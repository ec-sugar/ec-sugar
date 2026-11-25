<?php

declare(strict_types=1);

namespace EcSugar\PluginRestock;

/**
 * 再入荷のお知らせの登録の状態。pending（確かめのメール待ち）→ waiting（入荷を待つ）→ notified（知らせた。終わり）か cancelled（取り消した。終わり）
 */
enum RestockStatus: string
{
    case Pending = 'pending';
    case Waiting = 'waiting';
    case Notified = 'notified';
    case Cancelled = 'cancelled';

    /** 進行中（メールを持ち、1 人 1 種類に 1 つ） */
    public const array ACTIVE = ['pending', 'waiting'];
}
