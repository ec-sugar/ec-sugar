<?php

declare(strict_types=1);

namespace App\Storefront\View;

use App\Models\Tenant;

/**
 * 店の情報（テーマ API の一部）。テナントの名前と、特定商取引法に基づく表記の項目（店の設定）と、
 * プライバシーポリシーのページの道筋（本文がある店だけ。ADR 0094）。
 * 見せ方（お知らせの帯・ヒーロー・安心の一行など）はテーマの区画（$sections。ADR 0028）。
 */
final readonly class ShopView
{
    /**
     * @param  list<array{label: string, value: string}>  $legal  特商法の表記の項目（未設定なら空）
     * @param  string|null  $privacyUrl  プライバシーポリシーのページ（/privacy）。本文が無い店では null（テーマはリンクを出さない）
     */
    public function __construct(
        public string $name,
        public array $legal,
        public ?string $privacyUrl = null,
    ) {}

    /**
     * @param  list<array{label: string, value: string}>  $legal  店の設定の表記（ShopSettingsService::legal）
     */
    public static function of(Tenant $tenant, array $legal = [], ?string $privacyUrl = null): self
    {
        return new self((string) $tenant->getAttribute('name'), $legal, $privacyUrl);
    }
}
