<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 自由なページの予約語に privacy を足す（ADR 0094）。ショップの /privacy はプライバシーポリシーの画面になったので、
 * 自由なページ（/pages/{slug}）に同じ名前を作らせない（お店の人が「どちらが本物か」で迷わないよう、また /{slug} の短い住所に移せるよう）。
 *
 * 前の案内（自由なページで作ってメニューに載せる）に沿って privacy のページを作っていた店のために、制約を作り直す前に
 * - そのページの slug を privacy-page（使われていれば privacy-page-2 …）に替える（ゴミ箱のページも。中身・題・公開の状態はそのまま）
 * - ゴミ箱でないページは、古い URL /pages/privacy から新しい URL への転送（redirects、source auto。slug を変えた時に核が足すものと同じ形）を足し、
 *   /pages/privacy を行き先にしていた転送も新しい URL へ寄せる（転送は 1 段で終わる決まり。RedirectService::followMove と同じ）
 * 記録（domain_events）には残さない（店の人の操作ではない。前のデータの移行と同じ扱い）。
 *
 * 予約語の一覧はこの移行の中に書き写す（後でアプリの一覧が変わっても、この移行の意味を変えない。ShopPage::RESERVED_SLUGS と同じ並び）
 */
return new class extends Migration
{
    /** 前の一覧（2026_10_30_000600）に privacy を足したもの */
    private const array RESERVED = [
        'account', 'admin', 'api', 'apps', 'assets', 'build', 'cart', 'categories', 'category', 'checkout', 'consent',
        'favicon', 'home', 'index', 'legal', 'login', 'logout', 'media', 'new', 'orders', 'p', 'page', 'pages', 'password',
        'postal', 'preview', 'privacy', 'product', 'products', 'register', 'robots', 'search', 'sitemap', 'storage', 'up', 'webhooks',
    ];

    /** 前の一覧（down で戻す） */
    private const array PREVIOUS = [
        'account', 'admin', 'api', 'apps', 'assets', 'build', 'cart', 'categories', 'category', 'checkout', 'consent',
        'favicon', 'home', 'index', 'legal', 'login', 'logout', 'media', 'new', 'orders', 'p', 'page', 'pages', 'password',
        'postal', 'preview', 'product', 'products', 'register', 'robots', 'search', 'sitemap', 'storage', 'up', 'webhooks',
    ];

    /** 1 つの店で持てる転送の数（Redirect::MAX の写し） */
    private const int MAX_REDIRECTS = 1000;

    public function up(): void
    {
        foreach (DB::table('shop_pages')->where('slug', 'privacy')->orderBy('id')->get(['id', 'deleted_at']) as $page) {
            $slug = self::freeSlug();
            DB::table('shop_pages')->where('id', $page->id)->update(['slug' => $slug, 'updated_at' => now()]);

            if ($page->deleted_at === null) {
                self::redirect('/pages/privacy', "/pages/{$slug}");
            }
        }

        DB::statement('ALTER TABLE shop_pages DROP CONSTRAINT IF EXISTS shop_pages_slug_not_reserved');
        DB::statement("ALTER TABLE shop_pages ADD CONSTRAINT shop_pages_slug_not_reserved CHECK (slug NOT IN ('".implode("', '", self::RESERVED)."'))");
    }

    public function down(): void
    {
        // 替えた slug と足した転送は戻さない（前のアプリでも privacy-page のまま動く）
        DB::statement('ALTER TABLE shop_pages DROP CONSTRAINT IF EXISTS shop_pages_slug_not_reserved');
        DB::statement("ALTER TABLE shop_pages ADD CONSTRAINT shop_pages_slug_not_reserved CHECK (slug NOT IN ('".implode("', '", self::PREVIOUS)."'))");
    }

    /**
     * 店の中で使われていない slug（ゴミ箱のページも数える。戻した時に重ならないように）
     */
    private static function freeSlug(): string
    {
        for ($n = 1; ; $n++) {
            $slug = $n === 1 ? 'privacy-page' : "privacy-page-{$n}";

            if (! DB::table('shop_pages')->where('slug', $slug)->exists()) {
                return $slug;
            }
        }
    }

    private static function redirect(string $from, string $to): void
    {
        // 行き先は今ある画面。それを元にしていた転送は効かない（今ある画面を上書きしない）ので消す。古い URL を行き先にしていた転送は、新しい URL へ寄せる
        DB::table('redirects')->where('from_path', $to)->delete();
        DB::table('redirects')->where('to_path', $from)->update(['to_path' => $to, 'updated_at' => now()]);

        if (DB::table('redirects')->where('from_path', $from)->exists()) {
            DB::table('redirects')->where('from_path', $from)->update(['to_path' => $to, 'source' => 'auto', 'updated_at' => now()]);

            return;
        }

        if (DB::table('redirects')->count() < self::MAX_REDIRECTS) {
            DB::table('redirects')->insert(['id' => strtolower((string) Str::ulid()), 'from_path' => $from, 'to_path' => $to, 'source' => 'auto', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
};
