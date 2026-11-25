<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * ショップの動き。テーマが theme.json で宣言した強さが外枠の data-motion に出て、核の動きの部品がそれを読む。
 * 商品の札と最初の見出しには動きの印が付き、浮かぶ動きはしっかり（rich）のテーマだけが印を置く
 */
final class StorefrontMotionTest extends TestCase
{
    use DatabaseTruncation;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_each_theme_puts_its_motion_strength_on_the_page(): void
    {
        foreach (['default' => 'subtle', 'kinari' => 'subtle', 'fashion' => 'rich', 'botanica' => 'rich'] as $theme => $motion) {
            $id = 'mo'.substr($theme, 0, 2);
            $this->tenant($id)->forceFill(['theme' => $theme])->save();
            $this->inside($id, fn () => Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '帆布のトート', 'unit_amount' => 1200, 'status' => ListingStatus::Active]));

            $html = (string) $this->get("http://{$id}.localhost/")->assertOk()->getContent();

            // 外枠には見た目の名前（字と角。ADR 0097）も続く
            $this->assertMatchesRegularExpression('#<html lang="ja" data-motion="'.$motion.'"[ >]#', $html, $theme);
            $this->assertMatchesRegularExpression('#data-test="product-card" data-motion-card#', $html, $theme);
            $this->assertSame(1, substr_count($html, 'data-motion-intro'), "{$theme}: ページを開いた時の動きは 1 か所だけ");
            // 浮かぶ動き・写真のゆっくりした拡大は、しっかりのテーマだけ
            $this->assertSame($motion === 'rich', str_contains($html, 'data-motion-reveal'), $theme);

            // 一覧の札にも同じ印。ご注文の確認（メールアドレスを入れる画面）の本文には動きの印を置かない（ヘッダのメニューは外枠のもの）
            $this->assertStringContainsString('data-motion-card', (string) $this->get("http://{$id}.localhost/products")->assertOk()->getContent(), $theme);
            $lookup = (string) $this->get("http://{$id}.localhost/orders")->assertOk()->getContent();
            $this->assertSame(1, preg_match('#<main\b.*</main>#s', $lookup, $main), $theme);
            $this->assertStringNotContainsString('data-motion-', $main[0], $theme);
        }
    }

    /**
     * 選んだテーマが入っていない店は既定のテーマ（控えめ）で描く。強さの値は登録で確かめた 3 つのほかは出ない
     */
    public function test_a_shop_whose_theme_is_missing_falls_back_to_the_default_strength(): void
    {
        $this->tenant('monx')->forceFill(['theme' => 'removed-theme'])->save();

        $html = (string) $this->get('http://monx.localhost/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<html lang="ja" data-motion="subtle"[ >]#', $html);
    }
}
