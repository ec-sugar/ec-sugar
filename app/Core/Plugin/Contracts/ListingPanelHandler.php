<?php

declare(strict_types=1);

namespace App\Core\Plugin\Contracts;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Manifest\SettingSpec;

/**
 * 管理画面の商品の画面に、App が出す欄（ADR 0050。定期便の「定期でも買える・周期・値引き」など）。
 * 欄の形は App の設定と同じ核の型（SettingSpec: 文・切り替え・選ぶ）だけ。画面は核の部品が描き、App の画面のコードは読み込まない。
 * 読み書きは App の表（店の DB）で、書くのは運用の段階から（核の経路が確かめる）
 */
interface ListingPanelHandler
{
    public const int NOTE_MAX = 300;

    /**
     * @return list<SettingSpec>
     */
    public function fields(): array;

    /**
     * @return array<string, bool|string|null>
     */
    public function read(Listing $listing): array;

    /**
     * 欄の上に出す短い案内（文字だけ。NOTE_MAX 文字まで。定期便の「この商品の定期便は N 件…」など）。無ければ null
     */
    public function note(Listing $listing): ?string;

    /**
     * 値を確かめて保存する。形が違えば Illuminate\Validation\ValidationException（欄の名前で）
     *
     * @param  array<string, mixed>  $values
     */
    public function write(Listing $listing, array $values, string $actor): void;
}
