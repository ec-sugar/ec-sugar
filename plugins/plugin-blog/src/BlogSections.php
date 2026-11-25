<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog;

use App\Core\Plugin\Sections\AppSectionContent;
use App\Core\Plugin\Sections\AppSectionHandler;
use App\Core\Plugin\Sections\Embed;
use EcSugar\PluginBlog\Models\BlogCategory;
use EcSugar\PluginBlog\View\BlogReader;
use EcSugar\PluginBlog\View\NewsSection;

/**
 * 区画「新着のお知らせ」（app_blog。ADR 0040 の口と、ADR 0072 の並べる区画）。外から何も埋め込まない（embed は無い）。
 * 欄は見出し・出す数（3・5・10 件）・種類で絞る（種類の名前。空ならすべて）。並べるのはショップに出ている記事だけ（BlogReader）
 */
final class BlogSections implements AppSectionContent, AppSectionHandler
{
    private const array COUNTS = ['n3' => 3, 'n5' => 5, 'n10' => 10];

    public function problems(string $type, array $values): array
    {
        $name = is_string($values['category'] ?? null) ? trim($values['category']) : '';

        // 種類の名前は、今ある種類のどれか（打ち間違いで空の区画にしない）
        return $name === '' || BlogCategory::query()->where('name', $name)->exists()
            ? []
            : ['category' => '「'.$name.'」という種類はありません。お知らせ・ブログの記事の種類の名前を入れてください。'];
    }

    public function embed(string $type, array $values, array $settings): ?Embed
    {
        return null;
    }

    public function content(string $type, array $values): ?NewsSection
    {
        $count = self::COUNTS[is_string($values['count'] ?? null) ? $values['count'] : 'n3'] ?? 3;
        $name = is_string($values['category'] ?? null) && trim($values['category']) !== '' ? trim($values['category']) : null;
        $items = app(BlogReader::class)->latest($count, $name);

        return $items === [] ? null : new NewsSection($items, route('storefront.plugin.blog.pages.index', [], false));
    }
}
