<?php

declare(strict_types=1);

namespace App\Core\Plugin\Sections;

use App\Core\Section\SectionSchema;
use App\Core\Section\SectionSpec;

/**
 * ある時点の、店で使う App の区画の写し（AppSectionCatalog::now。ADR 0040）。1 回の描画・1 回の要求の中で使い、持ち越さない
 */
final readonly class ShopAppSections
{
    /**
     * @param  array<string, AppSection>  $enabled  使う App の区画（種類 => 宣言）
     * @param  array<string, array<string, bool|string>|null>  $settings  App の名前 => ショップに渡してよい設定（入れるべき設定が欠けていれば null）
     */
    public function __construct(
        public array $enabled,
        private array $settings,
    ) {}

    public function section(string $type): ?AppSection
    {
        return $this->enabled[$type] ?? null;
    }

    /**
     * App の設定のうちショップに渡してよい値。入れるべき設定が欠けていれば null
     *
     * @return array<string, bool|string>|null
     */
    public function settings(string $plugin): ?array
    {
        return $this->settings[$plugin] ?? null;
    }

    /**
     * テーマの宣言に、使う App の区画を足したもの
     */
    public function schema(SectionSchema $schema): SectionSchema
    {
        if ($this->enabled === []) {
            return $schema;
        }

        return $schema->withApps(
            array_map(static fn (AppSection $s): SectionSpec => $s->spec, $this->enabled),
            array_map(static fn (AppSection $s): string => $s->title, $this->enabled),
        );
    }
}
