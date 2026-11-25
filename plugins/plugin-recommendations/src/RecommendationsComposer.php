<?php

declare(strict_types=1);

namespace EcSugar\PluginRecommendations;

use App\Storefront\View\SectionView;
use Illuminate\View\View;
use Throwable;

/**
 * おすすめの区画の部品に、並べる商品（$recommended。ListingCard の並び）を渡す（ADR 0071）。App の Blade は表示だけで DB に触らないので、
 * 商品はここで読む。App の部品（app-recommendations::sections.…）にも、テーマが描き直す同じ名前の部品（{テーマ}::sections.app_recommendations_…）にも渡す。
 * 編集の画面のプレビュー・試し見でも同じ商品（お客さんの Cookie が無ければ、関連商品は新着、最近見た商品は空）。読めなくてもページは止めない（空にする）
 */
final class RecommendationsComposer
{
    public function __construct(private readonly Recommendations $recommendations) {}

    public function compose(View $view): void
    {
        $section = $view->getData()['section'] ?? null;

        if (! $section instanceof SectionView || ! str_starts_with($section->key, 'app_recommendations_')) {
            return;
        }

        $count = Recommendations::count($section['count']);
        $request = request();

        try {
            $cards = match ($section->key) {
                'app_recommendations_related' => $this->recommendations->related($request, $count),
                'app_recommendations_recent' => $this->recommendations->recent($request, $count),
                'app_recommendations_ranking' => $this->recommendations->ranking($count),
                default => [],
            };
        } catch (Throwable $e) {
            report($e);
            $cards = [];
        }

        $view->with('recommended', $cards);
    }
}
