<?php

declare(strict_types=1);

namespace App\Core\Shop;

/**
 * ショップの公開の状態。準備中は、パスワードを入れた人のほかは「準備中です」の画面だけを見る。
 * 新しく作った店は準備中（サンプルが入ったまま見えないように）。DB の列の既定も準備中
 */
enum ShopStatus: string
{
    case Preparing = 'preparing';
    case Open = 'open';

    public function label(): string
    {
        return match ($this) {
            self::Preparing => '準備中',
            self::Open => '公開',
        };
    }
}
