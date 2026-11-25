<?php

declare(strict_types=1);

namespace App\Storefront\Catalog;

use App\Core\Money\Money;

/**
 * 商品の詳細に出すオプション（名入れ・ギフト包装など。テーマ API 2 の付け足し。ADR 0036）。在庫は持たない。
 * 額はここでは見せるためだけ。カートに入れるときは、核（AddonSelection）が今の定義と照らして額を決め直す。
 */
final readonly class ProductAddon
{
    public const string CHOICE = 'choice';

    public const string TEXT = 'text';

    public const string TOGGLE = 'toggle';

    /**
     * @param  string  $kind  choice（選ぶ）| text（入れる文字）| toggle（付ける）
     * @param  int  $amount  入れる文字・付けるの足す額（選ぶでは 0。額は選択肢ごと）
     * @param  list<array{label: string, amount: int}>  $choices  選ぶの選択肢（並び順）。ほかの形では空
     * @param  int|null  $maxLength  入れる文字の最大の文字数（ほかの形では null）
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $name,
        public bool $required,
        public int $amount,
        public array $choices,
        public ?int $maxLength,
        public string $currency,
    ) {}

    /**
     * フォームの欄の名前（addons[オプションの id]）
     */
    public function fieldName(): string
    {
        return "addons[{$this->id}]";
    }

    /**
     * 選ぶの選択肢の値（「label:名前」。番号は送らない。店が並べ替えても、見ていたものと違う選択肢にならないように）
     */
    public static function choiceValue(string $label): string
    {
        return 'label:'.$label;
    }

    /**
     * 足す額の文字（「+¥330」。金額は Money::format の形）。0 なら空
     */
    public function amountLabel(?int $amount = null): string
    {
        $amount ??= $this->amount;

        if ($amount <= 0) {
            return '';
        }

        return '+'.Money::format($amount, $this->currency);
    }

    /**
     * 欄の値（選ぶは「label:名前」・入れる文字・付けるは '1'）のときの足す額。空や合わない値は 0（選ぶは必須でも空の行から始める）
     */
    public function amountFor(?string $value): int
    {
        if ($value === null || trim($value) === '') {
            return 0;
        }

        return match ($this->kind) {
            self::CHOICE => array_values(array_filter($this->choices, static fn (array $choice): bool => self::choiceValue($choice['label']) === $value))[0]['amount'] ?? 0,
            self::TOGGLE => $value === '1' ? $this->amount : 0,
            default => $this->amount,
        };
    }

    /**
     * 核の部品（addon-fields）が data- の印（JSON）で渡す形。合計を出すためだけ（額はサーバーが決め直す）
     *
     * @return array{id: string, kind: string, amount: int, choices: list<array{value: string, amount: int}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'amount' => $this->amount,
            'choices' => array_map(static fn (array $choice): array => ['value' => self::choiceValue($choice['label']), 'amount' => $choice['amount']], $this->choices),
        ];
    }
}
