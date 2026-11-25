<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Plugin\AdminPage\AdminField;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use Illuminate\Validation\ValidationException;

/**
 * App の画面で、店の人が記録を足す・直す（「よくある質問」で ADR 0051 の口に足した）。
 * App の画面の係（AdminPageHandler）が、これも実装したときだけ使う（定期便のように、お客さんが作る記録の画面は実装しない）。
 * 欄は核の形（AdminField）だけで、描くのも値の形の確かめも核（AdminForms）。核が確かめてから、全部の欄がそろった値を渡す。
 * 欄をまたぐ決まりと保存（連鎖のロック → 行のロック → イベント）は係が受け持つ。呼ばれるのは使っている店の文脈の中で、運用の段階から
 */
interface AdminFormHandler
{
    /**
     * 足すボタンの文字（「質問を足す」など。40 文字まで）。null は「直すだけ」で、足すボタンも足す経路も出さない
     * （お客さんが書いた記録に店の返信だけを書くなど。App「商品レビュー」、2026-11-04）
     */
    public function createLabel(): ?string;

    /**
     * 足す・直すの欄（同じ欄。並びのとおりに出す）
     *
     * @return list<AdminField>
     */
    public function fields(): array;

    /**
     * 直す画面の今の値（欄の名前 => 値）。無い・直せない記録は null
     *
     * @return array<string, bool|string|null>|null
     */
    public function values(string $id): ?array;

    /**
     * 足して、新しい記録の ID（ULID）を返す。欄をまたぐ決まりに合わなければ ValidationException（欄の名前で）。
     * createLabel() が null の係では核が呼ばない
     *
     * @param  array<string, bool|string|null>  $values
     *
     * @throws ValidationException
     */
    public function create(array $values, string $actor): string;

    /**
     * 直す。記録が無ければ AdminRecordNotFound、決まりに合わなければ ValidationException（欄の名前で）
     *
     * @param  array<string, bool|string|null>  $values
     *
     * @throws AdminRecordNotFound
     * @throws ValidationException
     */
    public function update(string $id, array $values, string $actor): void;
}
