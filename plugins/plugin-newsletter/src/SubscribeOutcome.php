<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter;

/**
 * 受け取るを選んだ答え。ゲストには、送った・もう届けている・回数の上限のどれでも同じ答え（Sent）を返す
 * （メールのアドレスが購読しているかを、ほかの人が確かめられないように）。会員は自分のメールなので、そのまま伝える
 */
enum SubscribeOutcome
{
    /** ゲスト: 確かめのメールを送った（か、送らずに同じ答え） */
    case Sent;
    /** 会員: 届けるようにした（確かめは要らない。ログインで確かめたメール） */
    case Subscribed;
    /** 会員: もう届けている */
    case Already;
    /** 会員: 回数の上限（しばらくしてから） */
    case Throttled;
    /** 店の購読の数が上限 */
    case Full;
}
