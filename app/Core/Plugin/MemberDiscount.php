<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use InvalidArgumentException;

/**
 * 会員価格（ADR 0079）。App が会員ごとに渡す、商品の代金の定率の割引。対象は今買える商品の行の全部（オプションを含む。送料は含めない）。
 * key は見分けの印（ランクと率など。確かめの画面の指紋に入り、確かめた後にランクや率が変われば確定しない）、label はお客さんに見せる値引きの行の名前
 */
final readonly class MemberDiscount
{
    public const int MAX_PERCENT = 50;

    public function __construct(
        public string $key,
        public string $label,
        public int $percent,
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,39}\z/', $key) !== 1) {
            throw new InvalidArgumentException('会員価格の印の形が違う');
        }

        if ($label === '' || mb_strlen($label) > 40 || preg_match('/[\p{Cc}\p{Cf}]/u', $label) === 1) {
            throw new InvalidArgumentException('会員価格の名前は 40 文字までの 1 行');
        }

        if ($percent < 1 || $percent > self::MAX_PERCENT) {
            throw new InvalidArgumentException('会員価格の率は 1〜50%');
        }
    }
}
