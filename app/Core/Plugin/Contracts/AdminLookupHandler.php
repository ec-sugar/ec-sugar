<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Plugin\AdminPage\AdminActionRefused;

/**
 * App の画面の一覧の上に置く「読む・探す」の欄（チケットの受付で、QR を読む・番号や名前を入れる）。
 * 画面の係（AdminPageHandler）がこれも実装したときだけ出す。店の人が入れた文（QR の中身・番号など）を App が照らし、1 件の ID を返すと、
 * 画面はその 1 件へ移る。照らすのは運用の段階から（核の経路）。見つからない・使えないときは AdminActionRefused（文はそのまま店の人に見せる）
 */
interface AdminLookupHandler
{
    public const int INPUT_MAX = 200;

    /** 欄の名前（「チケットを読む」など） */
    public function lookupLabel(): string;

    /** 欄の下の短い案内 */
    public function lookupHint(): string;

    /** カメラで読む（QR コード）ボタンを出すか。ブラウザが読めない時は画面が出さない */
    public function lookupScan(): bool;

    /**
     * @return string 見つかった 1 件の ID（ULID）
     *
     * @throws AdminActionRefused
     */
    public function lookup(string $input, string $actor): string;
}
