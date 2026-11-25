<?php

declare(strict_types=1);

namespace App\Core\Plugin\OrderCharge;

use InvalidArgumentException;

/**
 * 注文の入力の App の料金の欄のまとまり（ADR 0078）。見出し・添える一言・欄（8 個まで）
 */
final readonly class ChargeForm
{
    public const int FIELDS_MAX = 8;

    /**
     * @param  list<ChargeField>  $fields
     */
    public function __construct(
        public string $title,
        public array $fields,
        public ?string $note = null,
    ) {
        if (! ChargeField::fits($title, 40) || ($note !== null && ! ChargeField::fits($note, 300))) {
            throw new InvalidArgumentException('料金の欄の見出しか一言の形が違います');
        }

        if ($fields === [] || count($fields) > self::FIELDS_MAX || count(array_unique($this->keys())) !== count($fields)) {
            throw new InvalidArgumentException('料金の欄は 1〜'.self::FIELDS_MAX.' 個で、鍵は重ねません');
        }
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (ChargeField $f): string => $f->key, $this->fields);
    }
}
