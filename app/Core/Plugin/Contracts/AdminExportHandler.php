<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Plugin\AdminPage\AdminCsv;
use App\Core\Plugin\AdminPage\AdminField;
use Illuminate\Validation\ValidationException;

/**
 * App の画面から CSV を書き出す（「配送の CSV」で ADR 0051 の口に足した）。
 * App の画面の係（AdminPageHandler）が、これも実装したときだけ使う。欄（運送会社・どの注文か など）は核の形（AdminField）で、
 * 描くのも値の確かめも核（AdminForms）。CSV にするのも核（AdminFiles。式の無害化・文字コード・大きさ）。
 * 呼ばれるのは使っている店の文脈の中で、運用の段階から。書き出したこと（誰が・どの App・何件）は核が中央の監査ログに残す（中身は残さない）。
 * 届け先などの個人情報をファイルにするのは、この口だけにする（App の画面の一覧と 1 件には出さない）
 */
interface AdminExportHandler
{
    /** 書き出すボタンの文字（「CSV を書き出す」など。40 文字まで） */
    public function exportLabel(): string;

    /**
     * 書き出しの欄（並びのとおりに出す）
     *
     * @return list<AdminField>
     */
    public function exportFields(): array;

    /**
     * 書き出す中身。書き出すものが無い・欄をまたぐ決まりに合わなければ ValidationException（欄の名前で）
     *
     * @param  array<string, bool|string|null>  $values
     *
     * @throws ValidationException
     */
    public function export(array $values, string $actor): AdminCsv;
}
