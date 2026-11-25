<?php

declare(strict_types=1);

namespace EcSugar\PluginRestock;

/**
 * 確かめ・取り消しのリンクの登録の様子（Pending・Waiting・Notified・Cancelled・Invalid）と、押した答え（Confirmed・CancelledNow。いま変えた時だけ）
 */
enum TokenOutcome
{
    /** 確かめ待ちで、期限の内（確かめられる） */
    case Pending;

    /** いま確かめた（入荷を待つ） */
    case Confirmed;

    /** もう確かめてある（入荷を待っている） */
    case Waiting;

    /** いま取り消した */
    case CancelledNow;

    /** もう取り消してある */
    case Cancelled;

    /** もう知らせた（登録は終わっている） */
    case Notified;

    /** 知らない・期限切れのリンク */
    case Invalid;
}
