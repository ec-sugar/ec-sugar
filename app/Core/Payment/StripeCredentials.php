<?php

declare(strict_types=1);

namespace App\Core\Payment;

use App\Core\Payment\Contracts\PaymentCredentials;
use SensitiveParameter;

/**
 * 店の Stripe の鍵（復号した値）。決済代行のドライバにだけ渡す。文字や配列にしても鍵は出さない
 */
final readonly class StripeCredentials implements PaymentCredentials
{
    public function __construct(
        #[SensitiveParameter] public string $secretKey,
        #[SensitiveParameter] public string $webhookSecret,
        public string $mode,
        // つないだ店（Connect、ADR 0022）の連結アカウント。鍵は運営のプラットフォームの鍵
        public ?string $accountId = null,
        // 公開の前に試すテスト用の鍵（ふだんの鍵と別の行。ADR 0096）。この鍵で確かめた通知は Stripe に問い合わせ直す
        public bool $testKey = false,
    ) {}

    public function account(): ?string
    {
        return $this->accountId;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * @return array{mode: string, accountId: string|null, testKey: bool}
     */
    public function __debugInfo(): array
    {
        return ['mode' => $this->mode, 'accountId' => $this->accountId, 'testKey' => $this->testKey];
    }
}
