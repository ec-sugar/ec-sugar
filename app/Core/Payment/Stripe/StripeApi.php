<?php

declare(strict_types=1);

namespace App\Core\Payment\Stripe;

use App\Core\IsolatedCopy;
use App\Core\Payment\PaymentUnavailableException;
use Illuminate\Support\Facades\Log;
use LogicException;
use Stripe\BaseStripeClient;
use Stripe\Exception\ApiErrorException;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

/**
 * Stripe の部品の呼び方をそろえる（支払いの画面・Connect のドライバで共通。ADR 0019・0022）。
 * 待つのは短く、送り直しは冪等キーに任せ、Stripe の警告は例外にせずログへ、送信先の差し替えは本番で止める。
 */
final class StripeApi
{
    public function client(string $secretKey): StripeClient
    {
        // 隔離した写し（ADR 0082）では支払いの代行へ送らない（店の鍵は店の DB にあり、写しでも開ける。Stripe の部品は Http の口を通らない）
        if (IsolatedCopy::enabled()) {
            throw new PaymentUnavailableException('この管理画面とショップは確認のための複製のため、決済代行（Stripe）へは送信しません。');
        }

        // 待つのは短く（お客さんや店主を長く待たせない）。送り直しは冪等キーがあるので安全
        CurlClient::instance()->setConnectTimeout(5);
        CurlClient::instance()->setTimeout(15);

        return new StripeClient(['api_key' => $secretKey, 'api_base' => self::apiBase(), 'max_network_retries' => 2]);
    }

    /**
     * 送信先は Stripe。差し替えはブラウザのテストの偽物のためだけで、本番では使えない（鍵を別の宛先へ送らない）
     */
    public static function apiBase(): string
    {
        $base = config('payment.stripe_api_base');

        if (! is_string($base) || $base === '') {
            return BaseStripeClient::DEFAULT_API_BASE;
        }

        if (app()->isProduction()) {
            throw new LogicException('本番では Stripe の送信先を差し替えられません（STRIPE_API_BASE を外してください）');
        }

        return $base;
    }

    /**
     * Stripe の部品は、答えに知らせがあると PHP の警告を出す（Laravel では例外になり、Stripe 側で作った後に落ちる）。警告はログに残して続ける。
     * Stripe の誤りは、見せてよい文だけの PaymentUnavailableException にする（ログは種類・コード・問い合わせ番号だけ。鍵や個人情報は入れない）
     *
     * @template T
     *
     * @param  callable(): T  $request
     * @return T
     */
    public function call(callable $request, string $failure, string $message = 'お支払いの画面を用意できませんでした。時間をおいて、もう一度お試しください。'): mixed
    {
        set_error_handler(static function (int $level, string $notice): bool {
            Log::warning('Stripe からの通知', ['notice' => $notice]);

            return true;
        }, E_USER_WARNING);

        try {
            return $request();
        } catch (ApiErrorException $e) {
            // 断られた理由の文（どの欄が違うか）も残す。こちらは個人情報・鍵を Stripe に渡していないので、Stripe の文にも含まれない。長い文は切る
            Log::warning($failure, ['error' => $e::class, 'code' => $e->getStripeCode(), 'param' => $e->getError()?->param, 'message' => mb_substr((string) $e->getError()?->message, 0, 300), 'status' => $e->getHttpStatus(), 'request' => $e->getRequestId()]);

            throw new PaymentUnavailableException($message, 0, $e);
        } finally {
            restore_error_handler();
        }
    }
}
