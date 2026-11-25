<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Core\Catalog\Models\Category;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Media\Models\ShopMedia;
use App\Core\Sample\BotanicaSamples;
use App\Core\Sample\Models\SampleMark;
use App\Core\Sample\SamplePhotos;
use App\Core\Sample\SampleSet;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use Database\Seeders\SugarShopSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 入れたときのサンプルのセット（続き。インストーラーで 3 つのテーマから選ぶ）。外へは出ない（Http::fake）。
 * セットごとに入る（商品・テーマの切り替え・テーマの中身）・写真はその場で Unsplash から取る（取れない写真は無しで入れる・決まったホストの外へは行かない・大きすぎる・画像でない）・
 * --set の入れ替えは先に --remove・既定の雑貨は取りに行かない・店をまたがない・写真のアップロードの 1 分の回数の前で待つ
 */
final class ShopSampleSetsTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use WithTenants;

    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        FakeMedia::fake();
        Http::preventStrayRequests();
        // 写真のアップロードの 1 分の回数の前で待つときは、本当には待たずに時計を進める
        Sleep::fake(syncWithCarbon: true);
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        Tenant::query()->find('sugar')?->delete();

        foreach ($this->dirs as $dir) {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: int, 4: string}>
     */
    public static function sets(): array
    {
        return [
            // セット・テーマ・商品・カテゴリ・トップのヒーローの見出しの頭
            'お菓子' => ['sweets', 'default', 18, 5, 'ひとつずつ'],
            '布もの' => ['fashion', 'fashion', 3, 2, '毎日に'],
            '植物' => ['botanica', 'botanica', 8, 3, '暮らしに'],
        ];
    }

    #[DataProvider('sets')]
    public function test_each_set_goes_in_with_photos_from_unsplash_and_switches_the_theme(string $set, string $theme, int $listings, int $categories, string $heading): void
    {
        $this->fakeUnsplash();
        $tenant = $this->tenant('sst');

        $this->sample('sst', ['--set' => $set])->expectsOutputToContain('サンプルを入れました')->assertSuccessful();

        // テーマはセットのテーマへ（角砂糖は既定なので切り替えない）
        $this->assertSame($theme === 'default' ? null : $theme, $tenant->fresh()?->getAttribute('theme'));
        $this->assertSame($theme !== 'default', AuditEvent::query()->where('type', 'tenant.theme_changed')->where('tenant_id', 'sst')->exists());

        $this->inside('sst', function () use ($set, $theme, $listings, $categories, $heading): void {
            $this->assertSame($listings, Listing::query()->count());
            // お菓子は飲食料品なので軽減税率、ほかのセットは標準（ADR 0063）
            $this->assertSame([$set === 'sweets' ? 'reduced' : 'standard'], Listing::query()->pluck('tax_class')->map(static fn ($c): string => $c->value)->unique()->values()->all());
            $this->assertSame($categories, Category::query()->count());

            foreach (Listing::query()->get() as $listing) {
                $this->assertStringContainsString('（サンプル）', $listing->title);
                $this->assertTrue(ListingImage::query()->where('listing_id', $listing->id)->exists(), "{$listing->title} に写真");
                // 写真の出どころ（撮った人か Unsplash）を説明の終わりに
                $last = $listing->description_blocks[array_key_last($listing->description_blocks ?? [])] ?? [];
                $this->assertStringStartsWith('写真: ', (string) ($last['text'] ?? ''));
                $this->assertStringStartsWith('SAMPLE-', (string) $listing->variants()->value('sku'));
            }

            // テーマの中身は公開まで済み、ヒーローの写真はショップの写真に
            $published = app(ThemeContentService::class)->published($theme, app(ThemeRegistry::class)->get($theme)->schema());
            $this->assertStringStartsWith($heading, (string) $published['hero']['values']['heading']);
            $this->assertTrue(ShopMedia::query()->whereKey($published['hero']['values']['image'])->exists());
            // 特商法の見本は書き換えの印つき
            $this->assertStringContainsString(SampleSet::LEGAL_TODO, app(ShopSettingsService::class)->legal()[0]['value']);
            // 印に出どころが残る
            $credits = SampleMark::query()->where('subject_type', 'listing')->firstOrFail()->snapshot['credits'] ?? [];
            $this->assertNotSame([], $credits);
        });

        // 取りに行ったのは決まったホストの https だけ
        Http::assertSent(static fn (Request $request): bool => str_starts_with($request->url(), 'https://images.unsplash.com/'));
        Http::assertNotSent(static fn (Request $request): bool => ! in_array(parse_url($request->url(), PHP_URL_HOST), SamplePhotos::HOSTS, true));

        // 流し直しても増えない（取りにも行かない）
        $sent = count(Http::recorded());
        $this->sample('sst', ['--set' => $set])->expectsOutputToContain('もう入っています')->assertSuccessful();
        $this->assertCount($sent, Http::recorded());
        $this->inside('sst', fn () => $this->assertSame($listings, Listing::query()->count()));

        // ショップにそのまま出る
        $this->get('http://sst.localhost/')->assertOk()->assertSee('（サンプル）')->assertSee('data-test="hero-image"', false);
    }

    public function test_the_fashion_set_keeps_its_variants_options_and_description_photo(): void
    {
        $this->fakeUnsplash();
        $this->tenant('ssf');
        $this->sample('ssf', ['--set' => 'fashion'])->assertSuccessful();

        $this->inside('ssf', function (): void {
            $tote = Listing::query()->where('title', '帆布のトートバッグ（サンプル）')->sole();
            $this->assertSame(12, $tote->variants()->count());
            $this->assertSame(20, ListingVariant::query()->count());
            $this->assertSame(['SAMPLE-TOTE-CAMEL-L-LONG'], ListingVariant::query()->where('status', 'stopped')->pluck('sku')->all());
            $this->assertSame(5, ListingImage::query()->where('listing_id', $tote->id)->count(), '色ごと 3 枚と商品の写真 2 枚');
            $image = collect($tote->description_blocks)->firstWhere('type', 'image');
            $this->assertIsArray($image);
            $this->assertTrue(ShopMedia::query()->whereKey($image['media_id'])->exists(), '説明の写真はショップの写真を指す');
            // おすすめの商品は名前で書いた商品の ID に
            $published = app(ThemeContentService::class)->published('fashion', app(ThemeRegistry::class)->get('fashion')->schema());
            $this->assertContains($tote->id, $published['favorites']['values']['products']['ids']);
        });
    }

    /**
     * よくある失敗: 1 枚取れない・別のホストへリダイレクトする・大きすぎる・画像でない。その写真なしで入れて注意を出し、止めない
     */
    public function test_photos_that_cannot_be_fetched_are_left_out_with_a_warning(): void
    {
        $jpeg = $this->jpeg(400, 300);
        $urls = array_map(static fn (array $source): string => $source['url'], BotanicaSamples::PHOTOS);
        Http::fake([
            $urls['pot'].'*' => Http::response('', 404),
            // リダイレクトの行き先がほかのホスト（images.unsplash.com でない）
            $urls['plant'].'*' => Http::response('', 302, ['Location' => 'https://evil.example/plant.jpg']),
            $urls['cactus'].'*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) (SamplePhotos::MAX_BYTES + 1)]),
            $urls['garden'].'*' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'text/html']),
            $urls['leaves'].'*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $this->tenant('ssg');

        $this->sample('ssg', ['--set' => 'botanica'])
            ->expectsOutputToContain('写真 pot を入れられませんでした（取得元の応答が 404 でした）')
            ->expectsOutputToContain('写真 plant を入れられませんでした（決まった取得元')
            ->expectsOutputToContain('写真 cactus を入れられませんでした（大きすぎます')
            ->expectsOutputToContain('写真 garden を入れられませんでした（画像ではありません')
            ->expectsOutputToContain('写真 4 枚を入れられませんでした')
            ->assertSuccessful();

        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'evil.example'));

        $this->inside('ssg', function (): void {
            $this->assertSame(8, Listing::query()->count(), '商品は写真が無くても全部入る');
            // 写真が取れたのはシダ（leaves）だけ。商品 1 つと、季節の特集のショップの写真 1 枚
            $this->assertSame(1, ListingImage::query()->count());
            $this->assertSame(1, ShopMedia::query()->count());
            $this->assertSame('botanica', tenant()?->getAttribute('theme'));
        });
    }

    public function test_photo_fetching_refuses_other_hosts_oversized_bodies_and_non_images(): void
    {
        $jpeg = $this->jpeg(200, 100);
        Http::fake([
            'https://unsplash.com/photos/okokokok/*' => Http::response('', 302, ['Location' => 'https://images.unsplash.com/photo-1-ok?w=2000']),
            'https://images.unsplash.com/photo-1-ok*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
            'https://unsplash.com/photos/plainhttp/*' => Http::response('', 302, ['Location' => 'http://images.unsplash.com/photo-1-ok']),
            'https://unsplash.com/photos/loooooop/*' => Http::response('', 302, ['Location' => 'https://unsplash.com/photos/loooooop/download']),
            // つながらない（時間切れ・外へ出られない）
            'https://unsplash.com/photos/downdown/*' => Http::failedConnection(),
            // 大きさの知らせが無くても、読んだ量で止める
            'https://images.unsplash.com/photo-2-b16*' => Http::response(str_repeat('a', SamplePhotos::MAX_BYTES + 1), 200, ['Content-Type' => 'image/jpeg']),
            // 形の知らせは画像でも、中身が画像でない
            'https://images.unsplash.com/photo-3-fa4e*' => Http::response('GIF89a not really', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $dir = $this->directory();

        $failed = app(SamplePhotos::class)->fetch([
            'ok' => ['id' => 'okokokok', 'author' => 'A', 'page' => null],
            'plain' => ['id' => 'plainhttp', 'author' => 'A', 'page' => null],
            'loop' => ['id' => 'loooooop', 'author' => 'A', 'page' => null],
            'down' => ['id' => 'downdown', 'author' => 'A', 'page' => null],
            'big' => ['url' => 'https://images.unsplash.com/photo-2-b16', 'author' => null, 'page' => null],
            'fake' => ['url' => 'https://images.unsplash.com/photo-3-fa4e', 'author' => null, 'page' => null],
            'elsewhere' => ['url' => 'https://example.com/photo-4-a', 'author' => null, 'page' => null],
        ], $dir);

        $this->assertSame(['plain', 'loop', 'down', 'big', 'fake', 'elsewhere'], array_keys($failed));
        $this->assertStringStartsWith('接続できませんでした', $failed['down']);
        $this->assertStringContainsString('決まった取得元', $failed['plain']);
        $this->assertSame('リダイレクトが多すぎます', $failed['loop']);
        $this->assertStringContainsString('大きすぎます', $failed['big']);
        $this->assertSame('画像として読めません', $failed['fake']);
        $this->assertSame('写真の取得元の形が違います', $failed['elsewhere']);
        // 取れた 1 枚だけがフォルダに残る（取りかけた物は消す）
        $this->assertSame(['ok.jpg'], array_map('basename', glob("{$dir}/*") ?: []));
        Http::assertNotSent(static fn (Request $request): bool => str_starts_with($request->url(), 'http://'));
    }

    /**
     * 別のセットへ替えるときは先に消す。消した後は別のセットを入れられ、前のサンプルが空に戻したテーマの中身は新しいセットの中身に替わる
     */
    public function test_switching_sets_needs_remove_first_and_then_replaces_the_cleared_theme_content(): void
    {
        $this->fakeUnsplash();
        $this->tenant('ssh');
        $this->sample('ssh')->assertSuccessful();
        Http::assertNothingSent();

        $this->sample('ssh', ['--set' => 'sweets'])->expectsOutputToContain('--remove')->assertFailed();
        $this->inside('ssh', fn () => $this->assertSame(8, Listing::query()->count()));

        $this->sample('ssh', ['--remove' => true])->expectsOutputToContain('まとめて消しました')->assertSuccessful();
        $this->assertTrue(AuditEvent::query()->where('type', 'tenant.samples_removed')->where('tenant_id', 'ssh')->exists());
        $this->inside('ssh', fn () => $this->assertSame(0, Listing::query()->count()));

        $this->sample('ssh', ['--set' => 'sweets'])->assertSuccessful();
        $this->inside('ssh', function (): void {
            $this->assertSame(18, Listing::query()->count());
            $published = app(ThemeContentService::class)->published('default', app(ThemeRegistry::class)->get('default')->schema());
            $this->assertSame('SAMPLE SWEETS SHOP', $published['hero']['values']['eyebrow']);
        });

        // 消すサンプルが無いときは何もしない
        $this->sample('ssh', ['--remove' => true])->assertSuccessful();
        $this->sample('ssh', ['--remove' => true])->expectsOutputToContain('消すサンプルはありません')->assertSuccessful();
    }

    public function test_the_shop_reworked_theme_content_is_not_overwritten_by_the_next_set(): void
    {
        $this->fakeUnsplash();
        $this->tenant('ssi');
        $this->sample('ssi')->assertSuccessful();
        $this->sample('ssi', ['--remove' => true])->assertSuccessful();

        // 空に戻した後に、お店がトップを書き換えた
        $this->inside('ssi', function (): void {
            $content = app(ThemeContentService::class);
            $schema = app(ThemeRegistry::class)->get('default')->schema();
            $current = $content->current('default');
            $this->assertNotNull($current);
            $version = $content->saveDraft('default', $schema, $current->id, $current->revision, ['announcement' => ['values' => ['text' => 'お店のお知らせ']]], 'test');
            $content->publish('default', $schema, $version->id, $version->revision, 'test');
        });

        $this->sample('ssi', ['--set' => 'sweets'])->assertSuccessful();
        $this->inside('ssi', function (): void {
            $published = app(ThemeContentService::class)->published('default', app(ThemeRegistry::class)->get('default')->schema());
            $this->assertSame('お店のお知らせ', $published['announcement']['values']['text']);
            $this->assertSame(18, Listing::query()->count());
        });
    }

    public function test_samples_stay_inside_their_own_shop(): void
    {
        $this->fakeUnsplash();
        $this->tenant('ssj');
        $this->tenant('ssk');
        $this->sample('ssk')->assertSuccessful();

        $this->sample('ssj', ['--set' => 'botanica'])->assertSuccessful();
        $this->sample('ssj', ['--remove' => true])->assertSuccessful();

        $this->assertNull(Tenant::query()->findOrFail('ssk')->getAttribute('theme'));
        $this->inside('ssk', function (): void {
            $this->assertSame(8, Listing::query()->count());
            $this->assertSame(16, SampleMark::query()->active()->count());
        });
    }

    public function test_local_photos_are_used_without_fetching_and_missing_ones_are_left_out(): void
    {
        $dir = $this->directory();
        file_put_contents("{$dir}/pot.jpg", $this->jpeg(300, 300));
        file_put_contents("{$dir}/leaves.png", $this->encode(imagecreatetruecolor(300, 200), 'png'));
        $this->tenant('ssl');

        $this->sample('ssl', ['--set' => 'botanica', '--photos' => $dir])
            ->expectsOutputToContain('写真 cactus を入れられませんでした（フォルダにありません）')
            ->assertSuccessful();

        Http::assertNothingSent();
        // ミントポットとスターターセット（pot）・シダ（leaves）の商品 3 つと、ヒーロー（pot）と季節の特集（leaves）
        $this->inside('ssl', fn () => $this->assertSame([3, 2], [ListingImage::query()->count(), ShopMedia::query()->count()]));
    }

    /**
     * 写真のアップロードの 1 分の回数の前で、書き込みの錠の外で待つ（待たないと途中で断られる）
     */
    public function test_it_waits_before_the_per_minute_photo_limit(): void
    {
        config(['media.uploads_per_minute' => 4]);
        Sleep::fake(syncWithCarbon: true);
        $this->fakeUnsplash();
        $this->tenant('ssm');

        $this->sample('ssm', ['--set' => 'botanica'])->expectsOutputToContain('秒待ちます')->assertSuccessful();

        Sleep::assertSleptTimes(2);
        $this->inside('ssm', fn () => $this->assertSame([8, 4], [ListingImage::query()->count(), ShopMedia::query()->count()]));
    }

    /**
     * 開発の見本の店 SUGAR SHOP は、同じセット（お菓子）を手元の写真で作る（取りに行かない）
     */
    public function test_the_sugar_dev_shop_is_built_from_the_same_set_with_local_photos(): void
    {
        $dir = $this->directory();
        file_put_contents("{$dir}/hero.jpg", $this->jpeg(600, 400));
        file_put_contents("{$dir}/jam-apricot.jpg", $this->jpeg(300, 300));
        app()->instance(SugarShopSeeder::class, new SugarShopSeeder($dir));

        $this->seed(SugarShopSeeder::class);

        Http::assertNothingSent();
        $tenant = Tenant::query()->findOrFail('sugar');
        $state = $tenant->run(function (): array {
            $published = app(ThemeContentService::class)->published('default', app(ThemeRegistry::class)->get('default')->schema());

            return [Listing::query()->count(), ListingImage::query()->count(), $published['hero']['values']['eyebrow'], is_string($published['hero']['values']['image'])];
        });
        $this->assertSame([18, 1, 'SAMPLE SWEETS SHOP', true], $state);
        // トップの新着は作った時刻の新しい順の数品だけ（18 品が同じ秒に作られると並びが毎回変わる）ので、必ず出る見出しで確かめる
        $this->get('http://sugar.localhost/')->assertOk()->assertSee('SAMPLE SWEETS SHOP');
    }

    public function test_wrong_arguments_are_refused(): void
    {
        $this->tenant('ssn');
        $this->sample('ssn', ['--set' => 'cars'])->expectsOutputToContain('サンプルのセットがありません')->assertExitCode(2);
        $this->sample('ssn', ['--remove' => true, '--set' => 'sweets'])->assertExitCode(2);
        $this->sample('ssn', ['--set' => 'sweets', '--photos' => '/nonexistent/'.bin2hex(random_bytes(4))])->assertExitCode(2);
        $this->sample('nothere', ['--set' => 'sweets'])->assertExitCode(1);
        Http::assertNothingSent();
        $this->inside('ssn', fn () => $this->assertSame(0, Listing::query()->count()));
    }

    /**
     * Unsplash の代わり: 取り出しの入口（unsplash.com）は images.unsplash.com へリダイレクト、images.unsplash.com は JPEG を返す
     */
    private function fakeUnsplash(): void
    {
        $jpeg = $this->jpeg(400, 300);
        Http::fake([
            'https://unsplash.com/photos/*' => static fn (Request $request) => Http::response('', 302, ['Location' => 'https://images.unsplash.com/photo-1-'.md5($request->url()).'?w=2000&fm=jpg']),
            'https://images.unsplash.com/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg; charset=binary', 'Content-Length' => (string) strlen($jpeg)]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function sample(string $tenant, array $options = []): PendingCommand
    {
        $command = $this->artisan('shop:sample', ['id' => $tenant] + $options);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function directory(): string
    {
        $dir = sys_get_temp_dir().'/sample-sets-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $this->dirs[] = $dir;

        return $dir;
    }
}
