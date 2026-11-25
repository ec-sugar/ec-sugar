<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Plugin\AdminPage\AdminField;
use Illuminate\Validation\ValidationException;

/**
 * App の画面にファイル（CSV など）を取り込む（「配送の CSV」で ADR 0051 の口に足した）。
 * App の画面の係（AdminPageHandler）が、これも実装したときだけ使う。
 * 取り込みは「確かめの記録」を作るだけにして、状態を変えるのは、その 1 件の画面の操作（店の人が中身を見て押す）にする。
 * 核はファイルの大きさ（AdminFiles::IMPORT_MAX_BYTES まで）と空でないことを確かめてから、中身をそのまま（文字コードも変えずに）渡す。
 * 欄は核の形（1 行の文・何行かの文・選ぶ。ファイルと一緒に送るので切り替えは使えない）。呼ばれるのは使っている店の文脈の中で、運用の段階から
 */
interface AdminImportHandler
{
    /** 取り込むボタンの文字（「CSV を取り込む」など。40 文字まで） */
    public function importLabel(): string;

    /**
     * 取り込みの欄（運送会社など。並びのとおりに出す。無ければ空）
     *
     * @return list<AdminField>
     */
    public function importFields(): array;

    /**
     * ファイルを読み、確かめの記録を作って、その ID（ULID）を返す。読めない・決まりに合わなければ ValidationException（file か欄の名前で）
     *
     * @param  array<string, bool|string|null>  $values
     *
     * @throws ValidationException
     */
    public function import(string $content, array $values, string $actor): string;
}
