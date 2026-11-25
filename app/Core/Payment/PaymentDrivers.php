<?php

declare(strict_types=1);

namespace App\Core\Payment;

use App\Core\Payment\Contracts\PaymentDriver;
use App\Core\Payment\Contracts\PaymentWebhookReceiver;
use InvalidArgumentException;

/**
 * 決済代行のドライバの登録簿（ADR 0026・ADR 0107 の段 3）。
 * 核はここに登録された名前から 1 つを選ぶだけで、どの代行かを型で知らない。
 * 登録するのはドライバ自身のサービスプロバイダ（核に入っている Stripe も、外の App として入るドライバも同じ形）。
 *
 * 選ぶのは config('payment.driver')。登録が 1 つだけなら、設定が無くてもそれを使う
 */
final class PaymentDrivers
{
    /** @var array<string, array{driver: class-string<PaymentDriver>, webhook: class-string<PaymentWebhookReceiver>}> */
    private array $drivers = [];

    /**
     * @param  class-string<PaymentDriver>  $driver
     * @param  class-string<PaymentWebhookReceiver>  $webhook
     */
    public function register(string $name, string $driver, string $webhook): void
    {
        $this->drivers[$name] = ['driver' => $driver, 'webhook' => $webhook];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->drivers);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->drivers);
    }

    /**
     * 今の設定で使う名前。設定が無く、登録が 1 つだけならそれ。決められなければ例外
     */
    public function chosen(): string
    {
        $configured = config('payment.driver');

        if (is_string($configured) && $configured !== '') {
            if (! $this->has($configured)) {
                throw new InvalidArgumentException("決済代行のドライバ {$configured} は入っていません（入っているのは: ".(implode(', ', $this->names()) ?: 'なし').'）');
            }

            return $configured;
        }

        if (count($this->drivers) === 1) {
            return (string) array_key_first($this->drivers);
        }

        throw new InvalidArgumentException($this->drivers === []
            ? '決済代行のドライバが 1 つも入っていません'
            : 'どの決済代行を使うか config(payment.driver) で決めてください（入っているのは: '.implode(', ', $this->names()).'）');
    }

    /**
     * @return class-string<PaymentDriver>
     */
    public function driverClass(): string
    {
        return $this->drivers[$this->chosen()]['driver'];
    }

    /**
     * @return class-string<PaymentWebhookReceiver>
     */
    public function webhookClass(): string
    {
        return $this->drivers[$this->chosen()]['webhook'];
    }
}
