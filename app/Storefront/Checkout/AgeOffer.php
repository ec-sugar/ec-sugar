<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use App\Core\Plugin\AgeCheck\AgeRule;
use Carbon\CarbonImmutable;

/**
 * 年齢の確かめの欄（ADR 0077）に渡す形（テーマ API の一部）。核の部品 <x-storefront::age-check> が見る。
 * 注文の入力の画面（CheckoutForm::ageOffer）と、カートを通さない App の申し込みの画面（定期便など）が同じ形で渡す。
 * 生年月日の欄は、入れ直した後も空で出す（生年月日をセッションに戻さない。誤りの理由だけを出す）
 */
final readonly class AgeOffer
{
    /**
     * @param  int  $age  聞く年齢（20 など）
     * @param  bool  $needsBirthdate  生年月日を聞くか（会員が前にこの年齢以上を確かめていれば聞かない）
     * @param  bool  $giftPossible  贈り物として送れる画面か（受け取る人の同意の欄を出す。贈り物を選んだ時だけ見せる）
     * @param  bool  $consent  同意の欄を選んでいるか（入れ直した値 → セッションの答え）
     * @param  bool  $recipient  受け取る人の同意の欄を選んでいるか
     * @param  array<string, string>  $errors  birthdate・consent・recipient の誤り
     */
    public function __construct(
        public int $age,
        public bool $needsBirthdate,
        public bool $giftPossible = false,
        public bool $consent = false,
        public bool $recipient = false,
        public array $errors = [],
    ) {}

    public function notice(): string
    {
        return AgeRule::noticeText($this->age);
    }

    public function consentText(): string
    {
        return AgeRule::consentText($this->age);
    }

    public function recipientText(): string
    {
        return AgeRule::recipientText($this->age);
    }

    public function error(string $key): ?string
    {
        return $this->errors[$key] ?? null;
    }

    public function hasError(): bool
    {
        return $this->errors !== [];
    }

    /** 生年月日の欄の選べる範囲（今日まで） */
    public function maxDate(): string
    {
        return AgeRule::today()->format('Y-m-d');
    }

    public function minDate(): string
    {
        return CarbonImmutable::create(AgeRule::today()->year - AgeRule::MAX_YEARS, 1, 1, 0, 0, 0, 'Asia/Tokyo')->format('Y-m-d');
    }
}
