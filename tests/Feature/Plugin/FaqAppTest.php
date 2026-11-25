<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Plugin\Services\AdminPages;
use App\Core\Plugin\Services\PluginStates;
use Dom\HTMLDocument;
use EcSugar\PluginFaq\FaqItems;
use EcSugar\PluginFaq\Models\FaqItem;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 見本の App「よくある質問」。管理画面の App の画面で足す・直す・並べ替える・消す（ADR 0051 の口と、足す・直すの欄）と、
 * ショップの商品の画面の開いて読む並び（核の部品。4 つのテーマ）。
 * 使わない店では管理画面にもショップにも出ない。店をまたいで読めない・書けない。公開していない質問は出ない。文は HTML として出ない
 */
final class FaqAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string BASE = '/api/tenants/faa/apps/faq/records';

    private string $bag;

    private string $mug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        $this->configureAdminHost();
        $this->tenant('faa');
        [$this->bag, $this->mug] = $this->inside('faa', fn (): array => [$this->listing('帆布のトートバッグ'), $this->listing('白いマグカップ')]);
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_an_unused_app_shows_nothing_in_the_admin_or_the_shop(): void
    {
        $this->enrolledOperator();
        // 使っていない店でも表はあり（空のまま）、質問を置いても出さない
        $this->inside('faa', fn () => FaqItem::query()->create(['question' => '洗えますか', 'answer' => '手洗いできます。', 'position' => 1, 'published' => true]));

        $this->send('GET', '/api/tenants/faa/app-pages')->assertOk()->assertExactJson(['pages' => []]);
        $this->send('GET', self::BASE)->assertNotFound();
        $this->send('POST', self::BASE, ['values' => ['question' => 'x', 'answer' => 'y']])->assertNotFound();
        $this->assertStringNotContainsString('product-panel-faq', $this->product('faa', $this->bag));

        $this->inside('faa', fn () => app(PluginStates::class)->set('faq', true, 'test'));
        $this->send('GET', '/api/tenants/faa/app-pages')->assertOk()->assertJsonFragment(['plugin' => 'faq', 'title' => 'よくある質問']);
        $this->assertStringContainsString('product-panel-faq', $this->product('faa', $this->bag));
    }

    public function test_the_shop_owner_adds_edits_and_the_product_page_shows_shared_and_own_questions_only(): void
    {
        $this->inside('faa', fn () => app(PluginStates::class)->set('faq', true, 'test'));
        [$owner] = $this->enrolledOperator();

        // 一覧の答えに、足す欄（最初の値つき）が付く。商品は選択肢から（全部の商品が先頭で既定）
        $list = $this->send('GET', self::BASE)->assertOk();
        $list->assertJsonPath('form.label', '質問を追加')->assertJsonPath('form.values', ['question' => null, 'answer' => null, 'listing' => 'all', 'published' => true]);
        $this->assertSame(['all', 'すべての商品（共通）'], $list->json('form.fields.2.options.0'));
        $this->assertContains([$this->bag, '帆布のトートバッグ'], $list->json('form.fields.2.options'));

        $shared = $this->create(['question' => '<b>洗えますか</b>', 'answer' => "手洗いできます。\r\n<script>alert(1)</script>", 'listing' => 'all']);
        $own = $this->create(['question' => 'トートの大きさは', 'answer' => '縦 40cm です。', 'listing' => $this->bag]);
        $other = $this->create(['question' => 'マグは電子レンジに', 'answer' => '使えます。', 'listing' => $this->mug]);
        $hidden = $this->create(['question' => '隠した質問', 'answer' => 'まだ出さない。', 'listing' => 'all', 'published' => false]);

        // 一覧は並びの順。足した人の印つきで記録に残り、記録に質問と答えの文は入らない
        $this->assertSame([$shared, $own, $other, $hidden], array_column($this->send('GET', self::BASE)->assertOk()->json('rows'), 'id'));
        $this->send('GET', self::BASE.'?filter=hidden')->assertOk()->assertJsonPath('rows.0.id', $hidden)->assertJsonCount(1, 'rows');
        $events = $this->inside('faa', fn () => DomainEvent::query()->where('type', 'faq.created')->get());
        $this->assertCount(4, $events);
        $this->assertSame($owner->actor(), $events[0]->actor);
        $this->assertStringNotContainsString('洗えますか', json_encode($events->pluck('payload')->all(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        // 改行は \n にそろえて保存する
        $this->assertSame("手洗いできます。\n<script>alert(1)</script>", $this->inside('faa', fn () => FaqItem::query()->findOrFail($shared)->answer));

        // 商品の画面: 全部の商品の質問とこの商品の質問だけ（ほかの商品の質問・公開していない質問は出ない）。文はエスケープされ、<details> で開閉する
        $html = $this->product('faa', $this->bag);
        $panel = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('[data-test="product-panel-faq"]');
        $this->assertNotNull($panel);
        $this->assertSame(['<b>洗えますか</b>', 'トートの大きさは'], array_map(static fn ($s) => $s->textContent, iterator_to_array($panel->querySelectorAll('details > summary'))));
        $this->assertNull($panel->querySelector('script, b'));
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('マグは電子レンジに', $html);
        $this->assertStringNotContainsString('隠した質問', $html);
        $this->assertStringContainsString('マグは電子レンジに', $this->product('faa', $this->mug));

        // 1 件の画面に直す欄（今の値）。直すと、変えた欄の名前だけが記録に残る
        $this->send('GET', self::BASE.'/'.$hidden)->assertOk()->assertJsonPath('form.values.published', false)->assertJsonPath('record.title', '隠した質問');
        $this->send('PUT', self::BASE.'/'.$hidden, ['values' => ['published' => true, 'listing' => $this->bag]])->assertOk()
            ->assertJsonPath('form.values', ['question' => '隠した質問', 'answer' => 'まだ出さない。', 'listing' => $this->bag, 'published' => true]);
        $updated = $this->inside('faa', fn () => DomainEvent::query()->where('type', 'faq.updated')->sole());
        $this->assertSame(['listing_id', 'published'], $updated->payload['changed']);
        $this->assertStringContainsString('隠した質問', $this->product('faa', $this->bag));
    }

    public function test_values_are_checked_by_the_core_and_the_app(): void
    {
        $this->inside('faa', fn () => app(PluginStates::class)->set('faq', true, 'test'));
        $this->enrolledOperator();

        $this->send('POST', self::BASE, ['values' => ['answer' => '答えだけ']])->assertUnprocessable()->assertJsonValidationErrors(['values.question']);
        $this->send('POST', self::BASE, ['values' => ['question' => "1 行目\n2 行目", 'answer' => 'a']])->assertUnprocessable()->assertJsonValidationErrors(['values.question']);
        $this->send('POST', self::BASE, ['values' => ['question' => 'q', 'answer' => str_repeat('あ', FaqItems::ANSWER_MAX + 1)]])->assertUnprocessable()->assertJsonValidationErrors(['values.answer']);
        $this->send('POST', self::BASE, ['values' => ['question' => 'q', 'answer' => 'a', 'listing' => '01JBQ0000000000000000000ZZ']])->assertUnprocessable()->assertJsonValidationErrors(['values.listing']);
        $this->send('POST', self::BASE, ['values' => ['question' => 'q', 'answer' => 'a', 'published' => 'yes']])->assertUnprocessable()->assertJsonValidationErrors(['values.published']);
        $this->send('POST', self::BASE, ['values' => ['question' => 'q', 'answer' => 'a', 'position' => 1]])->assertUnprocessable()->assertJsonValidationErrors(['values']);
        $this->send('POST', self::BASE, ['values' => ['question' => "見えない\u{202E}文字", 'answer' => 'a']])->assertUnprocessable()->assertJsonValidationErrors(['values.question']);
        $this->assertSame(0, $this->inside('faa', fn () => FaqItem::query()->count()));

        // 店に置ける数の上限（App の決まり。誤りは欄の全体に）
        $this->inside('faa', function (): void {
            $rows = [];

            for ($i = 1; $i <= FaqItems::MAX_ITEMS; $i++) {
                $rows[] = ['id' => strtolower((string) Str::ulid()), 'question' => "q{$i}", 'answer' => 'a', 'position' => $i, 'published' => true, 'created_at' => now(), 'updated_at' => now()];
            }

            DB::table('faq_items')->insert($rows);
        });
        $this->send('POST', self::BASE, ['values' => ['question' => 'q', 'answer' => 'a']])->assertUnprocessable()->assertJsonValidationErrors(['values']);
    }

    public function test_reordering_moves_one_step_and_is_not_limited_per_hour(): void
    {
        $this->inside('faa', fn () => app(PluginStates::class)->set('faq', true, 'test'));
        $this->enrolledOperator();
        $a = $this->create(['question' => 'A', 'answer' => 'a']);
        $b = $this->create(['question' => 'B', 'answer' => 'b']);
        $c = $this->create(['question' => 'C', 'answer' => 'c']);

        // 端の質問には、その向きの操作が無い（押しても 404）
        $this->assertSame(['move_down', 'delete'], array_column($this->send('GET', self::BASE.'/'.$a)->json('record.actions'), 'key'));
        $this->send('POST', self::BASE.'/'.$a.'/actions/move_up')->assertNotFound();

        // 並べ替えはくり返すのがふつうなので、1 時間の回数の上限（6 回）を数えない
        for ($i = 0; $i < AdminPages::ACTIONS_PER_HOUR + 2; $i++) {
            $this->send('POST', self::BASE.'/'.$c.'/actions/'.($i % 2 === 0 ? 'move_up' : 'move_down'))->assertOk();
        }

        $this->send('POST', self::BASE.'/'.$c.'/actions/move_up')->assertOk()->assertJsonPath('record.facts.3.value', 2);
        $this->assertSame([$a, $c, $b], array_column($this->send('GET', self::BASE)->json('rows'), 'id'));
        $this->send('POST', self::BASE.'/'.$c.'/actions/move_up')->assertOk();
        $this->assertSame([$c, $a, $b], array_column($this->send('GET', self::BASE)->json('rows'), 'id'));

        $summaries = HTMLDocument::createFromString($this->product('faa', $this->bag), LIBXML_NOERROR)->querySelectorAll('[data-test="product-panel-faq"] summary');
        $this->assertSame(['C', 'A', 'B'], array_map(static fn ($s) => $s->textContent, iterator_to_array($summaries)));
    }

    public function test_deleting_needs_the_recent_confirmation_and_the_operate_level(): void
    {
        $this->inside('faa', fn () => app(PluginStates::class)->set('faq', true, 'test'));
        [$owner, $key] = $this->enrolledOperator();
        $id = $this->create(['question' => '消す質問', 'answer' => 'a']);

        // 消すのは取り消せないので、直前の再確認の前は 423
        $this->send('POST', self::BASE.'/'.$id.'/actions/delete')->assertStatus(423);
        $this->reconfirm($owner, $key);
        $this->send('POST', self::BASE.'/'.$id.'/actions/delete')->assertOk()->assertExactJson(['record' => null, 'form' => null]);
        $this->assertSame(0, $this->inside('faa', fn () => FaqItem::query()->count()));
        $this->assertSame(1, $this->inside('faa', fn () => DomainEvent::query()->where('type', 'faq.deleted')->where('aggregate_id', $id)->count()));
        $this->send('GET', self::BASE.'/'.$id)->assertNotFound();
        $this->send('PUT', self::BASE.'/'.$id, ['values' => ['question' => 'x']])->assertNotFound();

        // 閲覧の段階の人は見られるが、足す・直す・並べ替えはできない
        $kept = $this->create(['question' => '残す質問', 'answer' => 'a']);
        // 別の人として入り直す（オーナーのセッションを持ち越さない）
        $this->sessionId = null;
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'faa', 'read', 'test');
        $this->send('GET', self::BASE.'/'.$kept)->assertOk();
        $this->send('POST', self::BASE, ['values' => ['question' => 'x', 'answer' => 'y']])->assertForbidden();
        $this->send('PUT', self::BASE.'/'.$kept, ['values' => ['question' => 'x']])->assertForbidden();
        $this->send('POST', self::BASE.'/'.$kept.'/actions/move_down')->assertForbidden();
        $this->assertSame('残す質問', $this->inside('faa', fn () => FaqItem::query()->findOrFail($kept)->question));
    }

    public function test_another_shop_can_neither_read_nor_write_the_questions_or_point_at_its_products(): void
    {
        $this->tenant('fab');

        foreach (['faa', 'fab'] as $shop) {
            $this->inside($shop, fn () => app(PluginStates::class)->set('faq', true, 'test'));
        }

        [$owner, $key] = $this->enrolledOperator();
        $this->reconfirm($owner, $key);
        $mine = $this->create(['question' => 'A の店の質問', 'answer' => 'a', 'listing' => $this->bag]);
        $theirs = $this->inside('fab', fn (): string => $this->listing('B の店の商品'));

        // B の店の経路から A の店の質問は無いのと同じ（読む・直す・操作・消す）
        $other = '/api/tenants/fab/apps/faq/records';
        $this->send('GET', $other)->assertOk()->assertJsonPath('meta.total', 0);
        $this->send('GET', "{$other}/{$mine}")->assertNotFound();
        $this->send('PUT', "{$other}/{$mine}", ['values' => ['question' => '書き換え']])->assertNotFound();
        $this->send('POST', "{$other}/{$mine}/actions/delete")->assertNotFound();
        $this->assertSame('A の店の質問', $this->inside('faa', fn () => FaqItem::query()->findOrFail($mine)->question));

        // A の店の質問に B の店の商品は選べない（選択肢に無い）。B の店の商品の画面にも A の店の質問は出ない
        $this->send('PUT', self::BASE.'/'.$mine, ['values' => ['listing' => $theirs]])->assertUnprocessable()->assertJsonValidationErrors(['values.listing']);
        $this->assertNotContains($theirs, array_column($this->send('GET', self::BASE)->json('form.fields.2.options'), 0));
        $this->assertStringNotContainsString('A の店の質問', $this->product('fab', $theirs));
    }

    public function test_every_official_theme_puts_the_questions_under_the_description(): void
    {
        $this->inside('faa', fn () => app(PluginStates::class)->set('faq', true, 'test'));
        $this->enrolledOperator();
        $this->create(['question' => '洗えますか', 'answer' => "手洗いできます。\n陰干ししてください。"]);
        $tenant = $this->tenants[0];

        foreach (['default', 'kinari', 'fashion', 'botanica'] as $theme) {
            $tenant->forceFill(['theme' => $theme])->save();
            $document = HTMLDocument::createFromString($this->product('faa', $this->bag), LIBXML_NOERROR);
            $panel = $document->querySelector('section[aria-labelledby="panel-faq-title"] [data-product-panel="faq"]');
            $this->assertNotNull($panel, "{$theme} に出る");
            $this->assertSame('よくある質問', $document->querySelector('#panel-faq-title')?->textContent, $theme);
            $this->assertSame("手洗いできます。\n陰干ししてください。", $panel->querySelector('details [data-product-panel-body]')?->textContent, $theme);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function create(array $values): string
    {
        $id = $this->send('POST', self::BASE, ['values' => $values])->assertCreated()->json('id');
        $this->assertIsString($id);

        return $id;
    }

    private function listing(string $title): string
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 4900, 'status' => ListingStatus::Active])->id;
    }

    private function product(string $shop, string $listingId): string
    {
        /** @var TestResponse<Response> $response */
        $response = $this->get("http://{$shop}.localhost/products/{$listingId}");
        $html = (string) $response->assertOk()->getContent();
        tenancy()->end();

        return $html;
    }
}
