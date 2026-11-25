<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Media\Models\ShopMedia;
use App\Core\Media\Services\ShopMediaService;
use App\Core\Theme\Models\ThemeVersion;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeMedia;
use Tests\Support\MakesImages;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * ショップの写真の置き場（ADR 0028 区切り 1）。商品と関係ない写真を店ごとに上げて置き、ショップには置き場に今ある写真だけを配る。
 */
final class ShopMediaTest extends TestCase
{
    use DatabaseTruncation;
    use MakesImages;
    use OperatorSession;
    use WithTenants;

    /** @var list<string> */
    private array $files = [];

    private Operator $operator;

    private Filesystem $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->disk = FakeMedia::fake();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_a_photo_is_rebuilt_listed_and_served_on_the_shop_without_cookies(): void
    {
        $this->tenant('sma');
        $this->signIn();

        $marker = 'SECRET-GPS-35.6895-139.6917';
        $response = $this->upload('sma', $this->withExif($this->jpeg(3000, 1500), $marker, 1).'<?php system($_GET["c"]); ?>', '  春のキャンペーン  ')
            ->assertCreated()
            ->assertJsonPath('item.width', 2000)
            ->assertJsonPath('item.height', 1000)
            ->assertJsonPath('item.alt', '春のキャンペーン')
            ->assertJsonPath('item.used_by', []);
        $id = (string) $response->json('item.id');

        $this->assertSame(["tenants/sma/shop/media/{$id}-large.webp", "tenants/sma/shop/media/{$id}-medium.webp"], $this->allFiles());
        foreach ($this->allFiles() as $file) {
            $stored = (string) $this->disk->get($file);
            $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer($stored));
            $this->assertStringNotContainsString($marker, $stored);
            $this->assertStringNotContainsString('<?php', $stored);
        }

        $this->send('GET', '/api/tenants/sma/shop-media')->assertOk()
            ->assertJsonPath('max', ShopMediaService::MAX_PER_SHOP)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $id)
            ->assertJsonPath('items.0.alt', '春のキャンペーン');
        // 管理画面のプレビュー
        $this->send('GET', "/api/tenants/sma/shop-media/{$id}/medium")->assertOk()->assertHeader('Content-Type', 'image/webp');

        // ショップ: Cookie なし、共有のキャッシュに載せられる、同じ中身なら 304
        $served = $this->get("http://sma.localhost/media/shop/{$id}/medium")->assertOk()->assertHeader('Content-Type', 'image/webp');
        tenancy()->end();
        $this->assertSame([], $served->headers->getCookies());
        $this->assertStringContainsString('public', (string) $served->headers->get('Cache-Control'));
        $this->assertSame([1000, 500], array_slice((array) getimagesizefromstring((string) $served->getContent()), 0, 2));
        $this->withHeaders(['If-None-Match' => (string) $served->headers->get('ETag')])->get("http://sma.localhost/media/shop/{$id}/medium")->assertStatus(304);
        tenancy()->end();

        // 記録には寸法だけ（店の人が書いた代わりの文字は入れない）
        $this->inside('sma', function () use ($id): void {
            $event = DomainEvent::query()->where('type', 'shop.media_added')->sole();
            $this->assertSame($id, $event->aggregate_id);
            $this->assertEquals(['width' => 2000, 'height' => 1000], $event->payload);
            $this->assertStringStartsWith('operator:', (string) $event->actor);
        });
    }

    public function test_the_alt_text_is_one_short_line_and_changes_are_recorded_without_the_text(): void
    {
        $this->tenant('smb');
        $this->signIn();
        $id = (string) $this->upload('smb', $this->jpeg(400, 200))->assertCreated()->assertJsonPath('item.alt', null)->json('item.id');

        $this->send('PATCH', "/api/tenants/smb/shop-media/{$id}", ['alt' => '  店の入口  '])->assertOk()->assertJsonPath('item.alt', '店の入口');
        // 同じ文なら記録を増やさない
        $this->send('PATCH', "/api/tenants/smb/shop-media/{$id}", ['alt' => '店の入口'])->assertOk();
        // 空は「無し」
        $this->send('PATCH', "/api/tenants/smb/shop-media/{$id}", ['alt' => '   '])->assertOk()->assertJsonPath('item.alt', null);

        // 末尾の NUL は Laravel の TrimStrings が空白と一緒に削るので、途中に入れる（入口の RejectMalformedInput が断る）
        foreach ([str_repeat('あ', 201), "1 行目\n2 行目", "タブ\tあり", "NUL\0途中", "改\u{0085}行", "C1\u{009F}あり"] as $bad) {
            $this->assertSame(422, $this->send('PATCH', "/api/tenants/smb/shop-media/{$id}", ['alt' => $bad])->status(), (string) json_encode($bad));
        }
        $this->send('PATCH', "/api/tenants/smb/shop-media/{$id}", [])->assertStatus(422)->assertJsonValidationErrors('alt');
        $this->send('PATCH', "/api/tenants/smb/shop-media/{$id}", ['alt' => str_repeat('あ', 200)])->assertOk();
        // 上げるときの代わりの文字も同じ決まり
        $this->upload('smb', $this->jpeg(400, 200), "改行\nあり")->assertStatus(422)->assertJsonValidationErrors('alt');
        $this->assertCount(2, $this->allFiles());

        // 無い写真は 404
        $this->send('PATCH', '/api/tenants/smb/shop-media/'.strtolower((string) Str::ulid()), ['alt' => 'x'])->assertNotFound();

        $this->inside('smb', function () use ($id): void {
            $events = DomainEvent::query()->where('type', 'shop.media_updated')->orderBy('sequence')->get();
            $this->assertCount(3, $events);
            foreach ($events as $event) {
                $this->assertSame($id, $event->aggregate_id);
                $this->assertEquals(['changed' => ['alt']], $event->payload);
            }
            // DB でも 1 行・空でない文だけ
            $this->expectException(QueryException::class);
            DB::table('shop_media')->where('id', $id)->update(['alt' => "改行\nあり"]);
        });
    }

    public function test_removing_a_photo_deletes_its_files_and_stops_serving_it(): void
    {
        $this->tenant('smc');
        $this->signIn();
        $id = (string) $this->upload('smc', $this->jpeg(400, 200))->assertCreated()->json('item.id');
        $keep = (string) $this->upload('smc', $this->jpeg(500, 250))->assertCreated()->json('item.id');

        $this->send('DELETE', "/api/tenants/smc/shop-media/{$id}")->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $keep);
        $this->assertSame([], array_values(array_filter($this->allFiles(), static fn (string $f): bool => str_contains($f, $id))));
        $this->get("http://smc.localhost/media/shop/{$id}/large")->assertNotFound();
        tenancy()->end();
        $this->send('GET', "/api/tenants/smc/shop-media/{$id}/large")->assertNotFound();
        // 二度目は「見つからない」
        $this->send('DELETE', "/api/tenants/smc/shop-media/{$id}")->assertNotFound();

        // 消した写真のファイルが何かの理由で残っていても、置き場の行が無ければ配らない
        $this->disk->put("tenants/smc/shop/media/{$id}-large.webp", (string) $this->disk->get("tenants/smc/shop/media/{$keep}-large.webp"));
        $this->get("http://smc.localhost/media/shop/{$id}/large")->assertNotFound();
        tenancy()->end();
        $this->send('GET', "/api/tenants/smc/shop-media/{$id}/large")->assertNotFound();
        // 置き場に無い ID・形の違う ID・知らない大きさも配らない
        $this->get('http://smc.localhost/media/shop/'.strtolower((string) Str::ulid()).'/large')->assertNotFound();
        tenancy()->end();
        $this->get('http://smc.localhost/media/shop/../../listings/large')->assertNotFound();
        tenancy()->end();
        $this->get("http://smc.localhost/media/shop/{$keep}/original")->assertNotFound();
        tenancy()->end();
        $this->get("http://smc.localhost/media/shop/{$keep}/large")->assertOk();
        tenancy()->end();

        $this->inside('smc', fn () => $this->assertSame(
            ['shop.media_added', 'shop.media_added', 'shop.media_removed'],
            DomainEvent::query()->where('type', 'like', 'shop.%')->orderBy('sequence')->pluck('type')->all(),
        ));
    }

    public function test_a_photo_used_by_a_theme_cannot_be_removed(): void
    {
        $this->tenant('smd');
        $this->signIn();
        $id = (string) $this->upload('smd', $this->jpeg(400, 200))->assertCreated()->json('item.id');
        $draft = fn (array $sections, ?string $versionId, int $revision) => $this->send('PUT', '/api/tenants/smd/themes/fashion/pages/home/draft', ['version_id' => $versionId, 'revision' => $revision, 'sections' => $sections])->assertOk();
        $versionId = (string) $draft([['type' => 'hero', 'values' => ['image' => $id]]], null, 0)->json('version.id');
        $this->send('GET', '/api/tenants/smd/shop-media')->assertJsonPath('items.0.used_by', ['theme:fashion:標準']);

        $this->send('DELETE', "/api/tenants/smd/shop-media/{$id}")->assertStatus(422)->assertJsonValidationErrors('media');
        $this->assertCount(2, $this->allFiles());
        $this->get("http://smd.localhost/media/shop/{$id}/large")->assertOk();
        tenancy()->end();
        $this->inside('smd', fn () => $this->assertSame(0, DomainEvent::query()->where('type', 'shop.media_removed')->count()));

        // 下書きから外せば消せる
        $draft([], $versionId, 1);
        $this->send('GET', '/api/tenants/smd/shop-media')->assertJsonPath('items.0.used_by', []);
        $this->send('DELETE', "/api/tenants/smd/shop-media/{$id}")->assertOk()->assertJsonPath('items', []);
        $this->assertSame([], $this->allFiles());
    }

    /**
     * カスタム区画の HTML の中の写真（<img src="/media/shop/{ID}/large">）も「使っている写真」。自由な HTML と見たまま書ける文の両方
     */
    public function test_a_photo_used_inside_custom_html_cannot_be_removed(): void
    {
        $this->tenant('smk');
        $this->signIn();
        $inHtml = (string) $this->upload('smk', $this->jpeg(400, 200))->assertCreated()->json('item.id');
        $inRich = (string) $this->upload('smk', $this->jpeg(300, 200))->assertCreated()->json('item.id');
        $unused = (string) $this->upload('smk', $this->jpeg(200, 200))->assertCreated()->json('item.id');
        $draft = fn (array $sections, ?string $versionId, int $revision) => $this->send('PUT', '/api/tenants/smk/themes/fashion/pages/home/draft', ['version_id' => $versionId, 'revision' => $revision, 'sections' => $sections])->assertOk();
        $versionId = (string) $draft([
            // 大文字で書いても、しまう形は小文字にそろう
            ['type' => 'basic_html', 'values' => ['html' => '<figure><img src="/media/shop/'.strtoupper($inHtml).'/large" alt="写真"><figcaption>説明</figcaption></figure>']],
            ['type' => 'basic_richtext', 'values' => ['body' => '<p>文<img src="/media/shop/'.$inRich.'/medium"></p>']],
        ], null, 0)->json('version.id');

        $used = array_column($this->send('GET', '/api/tenants/smk/shop-media')->assertOk()->json('items'), 'used_by', 'id');
        $this->assertSame(['theme:fashion:標準'], $used[$inHtml]);
        $this->assertSame(['theme:fashion:標準'], $used[$inRich]);
        $this->assertSame([], $used[$unused]);

        foreach ([$inHtml, $inRich] as $id) {
            $this->send('DELETE', "/api/tenants/smk/shop-media/{$id}")->assertStatus(422)->assertJsonValidationErrors('media');
            $this->get("http://smk.localhost/media/shop/{$id}/large")->assertOk();
            tenancy()->end();
        }
        $this->send('DELETE', "/api/tenants/smk/shop-media/{$unused}")->assertOk();
        $this->assertCount(4, $this->allFiles());

        // HTML から外せば消せる
        $draft([['type' => 'basic_html', 'values' => ['html' => '<p>写真なし</p>']]], $versionId, 1);
        foreach ([$inHtml, $inRich] as $id) {
            $this->send('DELETE', "/api/tenants/smk/shop-media/{$id}")->assertOk();
        }
        $this->assertSame([], $this->allFiles());
    }

    /**
     * HTML の中の写真も、ほかの写真の欄と同じく保存のときにこの店の置き場にあるかを確かめ、無ければ断る（黙って外さない。道筋つき）
     */
    public function test_photos_inside_custom_html_must_be_in_this_shop(): void
    {
        $this->tenant('sml');
        $this->tenant('smm');
        $this->signIn();
        $mine = (string) $this->upload('sml', $this->jpeg(400, 200))->assertCreated()->json('item.id');
        $theirs = (string) $this->upload('smm', $this->jpeg(400, 200))->assertCreated()->json('item.id');
        $put = fn (string $type, string $field, string $html) => $this->send('PUT', '/api/tenants/sml/themes/fashion/pages/home/draft', ['version_id' => null, 'revision' => 0, 'sections' => [
            ['type' => 'basic_text', 'values' => ['heading' => '見出し']],
            ['type' => $type, 'values' => [$field => $html]],
        ]]);

        foreach ([
            'ほかの店の写真' => $theirs,
            '保存先に無い写真' => strtolower((string) Str::ulid()),
        ] as $case => $id) {
            foreach (['basic_html' => 'html', 'basic_richtext' => 'body'] as $type => $field) {
                $put($type, $field, '<p>文</p><img src="/media/shop/'.$id.'/large"><img src="/media/shop/'.$mine.'/large">')
                    ->assertStatus(422)
                    ->assertJsonValidationErrors(["sections.1.values.{$field}" => '「'.($field === 'html' ? 'HTML' : '文').'」の中の写真に、「写真」の一覧に無いものがあります。写真を選び直すか、その写真（<img>）を削除してください。'])
                    ->assertJsonMissingValidationErrors(['sections']);
            }
            $this->assertSame(0, $this->inside('sml', fn (): int => ThemeVersion::query()->count()), $case);
        }

        $put('basic_html', 'html', '<img src="/media/shop/'.$mine.'/large">')->assertOk();
        $this->send('GET', '/api/tenants/sml/shop-media')->assertJsonPath('items.0.used_by', ['theme:fashion:標準']);
        // ほかの店の写真は、この店の保存では使われていない（消せる）
        $this->send('GET', '/api/tenants/smm/shop-media')->assertJsonPath('items.0.used_by', []);
    }

    public function test_the_archived_hero_photo_cannot_be_removed_before_it_is_copied(): void
    {
        $this->tenant('smj');
        $this->signIn();
        $id = (string) $this->upload('smj', $this->jpeg(400, 200))->assertCreated()->json('item.id');
        // 区切り 2c で店の設定から控えへ移したヒーロー（外部キーは無い）
        $this->inside('smj', fn () => DB::table('legacy_storefront_settings')->insert([
            'id' => strtolower((string) Str::ulid()), 'storefront' => '{}', 'hero_media_id' => $id, 'archived_at' => now(),
        ]));

        $this->send('GET', '/api/tenants/smj/shop-media')->assertJsonPath('items.0.used_by', ['legacy:hero']);
        $this->send('DELETE', "/api/tenants/smj/shop-media/{$id}")->assertStatus(422)
            ->assertJsonPath('errors.media.0', 'この写真は前のデザインのバックアップ（ヒーロー。テーマを切り替えるとコピーされます）で使われているため削除できません。先にそちらで別の写真に変更するか、写真を外してください。');
        $this->assertCount(2, $this->allFiles());

        // 区画のテーマへ写すと、テーマのバージョンが使う写真になる（控えも残るので、引き続き消せない）
        $this->artisan('storefront:theme', ['tenant' => 'smj', 'theme' => 'fashion'])->assertSuccessful();
        $this->send('GET', '/api/tenants/smj/shop-media')->assertJsonPath('items.0.used_by', ['theme:fashion:標準', 'legacy:hero']);
    }

    public function test_a_photo_being_chosen_by_an_unfinished_change_is_not_removed_underneath_it(): void
    {
        $this->tenant('smh');
        $this->signIn();
        $id = (string) $this->upload('smh', $this->jpeg(400, 200))->assertCreated()->json('item.id');

        $this->inside('smh', function () use ($id): void {
            // 別の接続で、写真を区画に選ぶ変更を確定させずに持つ（ThemeContentService::saveDraft は選んだ写真の行を押さえる）
            config(['database.connections.tenant_other' => config('database.connections.tenant')]);
            $other = DB::connection('tenant_other');
            $other->beginTransaction();

            try {
                $other->table('shop_media')->where('id', $id)->lockForUpdate()->first();
                DB::statement("SET lock_timeout = '500ms'");

                try {
                    app(ShopMediaService::class)->remove($id);
                    $this->fail('別の操作が選んでいる途中の写真を消せてしまった');
                } catch (QueryException $e) {
                    $this->assertSame('55P03', $e->getCode()); // lock_not_available: 選んでいる側の確定を待つ
                }
            } finally {
                $other->rollBack();
                DB::statement('SET lock_timeout = 0');
                DB::purge('tenant_other');
            }

            $this->assertSame(1, ShopMedia::query()->whereKey($id)->count());
            $this->assertSame(0, DomainEvent::query()->where('type', 'shop.media_removed')->count());
        });
        $this->assertCount(2, $this->allFiles());
    }

    public function test_photos_stay_in_their_shop(): void
    {
        $this->tenant('sme');
        $this->tenant('smf');
        $this->signIn();
        $id = (string) $this->upload('sme', $this->jpeg(400, 200))->assertCreated()->json('item.id');

        // 別の店からは、ID を知っていても見えない・変えられない・消せない・区画に選べない
        $this->send('GET', '/api/tenants/smf/shop-media')->assertOk()->assertJsonPath('items', []);
        $this->send('GET', "/api/tenants/smf/shop-media/{$id}/large")->assertNotFound();
        $this->send('PATCH', "/api/tenants/smf/shop-media/{$id}", ['alt' => '乗っ取り'])->assertNotFound();
        $this->send('DELETE', "/api/tenants/smf/shop-media/{$id}")->assertNotFound();
        $this->send('PUT', '/api/tenants/smf/themes/fashion/pages/home/draft', ['version_id' => null, 'revision' => 0, 'sections' => [['type' => 'hero', 'values' => ['image' => $id]]]])->assertStatus(422);
        $this->get("http://smf.localhost/media/shop/{$id}/large")->assertNotFound();
        tenancy()->end();
        $this->get("http://localhost/media/shop/{$id}/large")->assertNotFound();

        $this->send('GET', '/api/tenants/sme/shop-media')->assertJsonPath('items.0.alt', null);
        $this->assertCount(2, $this->allFiles());
    }

    public function test_files_that_are_not_allowed_photos_and_uploads_past_the_limit_are_refused(): void
    {
        $this->tenant('smg');
        $this->signIn();

        foreach (['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', '<?php system($_GET["c"]);', $this->pngHeader(6000, 6000)] as $bytes) {
            $this->upload('smg', $bytes)->assertStatus(422)->assertJsonValidationErrors('image');
        }
        $this->send('POST', '/api/tenants/smg/shop-media')->assertStatus(422)->assertJsonValidationErrors('image');
        $this->assertSame([], $this->allFiles());

        // 上限まで置いた店は、それ以上上げられない（数はロックの中で数える）
        $this->inside('smg', function (): void {
            for ($i = 0; $i < ShopMediaService::MAX_PER_SHOP; $i++) {
                ShopMedia::create(['width' => 10, 'height' => 10, 'checksum' => str_repeat('a', 64)]);
            }
        });
        $this->upload('smg', $this->jpeg(400, 200))->assertStatus(422)->assertJsonValidationErrors('image');
        $this->assertCount(0, $this->allFiles());

        // 上げる経路には、運営ごとの回数の制限が掛かっている
        foreach (['api/tenants/{tenant}/shop-media'] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())->first(static fn ($r): bool => $r->uri() === $uri && in_array('POST', $r->methods(), true));
            $this->assertNotNull($route, $uri);
            $this->assertContains('throttle:admin-uploads', $route->gatherMiddleware(), $uri);
        }

        // 止めた運営は触れない
        $this->operator->update(['disabled_at' => now()]);
        $this->upload('smg', $this->jpeg(400, 200))->assertUnauthorized();
        $this->send('GET', '/api/tenants/smg/shop-media')->assertUnauthorized();
    }

    public function test_staff_with_read_access_can_see_the_photos_but_not_change_them(): void
    {
        $this->tenant('smi');
        $path = (string) tempnam(sys_get_temp_dir(), 'shop');
        file_put_contents($path, $this->jpeg(400, 200));
        $this->files[] = $path;
        $id = $this->inside('smi', fn (): string => app(ShopMediaService::class)->add($path, null, 'cli:test')->id);

        // 閲覧だけのスタッフ: 一覧とプレビューは見られるが、上げる・代わりの文字・消す・区画に選ぶはできない（運用の段階が要る。ADR 0024）
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        $access = app(OperatorAccess::class);
        $access->grant($staff, 'smi', 'read', 'test');
        $this->send('GET', '/api/tenants/smi/shop-media')->assertOk()->assertJsonPath('items.0.id', $id);
        $this->send('GET', "/api/tenants/smi/shop-media/{$id}/medium")->assertOk();
        $this->upload('smi', $this->jpeg(400, 200))->assertForbidden();
        $this->send('PATCH', "/api/tenants/smi/shop-media/{$id}", ['alt' => '書き換え'])->assertForbidden();
        $this->send('DELETE', "/api/tenants/smi/shop-media/{$id}")->assertForbidden();
        $this->send('PUT', '/api/tenants/smi/themes/fashion/pages/home/draft', ['version_id' => null, 'revision' => 0, 'sections' => [['type' => 'hero', 'values' => ['image' => $id]]]])->assertForbidden();
        $this->assertCount(2, $this->allFiles());

        // 運用を与えると変えられる
        $access->grant($staff, 'smi', 'operate', 'test');
        $this->send('PATCH', "/api/tenants/smi/shop-media/{$id}", ['alt' => '店の入口'])->assertOk()->assertJsonPath('item.alt', '店の入口');
        $this->send('DELETE', "/api/tenants/smi/shop-media/{$id}")->assertOk()->assertJsonPath('items', []);
    }

    /**
     * @return TestResponse<Response>
     */
    private function upload(string $tenant, string $bytes, ?string $alt = null): TestResponse
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'shop');
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        if ($this->sessionId !== null) {
            $this->withCookie((string) config('admin.session_cookie'), $this->sessionId);
        }

        $data = ['image' => new UploadedFile($path, 'photo.jpg', null, null, true)];

        if ($alt !== null) {
            $data['alt'] = $alt;
        }

        return $this->withHeaders(['Accept' => 'application/json'])->post("{$this->adminHost}/api/tenants/{$tenant}/shop-media", $data);
    }

    /**
     * @return list<string>
     */
    private function allFiles(): array
    {
        $files = array_values(array_filter($this->disk->allFiles(), static fn (string $f): bool => $f !== '.owner'));
        sort($files);

        return $files;
    }

    private function signIn(): void
    {
        [$this->operator] = $this->enrolledOperator();
    }
}
