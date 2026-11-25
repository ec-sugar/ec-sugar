<?php

declare(strict_types=1);

namespace EcSugar\PluginLine;

use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Order\Services\SaleOrderService;
use App\Core\Party\Models\CustomerAccount;
use App\Core\RateLimit\Throttle;
use App\Core\Sample\Demo\DemoShop;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\Orders\OrderReader;
use App\Storefront\View\StorefrontUrls;
use Carbon\CarbonImmutable;
use EcSugar\PluginLine\Models\LineLink;
use EcSugar\PluginLine\Models\LineMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * 注文の知らせを LINE でも送る（Messaging API の push。ADR 0070）。メールは核が今までどおり送り、LINE は足すだけ。
 *
 * - 送る相手: その注文のお客さんの台帳が会員で、LINE をつなぎ、お店の公式アカウントと友だちの時だけ（ブロック・友だちでない人には送らない）
 * - 中身: 店の名前・注文番号・ご注文の状況のページ（マイページ）の URL と、発送なら運送会社と追跡番号だけ。住所・名前・金額の明細は載せない。
 *   URL に合い言葉や署名を入れない（LINE のトークに残るため。見るには会員のログインが要る）
 * - 同じ注文の同じ知らせは 1 回だけ（記録の行の一意）。送れなかったら記録して終わる（送り直さない。注文の流れは止めない）
 * - 回数の上限: 店ごとに 1 分 60 通・1 人に 1 時間 10 通。超えたら送らずに「上限で送らなかった」と記録する
 * - アクセストークン・宛先の ID・本文はログにも記録にも残さない（記録は種類・注文・結果の言葉だけ）
 */
final class LineNotifier
{
    public const string PUSH = 'https://api.line.me/v2/bot/message/push';

    public const int SHOP_PER_MINUTE = 60;

    public const int MEMBER_PER_HOUR = 10;

    public function __construct(
        private readonly LineSettings $settings,
        private readonly OrderReader $orders,
    ) {}

    public function send(string $kind, string $transactionId, ?string $fulfillmentId = null): void
    {
        $tenant = tenant();
        $token = $this->settings->accessToken();

        // デモの店（ADR 0086）では LINE へ送らない（使うにできないが、念のため送る口でも止める）
        if (! $tenant instanceof Tenant || DemoShop::active() || ! in_array($kind, ['order_paid', 'order_shipped'], true) || ! $this->settings->enabled() || ! $this->settings->wants($kind) || $token === null) {
            return;
        }

        $transaction = $this->orders->find($transactionId);
        $accountId = $transaction?->customer_account_id;

        if ($transaction === null || ! is_string($accountId)) {
            return;
        }

        $link = LineLink::query()->where('customer_account_id', $accountId)->where('friend', true)->first();
        $member = $link !== null && CustomerAccount::query()->whereKey($accountId)->whereNotNull('member_since')->whereNull('disabled_at')->exists();

        if ($link === null || ! $member) {
            return;
        }

        // 同じ注文の同じ知らせは 1 回だけ（一意の索引で。2 つの仕事が同時に来ても 1 つだけが行を作れる）
        $row = new LineMessage;
        $id = $row->newUniqueId();
        $now = CarbonImmutable::now();
        $created = LineMessage::query()->insertOrIgnore([
            'id' => $id,
            'dedupe_key' => $kind.'|'.$transaction->id.'|'.($fulfillmentId ?? ''),
            'kind' => $kind,
            'transaction_id' => $transaction->id,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($created === 0) {
            return;
        }

        $shop = (string) $tenant->getTenantKey();
        $keys = [["line-push|{$shop}|member|".hash('sha256', $link->id), self::MEMBER_PER_HOUR, 3600], ["line-push|{$shop}", self::SHOP_PER_MINUTE, 60]];

        // 先に数えてから比べる（会員ごと → 店ごとの順。同時に送っても上限を越えない）
        if (Throttle::firstOver($keys) !== null) {
            self::finish($id, 'skipped', 'limit');

            return;
        }

        $text = $this->text($kind, $tenant, $transaction, $fulfillmentId);

        try {
            $answer = Http::withToken($token)->acceptJson()->timeout(10)
                ->withHeaders(['X-Line-Retry-Key' => (string) Str::uuid()])
                ->post(self::PUSH, ['to' => $link->line_user, 'messages' => [['type' => 'text', 'text' => $text]]]);
        } catch (ConnectionException) {
            self::finish($id, 'failed', 'connection');

            return;
        } catch (Throwable $error) {
            // 中身（宛先・トークン）を含みうるので、種類だけを残す
            report(new RuntimeException('LINE の通知を送れなかった（'.$error::class.'）'));
            self::finish($id, 'failed', 'error');

            return;
        }

        if ($answer->successful()) {
            self::finish($id, 'sent', null);

            return;
        }

        self::finish($id, 'failed', match ($answer->status()) {
            401 => 'unauthorized',
            403 => 'forbidden',
            429 => 'too_many',
            default => 'http_'.$answer->status(),
        });
    }

    private static function finish(string $id, string $status, ?string $error): void
    {
        LineMessage::query()->whereKey($id)->where('status', 'pending')->update([
            'status' => $status,
            'error' => $error,
            'sent_at' => $status === 'sent' ? CarbonImmutable::now() : null,
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    private function text(string $kind, Tenant $tenant, Transaction $transaction, ?string $fulfillmentId): string
    {
        // 店の名前は 1 行に（改行・制御文字を取り除く）
        $shop = mb_substr(trim((string) preg_replace('/[\p{C}\s]+/u', ' ', (string) $tenant->getAttribute('name'))), 0, 60);
        $number = $this->orders->read($transaction)->number();
        $url = StorefrontUrls::of($tenant, '/account');
        $lines = [$kind === 'order_paid' ? "【{$shop}】ご注文を受け付けました。" : "【{$shop}】ご注文の品を発送しました。", "注文番号: {$number}"];

        if ($kind === 'order_shipped' && $fulfillmentId !== null) {
            $fulfillment = Fulfillment::query()->where('transaction_id', $transaction->id)->find($fulfillmentId);
            $carrier = $fulfillment?->carrier === null ? null : (SaleOrderService::CARRIERS[$fulfillment->carrier] ?? null);
            $tracking = $fulfillment?->tracking_number;
            $tracking = is_string($tracking) && preg_match('/\A[A-Za-z0-9-]{1,40}\z/', $tracking) === 1 ? $tracking : null;

            if ($carrier !== null || $tracking !== null) {
                $lines[] = implode(' / ', array_filter([$carrier === null ? null : "配送: {$carrier}", $tracking === null ? null : "追跡番号: {$tracking}"]));
            }
        }

        if ($url !== null) {
            $lines[] = 'ご注文の状況は、マイページでご覧いただけます。';
            $lines[] = $url;
        }

        return implode("\n", $lines);
    }
}
