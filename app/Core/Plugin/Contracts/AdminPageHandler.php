<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Plugin\AdminPage\AdminActionRefused;
use App\Core\Plugin\AdminPage\AdminAttention;
use App\Core\Plugin\AdminPage\AdminColumn;
use App\Core\Plugin\AdminPage\AdminFilter;
use App\Core\Plugin\AdminPage\AdminRecord;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\AdminPage\AdminRows;

/**
 * App の管理画面の画面（一覧と 1 件。ADR 0051）。App は中身だけを渡し、核の部品が描く（App の画面のコードは読み込まない）。
 * 呼ばれるのは、この店で使っている時の店の文脈の中だけ。見るのは閲覧、act は運用の段階から（核の経路が確かめる）。
 * 名前・メール・住所・電話は返さない（お客さんはリンクで核の「お客さん」の画面へ。そこで記録を残して見る）。
 * お客さんが店に宛てて書いたもの（お問い合わせ）だけは、1 件の事実と表に個人情報の印（personal）を付けて返してよい（核が運用の段階の人にだけ出し、見たことを記録する）
 */
interface AdminPageHandler
{
    /** メニューと画面の見出し */
    public function title(): string;

    /**
     * 一覧の絞り込み（先頭が既定）
     *
     * @return list<AdminFilter>
     */
    public function filters(): array;

    /**
     * @return list<AdminColumn>
     */
    public function columns(): array;

    /**
     * ホームの「やること」に出す行（店の人が手を打つべきもの。3 つまで。件数が 0 の行は出ない）
     *
     * @return list<AdminAttention>
     */
    public function attention(): array;

    /**
     * 1 ページ分の行（$filter は filters() のどれか）
     */
    public function rows(string $filter, int $page, int $perPage): AdminRows;

    /**
     * 1 件の様子。無い・この App の記録でない ID は null
     */
    public function record(string $id): ?AdminRecord;

    /**
     * 操作を行う（核は、record() が今返した操作だけを渡す）。状態が変わって行えなければ AdminActionRefused、記録が無ければ AdminRecordNotFound
     *
     * @throws AdminActionRefused
     * @throws AdminRecordNotFound
     */
    public function act(string $id, string $action, string $actor): void;
}
