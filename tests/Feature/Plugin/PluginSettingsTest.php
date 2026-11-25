<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Event\Models\DomainEvent;
use App\Core\Plugin\Services\PluginSettings;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App の設定（ADR 0034 の 3）。管理画面から運用の段階で、直前の再確認の後に変える。値はサーバーが宣言どおりに確かめ、
 * 記録には変えた項目の名前だけを残す。秘密の項目は暗号化して置き、応答にも記録にも中身を出さない
 */
final class PluginSettingsTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant('pst');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_settings_are_checked_on_the_server_and_secrets_stay_out_of_the_record(): void
    {
        [$owner, $authenticator] = $this->enrolledOperator();
        $url = '/api/tenants/pst/plugins/tag-sample/settings';
        $this->send('PUT', $url, ['values' => ['tag_id' => 'TS-ABC123']])->assertStatus(423);
        $this->reconfirm($owner, $authenticator);

        // 守り (3): 形の合わない ID・スクリプトの文字・長すぎる・知らない項目・型の違う値は断る
        foreach ([
            ['tag_id' => 'G-ABC123'],
            ['tag_id' => 'TS-abc123'],
            ['tag_id' => 'TS-ABC123"><script>alert(1)</script>'],
            ['tag_id' => "TS-AB\tC123"],
            ['tag_id' => 'TS-ABCDEFGHIJK'],
            ['tag_id' => ['TS-ABC123']],
            ['region' => 'mars'],
            ['send_page_views' => 'yes'],
            ['api_secret' => "short'"],
        ] as $values) {
            $this->send('PUT', $url, ['values' => $values])->assertStatus(422)->assertJsonValidationErrors('values.'.array_key_first($values));
        }
        $this->send('PUT', $url, ['values' => ['onload' => 'x']])->assertStatus(422)->assertJsonValidationErrors('values');
        // 入れるべき ID が無いまま保存はできない
        $this->send('PUT', $url, ['values' => ['region' => 'us']])->assertStatus(422)->assertJsonValidationErrors('values.tag_id');
        $this->inside('pst', fn () => $this->assertSame(0, DomainEvent::query()->where('type', 'plugin.settings_changed')->count()));

        $saved = $this->send('PUT', $url, ['values' => ['tag_id' => 'TS-ABC123', 'api_secret' => 'very-secret-key-0001', 'send_page_views' => false]])->assertOk()
            ->assertDontSee('very-secret-key-0001');
        // 一覧の中の位置は入っている App の数で変わるので、名前で探す
        $sample = $saved->collect('plugins')->firstWhere('name', 'tag-sample');
        $this->assertSame(['tag_id' => 'TS-ABC123', 'send_page_views' => false, 'region' => 'jp'], $sample['settings']['values'] ?? null);
        $this->assertSame(['api_secret' => true], $sample['settings']['secrets'] ?? null);
        // 秘密は空なら変えない（消すのは clear に書いたときだけ。秘密でない項目は消せない）
        $kept = $this->send('PUT', $url, ['values' => ['api_secret' => '', 'region' => 'us']])->assertOk();
        $this->assertTrue($kept->collect('plugins')->firstWhere('name', 'tag-sample')['settings']['secrets']['api_secret'] ?? null);
        $this->send('PUT', $url, ['values' => [], 'clear' => ['tag_id']])->assertStatus(422)->assertJsonValidationErrors('clear');

        $this->inside('pst', function () use ($owner): void {
            $events = DomainEvent::query()->where('type', 'plugin.settings_changed')->orderBy('sequence')->get();
            $this->assertSame([['api_secret', 'send_page_views', 'tag_id'], ['region']], $events->map(fn ($e) => $e->payload['changed'])->all());
            $this->assertSame($owner->actor(), $events->first()?->actor);
            $this->assertStringNotContainsString('very-secret-key-0001', (string) json_encode($events->pluck('payload')));
            // 店の DB には暗号文だけ。サーバーの中では読める
            $raw = (string) DB::table('plugin_settings')->value('secrets');
            $this->assertStringStartsWith('te1:', $raw);
            $this->assertStringNotContainsString('very-secret', $raw);
            $this->assertSame('very-secret-key-0001', app(PluginSettings::class)->secret('tag-sample', 'api_secret'));
            $this->assertSame(['tag_id' => 'TS-ABC123', 'send_page_views' => false, 'region' => 'us'], app(PluginSettings::class)->publicValues('tag-sample'));
        });
    }

    public function test_settings_with_only_a_secret_or_with_every_value_cleared_are_saved_as_an_empty_object(): void
    {
        // 秘密の項目だけを送る・秘密でない値を全部消すと、ふつうの値が空になる。空でも「項目の名前 => 値」の形（JSON の object）で置く
        // （で見つけた: 空の並び [] として置こうとして表の制約 plugin_settings_values_object に当たり 500 だった）
        [$owner, $authenticator] = $this->enrolledOperator();
        $this->reconfirm($owner, $authenticator);
        $this->send('PUT', '/api/tenants/pst/plugins/contact/settings', ['values' => ['notify_email' => 'shop@example.com']])->assertOk();
        $this->send('PUT', '/api/tenants/pst/plugins/cart-reminder/settings', ['values' => ['wait' => 'h3']])->assertOk();
        $this->send('PUT', '/api/tenants/pst/plugins/cart-reminder/settings', ['values' => ['wait' => null, 'opening' => null]])->assertOk();

        $this->inside('pst', function (): void {
            $this->assertSame(['{}', '{}'], DB::table('plugin_settings')->orderBy('plugin')->pluck('values')->map(static fn (mixed $v): string => (string) $v)->all());
            $settings = app(PluginSettings::class);
            $this->assertSame('shop@example.com', $settings->secret('contact', 'notify_email'));
            $this->assertSame(['notify_email' => true], $settings->forAdmin('contact')['secrets']);
            // 読むときは空の値（既定で埋める）
            $this->assertSame([], $settings->storedValues()['cart-reminder']);
        });
    }
}
