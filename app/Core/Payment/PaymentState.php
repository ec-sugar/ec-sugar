<?php

declare(strict_types=1);

namespace App\Core\Payment;

use App\Core\Money\Money;
use App\Core\Payment\Services\DirectMethodSettings;
use Carbon\CarbonImmutable;

/**
 * お客さんと店の人に見せる支払いの様子（ADR 0058）。払われた手段か、入金待ちの手段と払いの期限。
 * 払込票の画面（instructionsUrl）は、注文を見てよい人の画面とメールにだけ出す。
 * 店が受け取る支払い（ADR 0074）: 銀行振込の入金待ちは振込先（bankAccount。注文の時の写し）と払う額、
 * 代金引換・予約の当日払いでまだ払われていない注文は onDelivery（受け渡しの時に払う。手段は method で見分ける）。振込先も注文を見てよい人の画面とメールにだけ出す
 */
final readonly class PaymentState
{
    /**
     * @param  array{bank: string, branch: string, type: string, number: string, holder: string}|null  $bankAccount
     */
    public function __construct(
        public ?string $method,
        public ?string $label,
        public bool $awaiting,
        public ?CarbonImmutable $payBy,
        public ?string $instructionsUrl,
        public ?array $bankAccount = null,
        public bool $onDelivery = false,
        public ?int $amount = null,
    ) {}

    /**
     * 払いの期限（日本時間。「2026/11/08 23:59」）
     */
    public function payByLabel(): ?string
    {
        return $this->payBy?->setTimezone('Asia/Tokyo')->format('Y/m/d H:i');
    }

    /**
     * 銀行振込の入金を待っている
     */
    public function awaitingTransfer(): bool
    {
        return $this->awaiting && $this->method === 'bank_transfer';
    }

    /**
     * 予約の当日払いで、まだ払われていない（来店の時にお店で払う）
     */
    public function payOnSite(): bool
    {
        return $this->onDelivery && $this->method === 'on_site';
    }

    /**
     * 振込先の 1 行ずつ（「銀行名 〇〇銀行」…）。銀行振込でなければ空
     *
     * @return list<array{label: string, value: string}>
     */
    public function bankLines(): array
    {
        $account = $this->bankAccount;

        if ($account === null) {
            return [];
        }

        return [
            ['label' => '銀行名', 'value' => $account['bank']],
            ['label' => '支店名', 'value' => $account['branch']],
            ['label' => '口座種別', 'value' => DirectMethodSettings::ACCOUNT_TYPES[$account['type']] ?? $account['type']],
            ['label' => '口座番号', 'value' => $account['number']],
            ['label' => '口座名義', 'value' => $account['holder']],
        ];
    }

    /**
     * 払う額（「¥5,400」）。分からなければ null
     */
    public function amountLabel(): ?string
    {
        return $this->amount === null ? null : Money::format($this->amount);
    }
}
