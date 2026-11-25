<?php

declare(strict_types=1);

namespace EcSugar\PluginContact;

/**
 * お問い合わせの状態。new（新着。まだ誰も手を付けていない）→ open（対応中。返信したか、店の人が対応中にした）→ closed（済み）。
 * 済みから対応中へ戻せる（お客さんから続きが来た時など）
 */
enum ContactStatus: string
{
    case New = 'new';
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => '新着',
            self::Open => '対応中',
            self::Closed => '済み',
        };
    }

    /**
     * 管理画面の状態の札の色
     */
    public function tone(): string
    {
        return match ($this) {
            self::New => 'warn',
            self::Open => 'good',
            self::Closed => 'muted',
        };
    }
}
