<?php

declare(strict_types=1);

namespace App\Core\Listing\Enums;

/**
 * オプションの形（ADR 0036）。在庫は持たない。
 */
enum AddonKind: string
{
    case Choice = 'choice';  // 選ぶ（選択肢ごとに足す額）
    case Text = 'text';      // 入れる文字（名入れなど。最大の文字数と足す額）
    case Toggle = 'toggle';  // 付ける／付けない（足す額）
}
