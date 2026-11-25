<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

/**
 * 1 件の画面で押せる操作（ADR 0051）。今の状態で押せるものだけを返す。
 * confirm は押す前に出す確かめの文。danger は取り消せない操作（解約など）の印。
 * repeatable は、くり返して押すのがふつうで、お客さんに何も知らせない操作（並べ替えの「上へ」など）の印。
 * 1 時間の回数の上限（AdminPages::ACTIONS_PER_HOUR）を数えない。
 * fields は、押す前に店の人が入れる欄（無効にする理由・返金するか など）。欄の形は足す・直すの欄と同じ核の形（AdminField）で、
 * 欄のある操作は、係が AdminActionInputHandler も実装しているときだけ使える（核が値を確かめてから actWith に渡す）。
 * money は、お金の記録に触れる操作の印（App「予約」の当日払いの受け取り・来店なしの取消 App「デジタルチケット」の返金 ）。
 * ポイントを付ける・引く操作（お金に近い。核の手の足し引きの口と同じ段階）にも付ける（App「友だち紹介」の確かめ待ちの「付ける」）。
 * 核は「お金」の段階の人にだけ出し、押す前にパスキーでの直前の再確認を求める（注文の取消・入金の確認の経路と同じ。ADR 0024）
 */
final readonly class AdminAction
{
    public function __construct(
        public string $key,
        public string $label,
        public string $confirm,
        public bool $danger = false,
        public bool $repeatable = false,
        /** @var list<AdminField> */
        public array $fields = [],
        public bool $money = false,
    ) {}
}
