<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Sections\AppSection;
use App\Core\Plugin\Sections\ShopAppSections;
use App\Core\Section\SectionSchema;

/**
 * 店で使う App の区画（ADR 0040）。店の文脈の中だけで使う。
 *
 * - 店がその App を「使う」にしているときだけ、テーマの宣言に App の区画を足す（schema）。足した宣言では、決まった並びでないどのページにも置ける
 * - 止めた App の区画は宣言に入らないので、置けない（保存で断る）・ショップに出さない。しまってある値は消さない（ThemeContentService が残す）
 *
 * 値を覚えておかない（Laravel は controller を経路に持たせて使い回すので、覚えると次の要求で古い状態を使う）。
 * 1 回の描画の中で何度も読むときは now() の写し（ShopAppSections）を使う
 */
final readonly class AppSectionCatalog
{
    public function __construct(
        private PluginRegistry $registry,
        private PluginStates $states,
        private PluginSettings $settings,
    ) {}

    /**
     * 今の店の App の区画の写し（使う App の区画と、ショップに渡してよい設定）
     */
    public function now(): ShopAppSections
    {
        $all = $this->registry->sections();

        // 区画を持つ App が 1 つも入っていなければ、店の DB を読まない
        if ($all === []) {
            return new ShopAppSections([], []);
        }

        $states = $this->states->all();
        $enabled = array_filter($all, static fn (AppSection $s): bool => $states[$s->plugin]['enabled'] ?? false);
        $settings = [];

        if ($enabled !== []) {
            $stored = $this->settings->storedValues();

            foreach (array_unique(array_map(static fn (AppSection $s): string => $s->plugin, $enabled)) as $plugin) {
                $settings[$plugin] = $this->settings->publicValues($plugin, $stored[$plugin] ?? []);
            }
        }

        return new ShopAppSections($enabled, $settings);
    }

    /**
     * テーマの宣言に、店で使う App の区画を足したもの
     */
    public function schema(SectionSchema $schema): SectionSchema
    {
        return $this->now()->schema($schema);
    }
}
