<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Models\Listing;
use App\Core\Theme\Models\ThemeVersion;
use App\Models\Tenant;
use Database\Seeders\BotanicaShopSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use LogicException;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 公式テーマ 3（botanica）の見本の店。区画の値で見せ方を作り、2 回走らせても変わらず、本番では作らない
 */
final class BotanicaShopSeederTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use WithTenants;

    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        FakeMedia::fake();
    }

    protected function tearDown(): void
    {
        app()->instance('env', 'testing');
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $tenant = Tenant::query()->find('midori');
        if ($tenant !== null) {
            $tenant->delete();
        }
        foreach ($this->dirs as $dir) {
            array_map('unlink', glob("{$dir}/*.jpg") ?: []);
            @rmdir($dir);
        }
        parent::tearDown();
    }

    public function test_demo_shop_has_photos_products_and_sections_and_repeating_does_not_change_it(): void
    {
        $this->withoutVite();
        // 見本の写真の代わりに、テストで作った写真の置き場を渡す
        $dir = sys_get_temp_dir().'/botanica-'.bin2hex(random_bytes(4));
        mkdir($dir);
        foreach (['pot', 'plant', 'cactus', 'garden', 'leaves'] as $name) {
            file_put_contents("{$dir}/{$name}.jpg", $this->jpeg(800, 600));
        }
        $this->dirs[] = $dir;
        app()->instance(BotanicaShopSeeder::class, new BotanicaShopSeeder($dir));

        $this->seed(BotanicaShopSeeder::class);
        $tenant = Tenant::query()->findOrFail('midori');
        $this->assertSame('botanica', $tenant->getAttribute('theme'));
        $this->assertSame('midori.localhost', $tenant->domains()->sole()->domain);
        [$count, $events] = $tenant->run(fn (): array => [Listing::query()->count(), DomainEvent::query()->count()]);
        $this->assertSame(8, $count);
        $this->assertGreaterThan(0, $events);
        // 見せ方は botanica の区画の値として公開してある
        $this->assertNotNull($tenant->run(fn () => ThemeVersion::query()->where('theme', 'botanica')->sole()->published_at));
        $response = $this->get('http://midori.localhost/')->assertOk();
        $html = (string) $response->getContent();
        tenancy()->end();
        foreach (['data-theme="botanica"', 'data-test="announcement"', 'data-test="hero-image"', 'data-test="looks"', 'data-test="categories"', 'data-test="seasonal"', 'data-test="favorites"', 'data-test="promises"', '/media/listings/', '/media/shop/', 'href="/c/plants"'] as $marker) {
            $this->assertStringContainsString($marker, $html, $marker);
        }
        $this->assertStringNotContainsString('images.unsplash.com', $html);
        $this->seed(BotanicaShopSeeder::class);
        $this->assertSame(1, Tenant::query()->count());
        $this->assertSame([$count, $events], $tenant->run(fn (): array => [Listing::query()->count(), DomainEvent::query()->count()]));
    }

    public function test_demo_shop_without_photos_still_has_its_text(): void
    {
        $this->withoutVite();
        app()->instance(BotanicaShopSeeder::class, new BotanicaShopSeeder(sys_get_temp_dir().'/botanica-missing-'.bin2hex(random_bytes(4))));
        $this->seed(BotanicaShopSeeder::class);

        $html = (string) $this->get('http://midori.localhost/')->assertOk()->getContent();
        tenancy()->end();
        // 写真の要る区画（右の特集・季節の特集）は出さず、文の区画は出す
        $this->assertStringContainsString('data-test="announcement"', $html);
        $this->assertStringContainsString('data-test="promises"', $html);
        $this->assertStringNotContainsString('data-test="looks"', $html);
        $this->assertStringNotContainsString('data-test="seasonal"', $html);
        $this->assertStringNotContainsString('data-test="hero-image"', $html);
    }

    public function test_existing_shop_is_never_overwritten(): void
    {
        $tenant = $this->tenant('midori', '既存のお店');
        $this->seed(BotanicaShopSeeder::class);
        $this->assertSame('既存のお店', $tenant->fresh()->name);
        $this->assertNull($tenant->fresh()->getAttribute('theme'));
        $this->assertSame(0, $tenant->run(fn (): int => Listing::query()->count()));
    }

    public function test_demo_cannot_run_in_production(): void
    {
        app()->instance('env', 'production');
        $this->expectException(LogicException::class);
        (new BotanicaShopSeeder)->run();
    }
}
