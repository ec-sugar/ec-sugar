<?php

declare(strict_types=1);

namespace EcSugar\PluginContact;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\AdminPage\AdminAction;
use App\Core\Plugin\AdminPage\AdminAttention;
use App\Core\Plugin\AdminPage\AdminColumn;
use App\Core\Plugin\AdminPage\AdminFact;
use App\Core\Plugin\AdminPage\AdminField;
use App\Core\Plugin\AdminPage\AdminFilter;
use App\Core\Plugin\AdminPage\AdminRecord;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\AdminPage\AdminRow;
use App\Core\Plugin\AdminPage\AdminRows;
use App\Core\Plugin\AdminPage\AdminTable;
use App\Core\Plugin\AdminPage\CellType;
use App\Core\Plugin\AdminPage\FieldType;
use App\Core\Plugin\Contracts\AdminFormHandler;
use App\Core\Plugin\Contracts\AdminFormWording;
use App\Core\Plugin\Contracts\AdminPageHandler;
use App\Core\Plugin\Services\PluginSettings;
use EcSugar\PluginContact\Models\ContactInquiry;
use EcSugar\PluginContact\Models\ContactReply;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * 管理画面の「お問い合わせ」（ADR 0051 の App の画面と、返信を送る欄）。
 * 一覧（新着・対応中・済み・すべて。新しい順）は種類・付いた商品と注文・状態・日時だけで、名前・メール・本文は出さない。
 * 1 件の名前・メール・本文・送った返信は個人情報の印つき（核が運用の段階の人にだけ出し、見たことを監査ログに残す。閲覧の人には隠す）。
 * 操作は状態を変える（お客さんに知らせないので、くり返してよい操作）と、消す（取り消せないので直前の再確認の後）。
 * 返信は「直す」の欄の口を使い、欄の言葉を「返信する」にする（送ると、お客さんのメールへ送り、送った文を残す。欄は空に戻る）
 */
final class ContactAdminPage implements AdminFormHandler, AdminFormWording, AdminPageHandler
{
    private const array FILTERS = ['new' => '新着', 'open' => '対応中', 'closed' => '済み', 'all' => 'すべて'];

    /** 1 件の画面の表の行の上限（核の上限 200 の中） */
    private const int TABLE_ROWS = 200;

    public function title(): string
    {
        return 'お問い合わせ';
    }

    public function filters(): array
    {
        $counts = ContactInquiry::query()->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status')->all();
        $all = array_sum(array_map('intval', $counts));

        return array_map(static fn (string $key): AdminFilter => new AdminFilter($key, self::FILTERS[$key], $key === 'all' ? $all : (int) ($counts[$key] ?? 0)), array_keys(self::FILTERS));
    }

    public function attention(): array
    {
        return [new AdminAttention('new', '新しいお問い合わせ', 'お客さんからのお問い合わせです。読んで、お返事してください。', ContactInquiry::query()->where('status', ContactStatus::New->value)->count())];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('received', '受け付けた日時', CellType::DateTime),
            new AdminColumn('kind', '種類', CellType::Text),
            new AdminColumn('listing', '商品', CellType::Link),
            new AdminColumn('order', 'ご注文', CellType::Link),
            new AdminColumn('status', '状態', CellType::Status),
            new AdminColumn('replied', '最後の返信', CellType::DateTime),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $query = self::filtered($filter);
        $total = (clone $query)->count();
        $inquiries = $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $perPage)->get();
        $titles = self::titles($inquiries);

        return new AdminRows($inquiries->map(static fn (ContactInquiry $inquiry): AdminRow => new AdminRow($inquiry->id, [
            'received' => $inquiry->created_at,
            'kind' => $inquiry->kind->label(),
            'listing' => self::listingLink($inquiry, $titles),
            'order' => self::orderLink($inquiry),
            'status' => ['label' => $inquiry->status->label(), 'tone' => $inquiry->status->tone()],
            'replied' => $inquiry->last_replied_at,
        ]))->values()->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $inquiry = ContactInquiry::query()->find(strtolower($id));

        if ($inquiry === null) {
            return null;
        }

        $titles = self::titles(collect([$inquiry]));
        $replies = ContactReply::query()->where('inquiry_id', $inquiry->id)->orderBy('created_at')->orderBy('id')->get();
        $days = ContactInquiries::retentionDays(app(PluginSettings::class)->publicValues('contact')['retention'] ?? null);

        return new AdminRecord('お問い合わせ（'.$inquiry->kind->label().'）', [
            new AdminFact('状態', CellType::Status, ['label' => $inquiry->status->label(), 'tone' => $inquiry->status->tone()]),
            new AdminFact('受付番号', CellType::Text, $inquiry->number()),
            new AdminFact('種類', CellType::Text, $inquiry->kind->label()),
            new AdminFact('お名前', CellType::Text, self::cut($inquiry->name), personal: true),
            new AdminFact('メールアドレス', CellType::Text, self::cut($inquiry->email), copy: true, personal: true),
            new AdminFact('商品', CellType::Link, self::listingLink($inquiry, $titles)),
            new AdminFact('ご注文', CellType::Link, self::orderLink($inquiry)),
            // ログインした会員が送った時だけ（メールは会員のログインで確かめたもの。ゲストのメールからはお客さんをつながない〔他人のアドレスを書けるため〕）
            new AdminFact('お客さん', CellType::Link, $inquiry->customer_account_id === null ? null : ['label' => 'お客さんの画面', 'kind' => 'customer', 'id' => $inquiry->customer_account_id]),
            new AdminFact('受け付けた日時', CellType::DateTime, $inquiry->created_at),
            new AdminFact('最後に返信した日時', CellType::DateTime, $inquiry->last_replied_at),
            new AdminFact('済みにした日時', CellType::DateTime, $inquiry->closed_at),
            // 済んだお問い合わせは、設定の期間の後に消える（定期チェック）
            new AdminFact('自動で削除する日', CellType::Date, $inquiry->closed_at?->addDays($days)),
        ], [
            new AdminTable('お問い合わせの内容', [new AdminColumn('text', '内容', CellType::Text)], array_map(
                static fn (string $part): AdminRow => new AdminRow(null, ['text' => $part]),
                self::lines($inquiry->body),
            ), personal: true),
            new AdminTable('送った返信', [new AdminColumn('sent', '送った日時', CellType::DateTime), new AdminColumn('text', '返信', CellType::Text)], self::replyRows($replies), personal: true),
        ], self::actions($inquiry));
    }

    public function act(string $id, string $action, string $actor): void
    {
        $inquiries = app(ContactInquiries::class);

        match ($action) {
            'open' => $inquiries->changeStatus($id, [ContactStatus::New, ContactStatus::Closed], ContactStatus::Open, $actor),
            'close' => $inquiries->changeStatus($id, [ContactStatus::New, ContactStatus::Open], ContactStatus::Closed, $actor),
            'delete' => $inquiries->delete($id, $actor),
            default => throw new AdminRecordNotFound,
        };
    }

    /**
     * 足す欄は無い（お問い合わせを作るのはお客さんだけ）
     */
    public function createLabel(): ?string
    {
        return null;
    }

    public function fields(): array
    {
        return [
            new AdminField('reply', FieldType::Textarea, '返信', help: 'お客さんのメールアドレスへ、この文をメールで送ります。送った文は「送った返信」に残ります。HTML は使えません。', required: true, max: ContactInquiries::REPLY_MAX),
        ];
    }

    public function values(string $id): ?array
    {
        // 返信の欄はいつも空から（送った文は「送った返信」の表に残る）
        return ContactInquiry::query()->whereKey(strtolower($id))->exists() ? ['reply' => null] : null;
    }

    public function create(array $values, string $actor): string
    {
        throw new AdminRecordNotFound;
    }

    public function update(string $id, array $values, string $actor): void
    {
        $reply = $values['reply'] ?? null;

        if (! is_string($reply) || trim($reply) === '') {
            throw ValidationException::withMessages(['reply' => '返信の内容を入力してください。']);
        }

        app(ContactInquiries::class)->reply($id, $reply, $actor);
    }

    public function editTitle(): string
    {
        return '返信する';
    }

    public function editSubmit(): string
    {
        return 'メールで返信を送る';
    }

    public function editDone(): string
    {
        return '返信を送りました。';
    }

    /**
     * 今の状態で押せる操作
     *
     * @return list<AdminAction>
     */
    private static function actions(ContactInquiry $inquiry): array
    {
        $actions = [];

        if ($inquiry->status !== ContactStatus::Open) {
            $actions[] = new AdminAction('open', '対応中にする', $inquiry->status === ContactStatus::Closed ? '済みから対応中に戻します。' : '対応中にします。', repeatable: true);
        }

        if ($inquiry->status !== ContactStatus::Closed) {
            $actions[] = new AdminAction('close', '済みにする', '済みにします（お客さんには知らせません）。', repeatable: true);
        }

        $actions[] = new AdminAction('delete', '削除', 'このお問い合わせを、名前・メール・本文・送った返信ごと削除します。元に戻せません。', danger: true);

        return $actions;
    }

    /**
     * @return Builder<ContactInquiry>
     */
    private static function filtered(string $filter): Builder
    {
        $query = ContactInquiry::query();

        return isset(self::FILTERS[$filter]) && $filter !== 'all' ? $query->where('status', $filter) : $query;
    }

    /**
     * 商品の名前
     *
     * @param  Collection<int, ContactInquiry>  $inquiries
     * @return array<string, string>
     */
    private static function titles(Collection $inquiries): array
    {
        $ids = $inquiries->pluck('listing_id')->filter()->unique()->values()->all();

        /** @var array<string, string> */
        return $ids === [] ? [] : Listing::query()->whereKey($ids)->pluck('title', 'id')->all();
    }

    /**
     * @param  array<string, string>  $titles
     * @return array{label: string, kind: string, id: string}|null
     */
    private static function listingLink(ContactInquiry $inquiry, array $titles): ?array
    {
        return $inquiry->listing_id === null ? null : ['label' => self::cut($titles[$inquiry->listing_id] ?? '（削除した商品）'), 'kind' => 'listing', 'id' => $inquiry->listing_id];
    }

    /**
     * @return array{label: string, kind: string, id: string}|null
     */
    private static function orderLink(ContactInquiry $inquiry): ?array
    {
        return $inquiry->transaction_id === null ? null : ['label' => '注文を開く', 'kind' => 'transaction', 'id' => $inquiry->transaction_id];
    }

    /**
     * 本文を表の行に分ける（改行ごとの行、長い行は 200 文字ずつ。空の行は出さない）。行が多すぎるときは、改行を空白にして 200 文字ずつに
     *
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $rows = [];

        foreach (explode("\n", $text) as $line) {
            if (trim($line) !== '') {
                array_push($rows, ...mb_str_split($line, CellType::TEXT_MAX));
            }
        }

        return count($rows) <= self::TABLE_ROWS ? $rows : mb_str_split(str_replace("\n", ' ', $text), CellType::TEXT_MAX);
    }

    /**
     * 送った返信を表の行に（1 通の最初の行に送った日時。文は改行を空白にして 200 文字ずつ）
     *
     * @param  Collection<int, ContactReply>  $replies
     * @return list<AdminRow>
     */
    private static function replyRows(Collection $replies): array
    {
        $rows = [];

        foreach ($replies as $reply) {
            foreach (mb_str_split(str_replace("\n", ' ', $reply->body), CellType::TEXT_MAX) as $i => $part) {
                $rows[] = new AdminRow(null, ['sent' => $i === 0 ? $reply->sent_at : null, 'text' => $part]);
            }
        }

        return array_slice($rows, 0, self::TABLE_ROWS);
    }

    /**
     * 画面の文の上限（CellType::TEXT_MAX 文字）に収める
     */
    private static function cut(string $text): string
    {
        return mb_strlen($text) > CellType::TEXT_MAX ? mb_substr($text, 0, CellType::TEXT_MAX - 1).'…' : $text;
    }
}
