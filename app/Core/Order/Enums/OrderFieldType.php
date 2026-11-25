<?php

declare(strict_types=1);

namespace App\Core\Order\Enums;

/**
 * 注文の項目の種類（ADR 0066）。店が注文の入力に足す欄の形
 */
enum OrderFieldType: string
{
    /** 1 行の文（200 文字まで） */
    case Text = 'text';
    /** 複数行の文（1,000 文字まで。改行できる） */
    case Textarea = 'textarea';
    /** 選ぶ（店が決めた選択肢の中から 1 つ） */
    case Select = 'select';
    /** チェック（はい／いいえ） */
    case Checkbox = 'checkbox';

    public const int TEXT_MAX = 200;

    public const int TEXTAREA_MAX = 1000;

    /**
     * お店の人に見せる言い方
     */
    public function label(): string
    {
        return match ($this) {
            self::Text => '1 行のテキスト',
            self::Textarea => '複数行のテキスト',
            self::Select => '選択式',
            self::Checkbox => 'チェックボックス',
        };
    }

    /**
     * 入れられる文字の数（文の種類だけ）
     */
    public function maxLength(): ?int
    {
        return match ($this) {
            self::Text => self::TEXT_MAX,
            self::Textarea => self::TEXTAREA_MAX,
            default => null,
        };
    }

    /**
     * 自由な文か（名前・住所・事情など、個人情報を含みうる）。管理画面では、見た記録の残る「個人情報」でだけ見せる
     */
    public function freeText(): bool
    {
        return $this === self::Text || $this === self::Textarea;
    }

    /**
     * 値の見せ方（チェックは「はい」「いいえ」、ほかは値のまま）
     */
    public function display(string $value): string
    {
        return $this === self::Checkbox ? ($value === '1' ? 'はい' : 'いいえ') : $value;
    }
}
