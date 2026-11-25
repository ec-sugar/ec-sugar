<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter;

/**
 * 確かめ・配信の停止のリンクの様子（押す前）と、押した答え（Confirmed・StoppedNow。いま変えた時だけ）
 */
enum TokenOutcome
{
    /** 確かめ待ちで、期限の内（確かめられる） */
    case Pending;
    /** いま確かめた（届けるようにした） */
    case Confirmed;
    /** 届けている（停止のリンクなら、止められる） */
    case Active;
    /** いま止めた */
    case StoppedNow;
    /** もう止めてある */
    case Stopped;
    /** 知らない・期限切れ・使い終わったリンク */
    case Invalid;
}
