<?php

declare(strict_types=1);

namespace EcSugar\PluginShopNotify;

use App\Core\Ledger\Enums\JournalType;
use App\Core\Ledger\Models\LedgerJournal;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Mail\MailHeaderText;
use App\Core\Money\Money;
use App\Core\Payment\Models\PaymentAttempt;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Sample\Demo\DemoShop;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use Carbon\CarbonImmutable;
use EcSugar\PluginShopNotify\Mail\ShopNoticeMail;
use EcSugar\PluginShopNotify\Models\ShopNotice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 店への知らせを作って送る（ADR 0060 の 2）。キューの仕事（SendShopNotice）から、店の文脈の中で呼ぶ。
 *
 * - 中身は注文番号・金額・品数・商品の名前・管理画面へのリンクだけ。お客さんの名前・住所・メール・電話は読まない・載せない
 * - 宛先は送る直前に設定の秘密の項目から読み、Slack は決まった宛先（hooks.slack.com）の形だけ（ShopNotifySettings）。転送（リダイレクト）は追わない
 * - 同じ知らせを同じ宛先へ 2 回送らない（記録の行を宛先ごとに作り、行のロックの中で「送った」にする。メールの知らせの SentMail と同じ形）
 * - 送れなかった時: 相手の答えが 5xx・429・つながらない・メールの送り手の誤りなら、記録を「送れなかった」にして true を返す（仕事がやり直す）。
 *   4xx（URL が古い・消した）は、やり直しても届かないので「送れなかった」のまま。どちらでも、注文などの店の操作は止めない
 */
final class ShopNotices
{
    public const array KINDS = ['paid', 'cancelled', 'low_stock', 'test'];

    private const int TIMEOUT = 5;

    public function __construct(
        private readonly PluginStates $states,
        private readonly ShopNotifySettings $settings,
    ) {}

    /**
     * 知らせる。やり直すと届くかもしれない失敗があれば true
     *
     * @param  list<'slack'|'mail'>|null  $only  試しの知らせで選んだ宛先（null は設定にある全部）
     */
    public function deliver(string $kind, ?string $subject, string $dedupeKey, ?array $only = null): bool
    {
        if (! in_array($kind, self::KINDS, true)) {
            return false;
        }

        // 送る前に使うのをやめた・切り替えを切った: 先に作った行（試しの知らせ）は「送らなかった」にする。
        // デモの店（ADR 0086）では外へ送らない（使うにできないが、念のため送る口でも止める）
        if (DemoShop::active() || ! $this->states->enabled(ShopNotifySettings::PLUGIN) || ! $this->settings->wants($kind)) {
            self::skip($kind, $dedupeKey, [], 'not_used');

            return false;
        }

        $channels = $this->settings->channels();

        if ($only !== null) {
            $channels = array_intersect_key($channels, array_flip($only));
        }

        // 先に作った行のうち、宛先が無くなったものは「送らなかった」にする
        self::skip($kind, $dedupeKey, array_keys($channels), 'not_configured');

        if ($channels === []) {
            return false;
        }

        $facts = $this->facts($kind, $subject);

        if ($facts === null) {
            return false;
        }

        $retry = false;

        foreach ($channels as $channel => $target) {
            $retry = $this->send($kind, $dedupeKey, $channel, $target, $facts) || $retry;
        }

        return $retry;
    }

    /**
     * まだ送っていない行のうち、宛先 $keep にないものを「送らなかった」にする
     *
     * @param  list<string>  $keep
     */
    private static function skip(string $kind, string $dedupeKey, array $keep, string $reason): void
    {
        ShopNotice::query()->where('kind', $kind)->where('dedupe_key', $dedupeKey)->where('status', 'pending')
            ->when($keep !== [], static fn ($q) => $q->whereNotIn('channel', $keep))
            ->update(['status' => 'skipped', 'error' => $reason, 'updated_at' => now()]);
    }

    /**
     * 取り消された注文を知らせるか: お支払いが確かめられていた（支払いの仕訳がある）注文か、受け付けた 0 円の注文か、
     * 銀行振込・代引きの注文（受け付けた時に「注文が入った」を知らせている。ADR 0074）だけ
     * （払われずに期限切れで消えた注文は、そもそも「注文が入った」を知らせていないので、取消も知らせない）
     */
    public function wasAccepted(string $transactionId): bool
    {
        $transaction = Transaction::query()->find($transactionId);

        if ($transaction === null || $transaction->kind !== 'sale') {
            return false;
        }

        return (int) $transaction->total_amount === 0
            || LedgerJournal::query()->where('transaction_id', $transactionId)->where('type', JournalType::Payment)->exists()
            || PaymentAttempt::direct($transactionId) !== null;
    }

    /**
     * 知らせの中身の材料（個人情報は入れない）。種類に合う相手が無ければ null
     *
     * @return array<string, int|string|null>|null
     */
    public function facts(string $kind, ?string $subject): ?array
    {
        $admin = rtrim((string) config('admin.url'), '/').'/tenants/'.rawurlencode((string) tenant()?->getTenantKey());

        if ($kind === 'test') {
            return ['link' => $admin];
        }

        if ($subject === null) {
            return null;
        }

        if ($kind === 'low_stock') {
            $variant = ListingVariant::query()->find($subject);
            $listing = $variant === null ? null : Listing::query()->find($variant->listing_id);

            if ($variant === null || $listing === null) {
                return null;
            }

            return [
                'listing_id' => $listing->id,
                'title' => self::cut($listing->title, 100),
                'variant' => $variant->label() === '' ? null : self::cut($variant->label(), 60),
                'available' => LowStock::available($variant->id),
                'threshold' => $this->settings->threshold(),
                'link' => "{$admin}/listings/{$listing->id}",
            ];
        }

        $transaction = Transaction::query()->find($subject);

        if ($transaction === null) {
            return null;
        }

        $direct = PaymentAttempt::direct($transaction->id);

        return [
            'transaction_id' => $transaction->id,
            'number' => strtoupper($transaction->id),
            'amount' => (int) $transaction->total_amount,
            'items' => (int) TransactionLine::query()->where('transaction_id', $transaction->id)->where('line_type', LineType::Item)->sum('quantity'),
            'link' => "{$admin}/transactions/{$transaction->id}",
        ]
            // 銀行振込の入金待ち（ADR 0074）。送る時に入金を確かめ終えていれば付けない
            + ($direct?->method === 'bank_transfer' && $direct->status === 'awaiting' ? ['awaiting_transfer' => 1] : []);
    }

    /**
     * 知らせの文（メールと Slack で同じ並び）。$slack のときは Slack の書式の特別な文字（& < >）を逃がし、リンクは Slack の形にする
     *
     * @param  array<string, mixed>  $facts
     * @return array{subject: string, lines: list<string>}
     */
    public static function render(string $kind, array $facts, string $shopName, bool $slack = false): array
    {
        $e = static fn (string $text): string => $slack ? str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text) : $text;
        $link = static fn (string $url, string $label): string => $slack ? '<'.$url.'|'.$label.'>' : "{$label}: {$url}";
        $amount = Money::format((int) ($facts['amount'] ?? 0));
        $items = (int) ($facts['items'] ?? 0);

        [$subject, $lines] = match ($kind) {
            'paid' => [
                match (true) {
                    (int) ($facts['amount'] ?? 0) === 0 => '注文を受け付けました（0 円）',
                    ($facts['awaiting_transfer'] ?? null) !== null => "注文が入りました（{$amount}・入金待ち）",
                    default => "注文が入りました（{$amount}）",
                },
                [
                    '注文番号: '.$e((string) ($facts['number'] ?? '')), "金額: {$amount}（{$items} 点）",
                    ...(($facts['awaiting_transfer'] ?? null) !== null ? ['お支払い: 銀行振込（入金待ち。振込を確認したら、注文の画面で「入金を確認した」を押してください）'] : []),
                    $link((string) ($facts['link'] ?? ''), '管理画面で注文を見る'),
                ],
            ],
            'cancelled' => [
                '注文が取り消されました',
                ['注文番号: '.$e((string) ($facts['number'] ?? '')), "金額: {$amount}（{$items} 点）", $link((string) ($facts['link'] ?? ''), '管理画面で注文を見る')],
            ],
            'low_stock' => [
                '在庫が少なくなりました',
                [
                    '商品: '.$e((string) ($facts['title'] ?? '')).(is_string($facts['variant'] ?? null) ? '（'.$e($facts['variant']).'）' : ''),
                    '注文できる在庫: '.(int) ($facts['available'] ?? 0).' 点（お知らせの基準: '.(int) ($facts['threshold'] ?? 0).' 点以下）',
                    $link((string) ($facts['link'] ?? ''), '管理画面で商品を見る'),
                ],
            ],
            default => [
                'テストのお知らせです',
                ['EC-SUGAR の App「店への知らせ」から、テストで送りました。このお知らせが届いていれば、設定は正しくできています。', $link((string) ($facts['link'] ?? ''), '管理画面を開く')],
            ],
        };

        $shop = MailHeaderText::line($shopName);

        return ['subject' => '['.$e($shop).'] '.$subject, 'lines' => $lines];
    }

    /**
     * 1 つの宛先へ送る。やり直すと届くかもしれない失敗なら true
     *
     * @param  array<string, int|string|null>  $facts
     */
    private function send(string $kind, string $dedupeKey, string $channel, string $target, array $facts): bool
    {
        $row = ShopNotice::query()->firstOrCreate(
            ['kind' => $kind, 'dedupe_key' => $dedupeKey, 'channel' => $channel],
            ['facts' => $facts, 'status' => 'pending'],
        );
        $shopName = (string) tenant()?->getAttribute('name');

        return DB::transaction(function () use ($row, $channel, $target, $shopName): bool {
            $locked = ShopNotice::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['sent', 'skipped'], true)) {
                return false;
            }

            [$error, $retry] = $channel === 'slack'
                ? self::postSlack($target, self::render($locked->kind, $locked->facts, $shopName, slack: true))
                : self::sendMail($target, $locked->kind, self::render($locked->kind, $locked->facts, $shopName));

            $locked->forceFill([
                'status' => $error === null ? 'sent' : 'failed',
                'error' => $error,
                'attempts' => $locked->attempts + 1,
                'sent_at' => $error === null ? CarbonImmutable::now() : null,
            ])->save();

            return $retry;
        });
    }

    /**
     * Slack の Incoming Webhook へ送る。答えの中身は残さない（決まった言葉だけ）
     *
     * @param  array{subject: string, lines: list<string>}  $message
     * @return array{0: ?string, 1: bool} 誤りの言葉（送れたら null）と、やり直すか
     */
    private static function postSlack(string $url, array $message): array
    {
        // 送る直前にもう一度、決まった宛先かを確かめる（ここを通らない宛先へは送らない）
        if (preg_match(ShopNotifySettings::SLACK_URL, $url) !== 1) {
            return ['bad_destination', false];
        }

        try {
            $response = Http::timeout(self::TIMEOUT)->connectTimeout(3)->withoutRedirecting()->acceptJson()
                ->post($url, ['text' => '*'.$message['subject']."*\n".implode("\n", $message['lines'])]);
        } catch (ConnectionException) {
            return ['connection', true];
        }

        if ($response->successful()) {
            return [null, false];
        }

        $status = $response->status();

        return ['http_'.$status, $status >= 500 || $status === 429];
    }

    /**
     * @param  array{subject: string, lines: list<string>}  $message
     * @return array{0: ?string, 1: bool}
     */
    private static function sendMail(string $to, string $kind, array $message): array
    {
        try {
            Mail::to($to)->send(new ShopNoticeMail($kind, $message['subject'], $message['lines']));
        } catch (Throwable $error) {
            report($error);

            return ['mail', true];
        }

        return [null, false];
    }

    private static function cut(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $text));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
