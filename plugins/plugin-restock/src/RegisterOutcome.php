<?php

declare(strict_types=1);

namespace EcSugar\PluginRestock;

/**
 * 登録を受けた答え。ゲストには、送った・もう登録がある・回数の上限・メールごとの上限のどれでも同じ答え（Sent）を返す
 * （メールのアドレスが登録されているかを、ほかの人が確かめられないように）。会員は自分のメールなので、そのまま伝える
 */
enum RegisterOutcome
{
    /** ゲスト: 確かめのメールを送った（か、送らずに同じ答え） */
    case Sent;

    /** 会員: 登録した（確かめは要らない。登録の知らせと取り消しのリンクをメールで） */
    case Registered;

    /** 会員: もう登録してある */
    case Already;

    /** その種類はいまカートに入れられる（売り切れでない） */
    case Available;

    /** その商品の種類でない・ショップに出ていない */
    case Unavailable;

    /** 会員: 回数の上限（しばらくしてから） */
    case Throttled;

    /** 店の登録の数か、会員のメールの登録の数が上限 */
    case Full;
}
