<?php

declare(strict_types=1);

namespace App\Core\Plugin\OrderCharge;

use InvalidArgumentException;

/**
 * App の料金の欄 1 つ（ADR 0078）。種類は「選ぶ」（select）と「1 行の文」（text）だけ。描くのは核の部品 <x-storefront::charge-fields> で、文字のまま出す。
 * 選ぶの選択肢は 値 => 画面の名前（値が空の選択肢は「選ばない」）。文は maxLength 文字まで
 */
final readonly class ChargeField
{
    public const int OPTIONS_MAX = 30;

    /**
     * @param  string  $type  select か text
     * @param  array<string, string>  $options
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $label,
        public ?string $help = null,
        public array $options = [],
        public int $maxLength = 0,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $key) !== 1 || ! in_array($type, ['select', 'text'], true)) {
            throw new InvalidArgumentException("料金の欄の鍵か種類が違います: {$key}");
        }

        if (! self::fits($label, 40) || ($help !== null && ! self::fits($help, 200))) {
            throw new InvalidArgumentException("料金の欄 {$key} の名前か説明の形が違います");
        }

        if ($type === 'select') {
            if ($options === [] || count($options) > self::OPTIONS_MAX) {
                throw new InvalidArgumentException("料金の欄 {$key} の選択肢は 1〜".self::OPTIONS_MAX.' 個です');
            }

            foreach ($options as $value => $name) {
                if (mb_strlen((string) $value) > 64 || preg_match('/[<>]|\p{C}/u', (string) $value) === 1 || ! self::fits($name, 80)) {
                    throw new InvalidArgumentException("料金の欄 {$key} の選択肢の形が違います");
                }
            }
        } elseif ($maxLength < 1 || $maxLength > 200) {
            throw new InvalidArgumentException("料金の欄 {$key} の文の長さは 1〜200 文字です");
        }
    }

    /**
     * 画面に出す文として使えるか（空でない・長さの中・制御文字が無い）
     */
    public static function fits(mixed $text, int $max): bool
    {
        return is_string($text) && trim($text) !== '' && mb_check_encoding($text, 'UTF-8') && mb_strlen($text) <= $max && preg_match('/\p{C}/u', $text) !== 1;
    }
}
