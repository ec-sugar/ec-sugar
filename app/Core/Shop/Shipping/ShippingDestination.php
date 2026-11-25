<?php

declare(strict_types=1);

namespace App\Core\Shop\Shipping;

use InvalidArgumentException;

/**
 * 送料を決めるお届け先（ADR 0064）。都道府県が決まっていればその額、贈り物（お届け先は受け取る人が後で入れる）はいちばん高い額、
 * カート（住所をまだ知らない）は目安（いちばん安い地域〜高い地域）
 */
final readonly class ShippingDestination
{
    private function __construct(
        public ?string $prefecture,
        public bool $highest,
    ) {}

    public static function prefecture(string $prefecture): self
    {
        if (! Regions::known($prefecture)) {
            throw new InvalidArgumentException('知らない都道府県');
        }

        return new self($prefecture, false);
    }

    /**
     * 贈り物: 選べるお届け先の中でいちばん高い額（ADR 0055 の 7）
     */
    public static function highest(): self
    {
        return new self(null, true);
    }

    /**
     * カート: お届け先をまだ知らない（目安を出す）
     */
    public static function estimate(): self
    {
        return new self(null, false);
    }

    public function isEstimate(): bool
    {
        return $this->prefecture === null && ! $this->highest;
    }
}
