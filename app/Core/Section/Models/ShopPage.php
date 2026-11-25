<?php

declare(strict_types=1);

namespace App\Core\Section\Models;

use App\Core\Section\PageSpec;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 自由なページ（テナント DB。ADR 0039 区切り 3）。住所（slug）・題・メニューに載せるか・並び順・公開の状態。
 * 中身（区画の並び）はテーマの値（ThemeVersion）の中に page-{ID} の名前で持つ。変えるのは ShopPageService だけ。
 *
 * @property string $id
 * @property string $slug
 * @property string $title
 * @property bool $in_nav
 * @property int $position
 * @property string $status
 * @property string|null $seo_title 検索の結果に出す題（空ならページの題。ADR 0061）
 * @property string|null $seo_description 検索の結果に出す説明（空なら店の説明）
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class ShopPage extends Model
{
    use HasUlids;
    use SoftDeletes;

    public const string DRAFT = 'draft';

    public const string PUBLISHED = 'published';

    /** 1 つの店で作れるページの数（ゴミ箱のページも含める） */
    public const int MAX_PAGES = 50;

    /** slug の形（英小文字・数字・ハイフン。ハイフンは間だけ） */
    public const string SLUG = '/\A[a-z0-9]+(-[a-z0-9]+)*\z/';

    public const int SLUG_MAX = 60;

    public const int TITLE_MAX = 60;

    /**
     * 使えない slug（予約語）。今あるショップの経路の名前と、これから足しそうな名前。ページの住所は /pages/{slug} なので
     * 今の経路とはぶつからないが、/{slug} の短い住所に移せるよう、また店の人が経路と同じ名前で迷わないよう、先に取っておく。
     * DB の制約（shop_pages_slug_not_reserved）にも同じ一覧を書いている（足すときは新しいマイグレーションで作り直す。privacy は
     * プライバシーポリシーの /privacy を足した時に。2026_11_11_000200、ADR 0094）
     */
    public const array RESERVED_SLUGS = [
        'account', 'admin', 'api', 'apps', 'assets', 'build', 'cart', 'categories', 'category', 'checkout', 'consent',
        'favicon', 'home', 'index', 'legal', 'login', 'logout', 'media', 'new', 'orders', 'p', 'page', 'pages', 'password',
        'postal', 'preview', 'privacy', 'product', 'products', 'register', 'robots', 'search', 'sitemap', 'storage', 'up', 'webhooks',
    ];

    protected $table = 'shop_pages';

    protected $fillable = ['slug', 'title', 'in_nav', 'position', 'status', 'seo_title', 'seo_description'];

    /**
     * 中身の置き場の名前（テーマの値の中のページの名前）
     */
    public function pageName(): string
    {
        return PageSpec::freeName($this->id);
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'in_nav' => 'boolean',
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
