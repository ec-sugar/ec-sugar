<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter;

use App\Core\Mail\SenderFacts;
use App\Core\Plugin\AdminPage\AdminAction;
use App\Core\Plugin\AdminPage\AdminActionRefused;
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
use App\Core\Plugin\Contracts\AdminPageHandler;
use Carbon\CarbonImmutable;
use EcSugar\PluginNewsletter\Models\NewsletterCampaign;
use EcSugar\PluginNewsletter\Models\NewsletterSubscriber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 管理画面の「メルマガ・一斉メール」（ADR 0051 の App の画面と、足す・直すの欄）。記録は配信（題と本文）。
 * 一覧（下書き・予約と配信中・配信済み・すべて）・1 件（数と送り主の表示）・書く・直す（下書きだけ）・試しに自分へ送る・今すぐ配信する・予約する
 * （どちらも取り消せないので直前の再確認の後）・予約をやめる・配信を止める・消す（下書きだけ）。
 * 購読者のメールのアドレスは出さない（数だけ。書き出しも無い）。見るのは閲覧、書く・送るのは運用の段階から（核が確かめる）
 */
final class NewsletterAdminPage implements AdminFormHandler, AdminPageHandler
{
    private const array FILTERS = ['all' => 'すべて', 'draft' => '下書き', 'active' => '予約・配信中', 'done' => '配信済み'];

    public function title(): string
    {
        return 'メルマガ・一斉メール';
    }

    public function filters(): array
    {
        return array_map(static fn (string $key): AdminFilter => new AdminFilter($key, self::FILTERS[$key], self::filtered($key)->count()), array_keys(self::FILTERS));
    }

    public function attention(): array
    {
        $now = CarbonImmutable::now();
        $out = [];
        $missed = NewsletterCampaign::query()->where('status', CampaignStatus::Draft->value)->whereNotNull('scheduled_at')->where('scheduled_at', '<', $now)->count();
        $out[] = new AdminAttention('draft', '予約の日時を過ぎた下書き', '予約の日時に、送り主の表示（特商法の表記）が足りなかったため、下書きに戻した配信です。特商法の表記を入力してから、日時を直してもう一度予約してください。', $missed, 'warn');

        if (SenderFacts::current()->missing() !== []) {
            $waiting = NewsletterCampaign::query()->whereIn('status', [CampaignStatus::Scheduled->value, CampaignStatus::Sending->value])->count();
            $out[] = new AdminAttention('active', '送り主の表示が足りない配信', '設定の「特商法の表記」に「販売業者」「所在地」「電話番号かメールアドレス」がそろうまで、配信のメールは送られません。', $waiting, 'bad');
        }

        return $out;
    }

    public function columns(): array
    {
        return [
            new AdminColumn('subject', '件名', CellType::Text),
            new AdminColumn('status', '状態', CellType::Status),
            new AdminColumn('when', '配信の日時', CellType::DateTime),
            new AdminColumn('sent', '送った数', CellType::Number),
            new AdminColumn('failed', '失敗', CellType::Number),
            new AdminColumn('unsubscribed', '配信を停止した人', CellType::Number),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $query = self::filtered($filter);
        $total = (clone $query)->count();
        $campaigns = $query->orderByDesc('updated_at')->orderByDesc('id')->forPage($page, $perPage)->get();

        return new AdminRows($campaigns->map(static function (NewsletterCampaign $c): AdminRow {
            $counts = NewsletterCampaigns::counts($c->id);

            return new AdminRow($c->id, [
                'subject' => self::cut($c->subject),
                'status' => ['label' => $c->status->label(), 'tone' => $c->status->tone()],
                'when' => $c->started_at ?? $c->scheduled_at,
                'sent' => $counts['sent'],
                'failed' => $counts['failed'],
                'unsubscribed' => $counts['unsubscribed'],
            ]);
        })->values()->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $c = NewsletterCampaign::query()->find(strtolower($id));

        if ($c === null) {
            return null;
        }

        $counts = NewsletterCampaigns::counts($c->id);
        $sender = SenderFacts::current();
        $missing = $sender->missing();
        $before = in_array($c->status, [CampaignStatus::Draft, CampaignStatus::Scheduled], true);

        $facts = [
            new AdminFact('状態', CellType::Status, ['label' => $c->status->label(), 'tone' => $c->status->tone()]),
            new AdminFact('件名', CellType::Text, self::cut($c->subject)),
            new AdminFact('予約の日時', CellType::DateTime, $c->scheduled_at),
            new AdminFact('配信を始めた日時', CellType::DateTime, $c->started_at),
            new AdminFact('配信を終えた日時', CellType::DateTime, $c->finished_at),
        ];

        if ($before) {
            $facts[] = new AdminFact('配信先の人数（登録が完了した人）', CellType::Number, NewsletterCampaigns::recipients());
            $facts[] = new AdminFact('登録の確認待ち（確認メールのリンクをまだ押していない人）', CellType::Number, NewsletterSubscriber::query()->where('status', SubscriberStatus::Pending->value)->where('expires_at', '>', CarbonImmutable::now())->count());
        } else {
            $facts[] = new AdminFact('送った数', CellType::Number, $counts['sent']);
            $facts[] = new AdminFact('送信中の数', CellType::Number, $counts['waiting']);
            $facts[] = new AdminFact('失敗', CellType::Number, $counts['failed']);
            $facts[] = new AdminFact('送らなかった数（途中で止めた配信の残り・配信停止を選んだ人）', CellType::Number, $counts['skipped']);
            $facts[] = new AdminFact('このメールから配信を停止した人', CellType::Number, $counts['unsubscribed']);
        }

        $facts[] = new AdminFact('送り主の表示（特商法の表記から）', CellType::Status, $missing === []
            ? ['label' => 'そろっています', 'tone' => 'good']
            : ['label' => self::cut('足りない: '.implode('・', $missing)), 'tone' => 'bad']);
        $facts[] = new AdminFact('送り主', CellType::Text, $sender->name === '' ? null : self::cut($sender->name));

        $body = new AdminTable('本文', [new AdminColumn('text', '本文', CellType::Text)], array_map(
            static fn (string $part): AdminRow => new AdminRow(null, ['text' => $part]),
            array_slice(mb_str_split(str_replace("\n", ' ↵ ', $c->body), CellType::TEXT_MAX), 0, 200),
        ));

        return new AdminRecord(self::cut($c->subject), $facts, [$body], self::actions($c));
    }

    public function act(string $id, string $action, string $actor): void
    {
        $id = strtolower($id);
        $campaigns = app(NewsletterCampaigns::class);

        match ($action) {
            'test' => $campaigns->test($id, self::operatorEmail($actor), $actor),
            'send_now' => $campaigns->sendNow($id, $actor),
            'schedule' => $campaigns->schedule($id, $actor),
            'unschedule' => $campaigns->unschedule($id, $actor),
            'stop' => $campaigns->stop($id, $actor),
            'delete' => $campaigns->delete($id, $actor),
            default => throw new AdminRecordNotFound,
        };
    }

    public function createLabel(): string
    {
        return '配信を書く';
    }

    public function fields(): array
    {
        return [
            new AdminField('subject', FieldType::Text, '件名', help: 'メールの件名です。', required: true, max: NewsletterCampaigns::SUBJECT_MAX),
            new AdminField('body', FieldType::Textarea, '本文', help: 'テキストだけのメールです（HTML は使えず、タグも文字のまま届きます）。改行はそのまま届きます。送り主の名前・住所・問い合わせ先と配信停止のリンクは、最後に自動で付きます。', required: true, max: NewsletterCampaigns::BODY_MAX),
            new AdminField('scheduled_at', FieldType::Text, '予約の日時（予約するときだけ）', help: '「2026-11-20 09:00」の形（日本の時刻）。入れてから「予約する」を押します。空なら「今すぐ配信する」で送ります。', max: 16),
        ];
    }

    public function values(string $id): ?array
    {
        $c = NewsletterCampaign::query()->find(strtolower($id));

        return $c === null || $c->status !== CampaignStatus::Draft ? null : [
            'subject' => $c->subject,
            'body' => $c->body,
            'scheduled_at' => $c->scheduled_at?->setTimezone(NewsletterCampaigns::TIMEZONE)->format(NewsletterCampaigns::TIME_FORMAT),
        ];
    }

    public function create(array $values, string $actor): string
    {
        return app(NewsletterCampaigns::class)->create($values, $actor);
    }

    public function update(string $id, array $values, string $actor): void
    {
        app(NewsletterCampaigns::class)->update(strtolower($id), $values, $actor);
    }

    /**
     * 今の状態で押せる操作
     *
     * @return list<AdminAction>
     */
    private static function actions(NewsletterCampaign $c): array
    {
        return match ($c->status) {
            CampaignStatus::Draft => array_values(array_filter([
                new AdminAction('test', '自分あてにテスト送信', 'この配信を、あなたのメールアドレスへ 1 通だけ送ります（件名の頭に【テスト】が付きます）。'),
                new AdminAction('send_now', '今すぐ配信する', '登録している '.NewsletterCampaigns::recipients().' 人へ配信を始めます。送ったメールは取り消せません。', danger: true),
                $c->scheduled_at === null ? null : new AdminAction('schedule', '予約する', $c->scheduled_at->setTimezone(NewsletterCampaigns::TIMEZONE)->format('Y/m/d H:i').' に配信するよう予約します（その時点で登録している人へ送ります）。', danger: true),
                new AdminAction('delete', '消す', 'この下書きを消します。消すと元に戻せません。', danger: true),
            ])),
            CampaignStatus::Scheduled => [new AdminAction('unschedule', '予約をやめる', '予約をやめて下書きに戻します（まだ 1 通も送っていません）。')],
            CampaignStatus::Sending => [new AdminAction('stop', '配信を止める', 'まだ送っていない人へは送りません。送ったメールは取り消せません。止めた配信は再開できません。', danger: true)],
            default => [],
        };
    }

    /**
     * 試しのメールの宛先: 操作した店の人（運営のアカウント）のメール。actor と今のログインが同じ人の時だけ
     */
    private static function operatorEmail(string $actor): string
    {
        $user = auth('operator')->user();

        if (! $user instanceof Model || $actor !== 'operator:'.$user->getKey()) {
            throw new AdminActionRefused('テスト送信は、ログインしているあなたのメールアドレスへ送ります。ログインし直してください。');
        }

        return (string) $user->getAttribute('email');
    }

    /**
     * @return Builder<NewsletterCampaign>
     */
    private static function filtered(string $filter): Builder
    {
        $query = NewsletterCampaign::query();

        return match ($filter) {
            'draft' => $query->where('status', CampaignStatus::Draft->value),
            'active' => $query->whereIn('status', [CampaignStatus::Scheduled->value, CampaignStatus::Sending->value]),
            'done' => $query->whereIn('status', [CampaignStatus::Sent->value, CampaignStatus::Stopped->value]),
            default => $query,
        };
    }

    private static function cut(string $text): string
    {
        $text = str_replace("\n", ' ', $text);

        return mb_strlen($text) > CellType::TEXT_MAX ? mb_substr($text, 0, CellType::TEXT_MAX - 1).'…' : $text;
    }
}
