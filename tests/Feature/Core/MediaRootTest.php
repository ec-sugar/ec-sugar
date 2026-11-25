<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Media\DeleteTenantMedia;
use App\Core\Media\ForeignMediaRootException;
use App\Core\Media\MediaRoot;
use App\Models\Tenant;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 写真の置き場を環境ごとに分ける。店の ID は開発もテストも短い固定の名前なので、置き場が同じだと、
 * テストの店の後片付けが開発の店の写真を消す。設定を忘れても、置き場をリンクでつないでも、別の環境の写真を消せないことを確かめる。
 *
 * DB は使わない（店の後片付けの流れは TenantMediaCleanupTest）。開発の本物の置き場（storage/app/media）には触らず、使い捨ての場所を使う。
 */
final class MediaRootTest extends TestCase
{
    use UsesScratchMedia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScratchMedia();
    }

    protected function tearDown(): void
    {
        $this->tearDownScratchMedia();

        parent::tearDown();
    }

    public function test_tests_and_browser_tests_use_their_own_media_directory(): void
    {
        $root = (string) config('filesystems.disks.media.root');

        // PHP のテスト（phpunit.xml）は media-testing。開発と本番の既定（media）とも、ブラウザのテスト（media-e2e）とも別。
        // 並べて走らせる作業場所は外から media-testing-g のような別名を渡す（外の値が phpunit.xml の force より勝つ）ので、
        // テストの入口の守りと同じ形（media-testing か media-testing-<文字> の 1 区切り。中央の DB との組は守りが見る）を許す
        $this->assertSame(storage_path('app'), dirname($root));
        $this->assertMatchesRegularExpression('/\Amedia-testing(?:-[a-z0-9]+)?\z/', basename($root));
        $this->assertNotSame(storage_path('app/media-e2e'), $root);
        $this->assertSame(storage_path('app/media'), config('filesystems.media_default_root'));
        $this->assertNotSame(config('filesystems.media_default_root'), $root);

        // ブラウザのテストのサーバーも、開発と別の置き場を使う（既定は media-e2e、E2E_DB で分けたときは media-e2e-<文字>）
        $env = (string) file_get_contents(base_path('tests/e2e/env.js'));
        $this->assertMatchesRegularExpression('/\bMEDIA_DIRECTORY: mediaDirectory,/', $env);
        $this->assertStringContainsString("export const mediaDirectory = `media-e2e\${database.slice('ec_e2e'.length).replace('_', '-')}`;", $env);

        // 写真の置き場はどれもコミットしない（storage/app は private と public と .gitignore 以外を無視する）
        $ignore = (string) file_get_contents(storage_path('app/.gitignore'));
        $this->assertSame(['*', '!private/', '!public/', '!.gitignore'], array_values(array_filter(array_map('trim', explode("\n", $ignore)))));
    }

    public function test_the_development_directory_is_refused_while_testing(): void
    {
        // phpunit.xml の MEDIA_DIRECTORY を忘れた形: テストの環境なのに、置き場が開発の既定の場所
        $dev = $this->useMedia('dev/media', defaultRoot: 'dev/media');
        $this->place($dev, 'tenants/mra/listings/a/large.webp');

        try {
            (new DeleteTenantMedia(new Tenant(['id' => 'mra'])))->handle();
            $this->fail('開発の保存先の写真を、テストの環境で消せてしまった');
        } catch (ForeignMediaRootException $e) {
            $this->assertStringContainsString('MEDIA_DIRECTORY', $e->getMessage());
        }

        $this->assertFileExists("{$dev}/tenants/mra/listings/a/large.webp");
        // 断るときは何も書かない（持ち主の札も付けない）
        $this->assertFileDoesNotExist("{$dev}/".MediaRoot::OWNER_FILE);
    }

    public function test_a_directory_owned_by_another_environment_is_refused(): void
    {
        // 開発（店の DB の接頭辞 ec_t_）の札が付いた置き場を、テストの環境（ec_test…）が指してしまった形
        $other = $this->useMedia('other/media-shared');
        $this->place($other, MediaRoot::OWNER_FILE, MediaRoot::ownerFor('ec_t_', ''));
        $this->place($other, 'tenants/mrb/shop/h/large.webp');

        $this->expectException(ForeignMediaRootException::class);

        try {
            (new DeleteTenantMedia(new Tenant(['id' => 'mrb'])))->handle();
        } finally {
            $this->assertFileExists("{$other}/tenants/mrb/shop/h/large.webp");
            $this->assertSame(MediaRoot::ownerFor('ec_t_', ''), file_get_contents("{$other}/".MediaRoot::OWNER_FILE));
        }
    }

    public function test_a_link_to_the_development_directory_is_refused_by_what_it_points_at(): void
    {
        // 別の作業場所（worktree）の置き場を、元の作業場所の開発の置き場へシンボリックリンクでつないだ形（写真が消えた窓の形）
        $dev = $this->path('main/storage/app/media');
        $this->place($dev, 'tenants/mrf/shop/h/large.webp');
        File::ensureDirectoryExists($this->path('worktree/storage/app'));
        symlink($dev, $this->path('worktree/storage/app/media'));

        // 1. テストの環境: 名前は別の場所（worktree の media）でも、実体が開発の既定の置き場なら断る
        $this->useMedia('worktree/storage/app/media', defaultRoot: 'main/storage/app/media');

        try {
            (new DeleteTenantMedia(new Tenant(['id' => 'mrf'])))->handle();
            $this->fail('リンクを通して、開発の保存先の写真をテストの環境で消せてしまった');
        } catch (ForeignMediaRootException) {
        }

        $this->assertFileExists("{$dev}/tenants/mrf/shop/h/large.webp");

        // 2. 開発と同じ local の環境（ブラウザのテストや worktree の確かめ用のサーバー）: 札は実体の側にあるので、
        //    リンクを通して見ても開発の札（ec_t_*）が見え、店の DB の形が違えば断る
        $this->place($dev, MediaRoot::OWNER_FILE, MediaRoot::ownerFor('ec_t_', ''));
        $this->app['env'] = 'local';
        $this->useMedia('worktree/storage/app/media', defaultRoot: 'worktree/storage/app/media-default');

        try {
            (new DeleteTenantMedia(new Tenant(['id' => 'mrf'])))->handle();
            $this->fail('リンクを通して、開発の保存先の写真を別の環境で消せてしまった');
        } catch (ForeignMediaRootException) {
        }

        $this->assertFileExists("{$dev}/tenants/mrf/shop/h/large.webp");
    }

    public function test_in_development_an_unmarked_directory_is_claimed_and_the_shop_photos_are_deleted_as_before(): void
    {
        // 開発で普通に店を消す形（環境は local、置き場は既定の media、札はまだ無い）
        $this->app['env'] = 'local';
        $dev = $this->useMedia('dev/media', defaultRoot: 'dev/media');
        $this->place($dev, 'tenants/mrc/listings/a/large.webp');
        $this->place($dev, 'tenants/mrd/listings/b/large.webp');

        (new DeleteTenantMedia(new Tenant(['id' => 'mrc'])))->handle();

        $this->assertDirectoryDoesNotExist("{$dev}/tenants/mrc");
        $this->assertFileExists("{$dev}/tenants/mrd/listings/b/large.webp");
        $this->assertSame(MediaRoot::current()->owner(), file_get_contents("{$dev}/".MediaRoot::OWNER_FILE));

        // 2 回目は札を確かめて消す
        (new DeleteTenantMedia(new Tenant(['id' => 'mrd'])))->handle();
        $this->assertDirectoryDoesNotExist("{$dev}/tenants/mrd");
    }

    public function test_the_owner_follows_the_tenant_database_names(): void
    {
        $this->assertSame("tenant-databases: ec_t_*\n", MediaRoot::ownerFor('ec_t_', ''));
        $this->assertSame(MediaRoot::ownerFor((string) config('tenancy.database.prefix'), ''), MediaRoot::current()->owner());
        $this->assertNotSame(MediaRoot::ownerFor('ec_t_', ''), MediaRoot::current()->owner());
    }
}
