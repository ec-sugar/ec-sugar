<?php

declare(strict_types=1);

namespace App\Core\Payment;

/**
 * 支払いの画面に出す手段（ADR 0058）。カードはいつも入る（Apple Pay・Google Pay はカードの中で、Stripe の画面が出す）。
 * 頼む前に記録し（「card,konbini:3」の形）、答えが届かなかったやり直しも同じ中身で頼む
 */
final readonly class CheckoutMethods
{
    /** 核が扱える手段。後から払う手段（入金待ちのあるもの）は konbini だけ */
    public const array KNOWN = ['card', 'konbini', 'paypay'];

    /**
     * 支払いの画面でいつも外す手段の名前（Stripe の excluded_payment_method_types。2026-09-30 の API から payment_method_types を渡せなくなり、
     * 手段はダッシュボードで有効にしたものが出る）。核が扱える手段（KNOWN）のうちこの注文で使わないものと、日本の店のダッシュボードで足せるが
     * 核が扱えない手段（Stripe の銀行振込・Alipay・WeChat Pay。入金待ちや返金の形が違う）を外す。Apple Pay・Google Pay・Link はカードの中で、
     * この一覧に入れると Stripe が断る
     */
    public const array EXCLUDABLE = ['konbini', 'paypay', 'customer_balance', 'alipay', 'wechat_pay'];

    /** 後から払う手段（支払いの画面の後、お客さんが払うまで入金待ち） */
    public const array ASYNC = ['konbini'];

    /** 手段ごとの 1 回の額の範囲（円。Stripe の文書、2026-11-05 に確かめた）。外れる注文には出さない */
    public const array LIMITS = ['konbini' => [120, 300_000], 'paypay' => [50, 1_000_000]];

    /** お客さんと店の人に見せる名前（払われた手段。カードの中の Apple Pay・Google Pay も分ける） */
    public const array LABELS = [
        'card' => 'クレジットカード',
        'apple_pay' => 'Apple Pay',
        'google_pay' => 'Google Pay',
        'konbini' => 'コンビニ払い',
        'paypay' => 'PayPay',
        // 店が受け取る支払い（Stripe を通さない。ADR 0074）
        'bank_transfer' => '銀行振込',
        'cod' => '代金引換',
        // 予約の当日払い（ADR 0056）
        'on_site' => '当日払い',
        'other' => 'そのほかの方法',
    ];

    /**
     * @param  list<string>  $types  出してよい手段（card が先頭。Stripe へは外す手段 excluded() で渡す）
     * @param  int  $konbiniDays  コンビニ払いの払いの期限（何日後の 23:59 まで）
     */
    public function __construct(
        public array $types = ['card'],
        public int $konbiniDays = 3,
    ) {}

    public static function cardOnly(): self
    {
        return new self;
    }

    /**
     * 記録した形から戻す。知らない手段は落とし、カードは必ず入れる（記録が壊れていても、カードだけの画面になる）
     */
    public static function fromString(?string $value): self
    {
        $types = ['card'];
        $days = 3;

        foreach (explode(',', (string) $value) as $part) {
            [$type, $option] = array_pad(explode(':', $part, 2), 2, null);

            if (! in_array($type, self::KNOWN, true) || in_array($type, $types, true)) {
                continue;
            }

            $types[] = $type;

            if ($type === 'konbini' && is_string($option) && ctype_digit($option)) {
                $days = max(1, min(30, (int) $option));
            }
        }

        return new self($types, $days);
    }

    public function toString(): string
    {
        return implode(',', array_map(fn (string $type): string => $type === 'konbini' ? "konbini:{$this->konbiniDays}" : $type, $this->types));
    }

    /**
     * Stripe に外してもらう手段（EXCLUDABLE のうち、この注文で出さないもの）
     *
     * @return list<string>
     */
    public function excluded(): array
    {
        return array_values(array_diff(self::EXCLUDABLE, $this->types));
    }

    public function has(string $type): bool
    {
        return in_array($type, $this->types, true);
    }

    /**
     * 後から払う手段を含むか（支払いの画面が終わっても払われていないことがある）
     */
    public function includesAsync(): bool
    {
        return array_intersect($this->types, self::ASYNC) !== [];
    }

    public static function label(?string $method): ?string
    {
        return $method === null ? null : (self::LABELS[$method] ?? self::LABELS['other']);
    }
}
