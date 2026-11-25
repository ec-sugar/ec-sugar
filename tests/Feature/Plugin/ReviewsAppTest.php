<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Services\OperatorAccess;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberCookie;
use App\Storefront\Orders\OrderReader;
use Dom\HTMLDocument;
use EcSugar\PluginReviews\Http\ReviewController;
use EcSugar\PluginReviews\Models\Review;
use EcSugar\PluginReviews\ReviewInput;
use EcSugar\PluginReviews\ReviewLinks;
use EcSugar\PluginReviews\ReviewProductPanel;
use EcSugar\PluginReviews\Reviews;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「商品レビュー」。書けるのは買った人だけ（発送済みか完了の注文の商品。会員はマイページ、ゲストはご注文の確認の署名つきの道筋から）で、
 * 1 つの注文の 1 つの商品に 1 つ。公開は店が決める（既定は店が公開するまで出さない）。ショップは商品の画面の説明の下に平均の星と件数とレビュー（4 つのテーマ）。
 * 文はエスケープし、制御文字と向きの印を断る。書く回数の上限・店の境界・権限・名前とメールを出さないこと
 */
final class ReviewsAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string BASE = '/api/tenants/rva/apps/reviews/records';

    private string $bag;

    private string $mug;

    private ?string $member = null;

    private string $ip = '127.0.0.1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        $this->configureAdminHost();
        $this->tenant('rva');
        [$this->bag, $this->mug] = $this->inside('rva', function (): array {
            app(PluginStates::class)->set('reviews', true, 'test');

            return [$this->listing('帆布のトートバッグ'), $this->listing('白いマグカップ')];
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_a_guest_writes_once_per_product_from_the_order_page_and_it_waits_for_the_shop(): void
    {
        $order = $this->order('rva', [$this->bag, $this->mug]);

        // ご注文の確認（確認のメールの署名つきのリンク）に「レビューを書く」が出て、署名つきの道筋でその注文のレビューの画面へ
        $page = $this->guestOrderPage('rva', $order);
        $link = $page->querySelector('[data-test="order-link-reviews"]')?->getAttribute('href');
        $this->assertIsString($link);
        $this->assertStringStartsWith("/p/reviews/orders/{$order}?", $link);
        $list = $this->html($this->shop('rva', 'GET', $link)->assertOk());
        $writes = array_map(static fn ($a) => (string) $a->getAttribute('href'), iterator_to_array($list->querySelectorAll('[data-test="reviews-write"]')));
        $this->assertCount(2, $writes);

        // 書く。題・本文・表示名だけで、名前やメールは出さない。記録には文を入れない
        $form = $this->html($this->shop('rva', 'GET', $writes[0])->assertOk());
        $this->assertSame('帆布のトートバッグ', $form->querySelector('[data-test="reviews-listing"]')?->textContent);
        $action = (string) $form->querySelector('[data-test="reviews-form"] form')?->getAttribute('action');
        $this->shop('rva', 'POST', $action, ['rating' => '5', 'title' => 'ちょうどいい大きさ', 'body' => "毎日使っています。\r\n丈夫です。", 'display_name' => ''])
            ->assertRedirect()->assertSessionHas('reviews_message', fn (string $m): bool => str_contains($m, 'ショップが内容を確認してから'));
        $review = $this->inside('rva', fn (): Review => Review::query()->sole());
        $this->assertSame(['pending', 5, "毎日使っています。\n丈夫です。", '購入者', $this->bag], [$review->status, $review->rating, $review->body, $review->display_name, $review->listing_id]);
        $event = $this->inside('rva', fn (): DomainEvent => DomainEvent::query()->where('type', 'review.written')->sole());
        $this->assertSame('storefront', $event->actor);
        $this->assertStringNotContainsString('毎日使っています', json_encode($event->payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        // 1 つの注文の 1 つの商品に 1 つ（もう一度送っても増えない。書く欄も開かない）
        $this->shop('rva', 'POST', $action, ['rating' => '1', 'title' => '二度目', 'body' => '二度目'])->assertSessionHas('reviews_message', fn (string $m): bool => str_contains($m, 'すでに投稿いただいて'));
        $this->shop('rva', 'GET', $writes[0])->assertRedirect();
        $this->assertSame(1, $this->inside('rva', fn (): int => Review::query()->count()));
        $after = $this->html($this->shop('rva', 'GET', $link)->assertOk());
        $this->assertCount(1, $after->querySelectorAll('[data-test="reviews-write"]'));
        $this->assertSame('投稿済み（ショップで確認中）', $after->querySelector('[data-test="reviews-state"]')?->textContent);

        // 店が公開するまで、商品の画面に出ない
        $this->assertStringNotContainsString('product-panel-reviews', $this->product('rva', $this->bag));
    }

    public function test_only_the_buyer_of_a_shipped_order_can_write(): void
    {
        $unpaid = $this->order('rva', [$this->bag], paid: false);
        $paid = $this->order('rva', [$this->bag], ship: false);
        $shipped = $this->order('rva', [$this->bag]);
        $admin = $this->order('rva', [$this->bag], storefront: false);
        $valid = ['rating' => '4', 'title' => 'よい', 'body' => 'よい品です。'];

        // 払う前・発送の前の注文には、ご注文の確認に入口が出ず、署名つきの道筋を作って送っても書けない
        foreach ([$unpaid, $paid] as $order) {
            $this->assertNull($this->guestOrderPage('rva', $order)->querySelector('[data-test="order-link-reviews"]'));
            $this->shop('rva', 'POST', $this->signedForm('rva', $order, $this->bag), $valid)->assertSessionHas('reviews_message', fn (string $m): bool => str_contains($m, 'まだレビューを書けません'));
        }

        // 注文に無い商品には書けない
        $this->shop('rva', 'POST', $this->signedForm('rva', $shipped, $this->mug), $valid)->assertSessionHas('reviews_message', fn (string $m): bool => str_contains($m, '注文の商品ではありません'));

        // 署名の無い・書き換えた・期限の切れた道筋、ショップの注文でない取引は、注文があるかを教えずにご注文の確認へ
        $signed = $this->signedForm('rva', $shipped, $this->bag);
        // 署名つきの書く画面は、移った先の参照元に署名を残さない（同じショップの次の画面の外のタグから読まれないように）
        $this->shop('rva', 'GET', $signed)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->shop('rva', 'POST', "/p/reviews/orders/{$shipped}/{$this->bag}", $valid)->assertRedirect('http://rva.localhost/orders');
        $this->shop('rva', 'POST', (string) preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $signed), $valid)->assertRedirect('http://rva.localhost/orders');
        $this->shop('rva', 'POST', str_replace($shipped, $paid, $signed), $valid)->assertRedirect('http://rva.localhost/orders');
        $this->shop('rva', 'POST', $this->signedForm('rva', $admin, $this->bag), $valid)->assertRedirect('http://rva.localhost/orders');
        $this->travel(25)->hours();
        $this->shop('rva', 'POST', $signed, $valid)->assertRedirect('http://rva.localhost/orders')->assertSessionHas('cart_error', fn (string $m): bool => str_contains($m, '期限が切れました'));

        $this->assertSame(0, $this->inside('rva', fn (): int => Review::query()->count()));

        // DB も 1 つの注文の 1 つの商品に 1 つ・星は 1〜5 を守る（アプリの確かめをすり抜けても）
        $this->inside('rva', function () use ($shipped): void {
            $row = ['transaction_id' => $shipped, 'listing_id' => $this->bag, 'rating' => 5, 'title' => 't', 'body' => 'b', 'display_name' => 'n', 'status' => 'pending'];
            Review::query()->create($row);

            foreach ([$row, ['rating' => 6, 'listing_id' => $this->mug] + $row, ['title' => null, 'listing_id' => $this->mug] + $row] as $bad) {
                try {
                    DB::transaction(static fn () => Review::query()->create($bad));
                    self::fail('DB が通した');
                } catch (QueryException) {
                    // 断られる
                }
            }
        });
    }

    public function test_a_member_writes_from_my_page_and_cannot_reach_someone_elses_order(): void
    {
        $mine = $this->order('rva', [$this->bag], email: 'hanako@example.com');
        $theirs = $this->order('rva', [$this->mug], email: 'taro@example.com');

        // ログインの前はログインへ
        $this->shop('rva', 'GET', '/p/reviews/account')->assertRedirect('http://rva.localhost/account/login?return=%2Fp%2Freviews%2Faccount');
        $this->signIn('rva', 'hanako@example.com');
        $this->assertStringContainsString('/p/reviews/account', (string) $this->shop('rva', 'GET', '/account')->assertOk()->getContent());

        // マイページのレビュー: 自分の注文だけ。書く欄は署名なしの道筋
        $list = $this->html($this->shop('rva', 'GET', '/p/reviews/account')->assertOk());
        $writes = array_map(static fn ($a) => (string) $a->getAttribute('href'), iterator_to_array($list->querySelectorAll('[data-test="reviews-write"]')));
        $this->assertSame(["/p/reviews/orders/{$mine}/{$this->bag}"], $writes);
        $this->shop('rva', 'POST', $writes[0], ['rating' => '3', 'title' => 'ふつう', 'body' => 'ふつうです。', 'display_name' => 'はな'])->assertRedirect("http://rva.localhost/p/reviews/orders/{$mine}");

        // ほかの人の注文は、署名なしでは開けない・書けない
        $this->shop('rva', 'GET', "/p/reviews/orders/{$theirs}")->assertRedirect('http://rva.localhost/orders');
        $this->shop('rva', 'POST', "/p/reviews/orders/{$theirs}/{$this->mug}", ['rating' => '1', 'title' => 'x', 'body' => 'x'])->assertRedirect('http://rva.localhost/orders');
        $this->assertSame([$mine], $this->inside('rva', fn (): array => Review::query()->pluck('transaction_id')->all()));
    }

    public function test_the_shop_publishes_replies_and_deletes_and_the_product_page_shows_the_average(): void
    {
        [$owner, $key] = $this->enrolledOperator();
        $ids = [];

        foreach ([[5, '最高'], [4, 'よい'], [4, '<b>太字</b>'], [1, '隠す']] as [$rating, $title]) {
            $ids[] = $this->write($this->order('rva', [$this->bag], email: "buyer{$rating}{$title}@example.com"), $this->bag, $rating, $title);
        }

        // 足す欄は無い（書くのはお客さんだけ）。一覧の既定は確認待ちで、ホームのやることに件数
        $this->send('GET', self::BASE)->assertOk()->assertJsonPath('form', null)->assertJsonPath('filter', 'pending')->assertJsonPath('meta.total', 4);
        $this->send('POST', self::BASE, ['values' => ['reply' => 'x']])->assertNotFound();
        $this->assertContains(['plugin' => 'reviews', 'filter' => 'pending', 'label' => '確認待ちのレビュー', 'description' => 'お客さんが書いたレビューです。読んで、公開するか決めてください。', 'count' => 4, 'tone' => 'warn'],
            $this->send('GET', '/api/tenants/rva/overview')->assertOk()->json('app_todo'));

        // 1 件の画面: 本文の全部・注文へのリンク。名前やメールは出さない
        $record = $this->send('GET', self::BASE.'/'.$ids[0])->assertOk();
        $record->assertJsonPath('record.title', '最高')->assertJsonPath('form.values', ['reply' => null]);
        $this->assertSame(['publish', 'hide', 'delete'], array_column($record->json('record.actions'), 'key'));
        $this->assertStringNotContainsString('buyer', json_encode($record->json(), JSON_THROW_ON_ERROR));

        // 公開する（3 つ）・非公開にする（1 つ）。公開は記録に残る
        foreach ([$ids[0], $ids[1], $ids[2]] as $id) {
            $this->send('POST', self::BASE.'/'.$id.'/actions/publish')->assertOk()->assertJsonPath('record.facts.0.value.label', '公開');
        }
        $this->send('POST', self::BASE.'/'.$ids[3].'/actions/hide')->assertOk();
        $this->assertSame($owner->actor(), $this->inside('rva', fn () => DomainEvent::query()->where('type', 'review.published')->where('aggregate_id', $ids[0])->value('actor')));

        // 返信（直すだけの欄）
        $this->send('PUT', self::BASE.'/'.$ids[0], ['values' => ['reply' => "ありがとうございます。\n<i>また</i>どうぞ。"]])->assertOk()->assertJsonPath('form.values.reply', "ありがとうございます。\n<i>また</i>どうぞ。");

        // 商品の画面: 公開した 3 つの平均（(5+4+4)/3 = 4.33… → 4.3）と件数。文はエスケープ。非公開は出ない。表示名と日付だけ
        $html = $this->product('rva', $this->bag);
        $panel = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('[data-test="product-panel-reviews"]');
        $this->assertNotNull($panel);
        $this->assertSame('4.3', $panel->querySelector('[data-test="product-panel-average"]')?->textContent);
        $this->assertSame('（3 件）', $panel->querySelector('[data-test="product-panel-count"]')?->textContent);
        $this->assertSame('5 段階中 4.3', $panel->querySelector('[data-product-panel-rating] [role="img"]')?->getAttribute('aria-label'));
        $this->assertCount(3, $panel->querySelectorAll('details[open]'));
        $this->assertNull($panel->querySelector('b, i, script'));
        $this->assertStringContainsString('&lt;b&gt;太字&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('隠す', $html);
        $this->assertStringNotContainsString('buyer', $html);
        $this->assertSame("ありがとうございます。\n<i>また</i>どうぞ。", $panel->querySelector('[data-product-panel-reply-body]')?->textContent);
        $this->assertStringStartsWith('購入者・', (string) $panel->querySelector('[data-product-panel-meta]')?->textContent);
        $this->assertStringNotContainsString('product-panel-reviews', $this->product('rva', $this->mug));

        // 消すのは取り消せないので直前の再確認の後。消すと中身を残さず、ショップからも消え、同じ注文の同じ商品にはもう書けない
        $this->send('POST', self::BASE.'/'.$ids[0].'/actions/delete')->assertStatus(423);
        $this->reconfirm($owner, $key);
        $this->send('POST', self::BASE.'/'.$ids[0].'/actions/delete')->assertOk()->assertExactJson(['record' => null, 'form' => null]);
        $this->send('GET', self::BASE.'/'.$ids[0])->assertNotFound();
        $deleted = $this->inside('rva', fn (): Review => Review::query()->findOrFail($ids[0]));
        $this->assertSame(['deleted', null, null, null], [$deleted->status, $deleted->title, $deleted->body, $deleted->reply]);
        $this->assertStringNotContainsString('最高', $this->product('rva', $this->bag));
        $this->assertSame('（2 件）', HTMLDocument::createFromString($this->product('rva', $this->bag), LIBXML_NOERROR)->querySelector('[data-test="product-panel-count"]')?->textContent);
        $this->assertSame(1, $this->inside('rva', fn (): int => Review::query()->where('transaction_id', $deleted->transaction_id)->count()));
    }

    public function test_the_immediate_setting_publishes_at_once(): void
    {
        $this->inside('rva', fn () => app(PluginSettings::class)->update('reviews', ['publish' => 'immediate'], 'test'));
        $order = $this->order('rva', [$this->bag]);

        $this->shop('rva', 'POST', $this->signedForm('rva', $order, $this->bag), ['rating' => '2', 'title' => 'すぐ出る', 'body' => 'すぐ出ます。'])
            ->assertSessionHas('reviews_message', fn (string $m): bool => str_contains($m, '商品ページに掲載しました'));
        $this->assertStringContainsString('すぐ出る', $this->product('rva', $this->bag));
    }

    public function test_text_rules_refuse_control_and_direction_characters_and_long_or_email_like_values(): void
    {
        $order = $this->order('rva', [$this->bag]);
        $form = $this->signedForm('rva', $order, $this->bag);
        $valid = ['rating' => '5', 'title' => 'タイトル', 'body' => '本文', 'display_name' => 'はな'];

        foreach ([
            'rating' => ['rating' => '6'],
            'title' => ['title' => "改行\nあり"],
            'title ' => ['title' => "向き\u{202E}を変える"],
            'title  ' => ['title' => str_repeat('あ', ReviewInput::TITLE_MAX + 1)],
            'body' => ['body' => "制御\u{0007}文字"],
            'body ' => ['body' => "幅の無い\u{200B}空白"],
            'body  ' => ['body' => str_repeat('あ', ReviewInput::BODY_MAX + 1)],
            'body   ' => ['body' => '   '],
            'display_name' => ['display_name' => 'hanako@example.com'],
            'display_name ' => ['display_name' => str_repeat('あ', ReviewInput::NAME_MAX + 1)],
        ] as $field => $override) {
            $this->shop('rva', 'POST', $form, $override + $valid)->assertSessionHasErrors(trim($field));
        }

        $this->assertSame(0, $this->inside('rva', fn (): int => Review::query()->count()));
        // 配列などの形の違う値は欄の誤りで、書く欄を描き直しても落ちない
        $this->ip = '203.0.113.9';
        $this->shop('rva', 'POST', $form, ['title' => ['<b>'], 'body' => ['x']] + $valid)->assertSessionHasErrors('title');
        $this->shop('rva', 'GET', $form)->assertOk();
        // 絵文字（つなぐ文字を含む）と全角の空白で囲んだ表示名は通す（上の 10 回で書く回数の上限に届いたので、別の接続元から）
        $this->ip = '203.0.113.8';
        $this->shop('rva', 'POST', $form, ['body' => "家族で使っています 👨\u{200D}👩\u{200D}👧", 'display_name' => '　はな　'] + $valid)->assertSessionHasNoErrors();
        $this->assertSame('はな', $this->inside('rva', fn () => Review::query()->sole()->display_name));
    }

    public function test_writing_is_limited_per_connection(): void
    {
        $order = $this->order('rva', [$this->bag]);
        $form = $this->signedForm('rva', $order, $this->bag);

        // 断られた回も数える（欄の誤りで総当たりさせない）
        for ($i = 0; $i < ReviewController::WRITES_PER_HOUR; $i++) {
            $this->shop('rva', 'POST', $form, ['rating' => '9'])->assertSessionHasErrors('rating');
        }

        $this->shop('rva', 'POST', $form, ['rating' => '5', 'title' => '題', 'body' => '本文'])->assertSessionHas('reviews_message', fn (string $m): bool => str_contains($m, '何度も投稿されています'));
        $this->assertSame(0, $this->inside('rva', fn (): int => Review::query()->count()));

        // ほかの接続元は数えない
        $this->ip = '203.0.113.7';
        $this->shop('rva', 'POST', $form, ['rating' => '5', 'title' => 'タイトル', 'body' => '本文'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->inside('rva', fn (): int => Review::query()->count()));
    }

    public function test_another_shop_can_neither_read_nor_write_and_a_link_does_not_cross_shops(): void
    {
        $this->tenant('rvb');
        $theirs = $this->inside('rvb', function (): string {
            app(PluginStates::class)->set('reviews', true, 'test');

            return $this->listing('B の店の商品');
        });
        [$owner, $key] = $this->enrolledOperator();
        $this->reconfirm($owner, $key);
        $order = $this->order('rva', [$this->bag]);
        $id = $this->write($order, $this->bag, 5, 'A の店のレビュー');

        // B の店の管理画面から、A の店のレビューは無いのと同じ
        $other = '/api/tenants/rvb/apps/reviews/records';
        $this->send('GET', $other)->assertOk()->assertJsonPath('meta.total', 0);
        $this->send('GET', "{$other}/{$id}")->assertNotFound();
        $this->send('POST', "{$other}/{$id}/actions/publish")->assertNotFound();
        $this->send('PUT', "{$other}/{$id}", ['values' => ['reply' => '書き換え']])->assertNotFound();
        $this->send('POST', "{$other}/{$id}/actions/delete")->assertNotFound();
        $this->assertSame('pending', $this->inside('rva', fn () => Review::query()->findOrFail($id)->status));

        // A の店の署名つきの道筋を B の店のドメインで使っても通らない（署名に店の ID が入る）
        $this->shop('rvb', 'GET', $this->signedForm('rva', $order, $this->bag))->assertRedirect('http://rvb.localhost/orders');
        $this->send('POST', self::BASE.'/'.$id.'/actions/publish')->assertOk();
        $this->assertStringNotContainsString('A の店のレビュー', $this->product('rvb', $theirs));
        $this->assertStringContainsString('A の店のレビュー', $this->product('rva', $this->bag));
    }

    public function test_an_unused_app_shows_nothing_and_the_read_level_cannot_act(): void
    {
        $order = $this->order('rva', [$this->bag]);
        $id = $this->write($order, $this->bag, 5, '見せる');
        $this->inside('rva', fn () => app(Reviews::class)->publish($id, 'test'));

        // 使うのをやめると、管理画面にもショップにも出ない（書いたレビューは残る）
        $this->inside('rva', fn () => app(PluginStates::class)->set('reviews', false, 'test'));
        [$owner] = $this->enrolledOperator();
        $this->send('GET', self::BASE)->assertNotFound();
        $this->assertStringNotContainsString('product-panel-reviews', $this->product('rva', $this->bag));
        $this->assertNull($this->guestOrderPage('rva', $order)->querySelector('[data-test="order-link-reviews"]'));
        $this->shop('rva', 'GET', '/p/reviews/account')->assertNotFound();
        $this->inside('rva', fn () => app(PluginStates::class)->set('reviews', true, 'test'));

        // 閲覧の段階の人は見られるが、公開・非公開・返信はできない
        $this->sessionId = null;
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'rva', 'read', 'test');
        $this->send('GET', self::BASE.'/'.$id)->assertOk();
        $this->send('POST', self::BASE.'/'.$id.'/actions/hide')->assertForbidden();
        $this->send('PUT', self::BASE.'/'.$id, ['values' => ['reply' => 'x']])->assertForbidden();
        $this->assertSame('published', $this->inside('rva', fn () => Review::query()->findOrFail($id)->status));
    }

    public function test_every_official_theme_shows_the_reviews_and_the_order_link(): void
    {
        $order = $this->order('rva', [$this->bag]);
        $id = $this->write($order, $this->bag, 4, 'どのテーマでも');
        $this->inside('rva', fn () => app(Reviews::class)->publish($id, 'test'));
        $tenant = $this->tenants[0];

        foreach (['default', 'kinari', 'fashion', 'botanica'] as $theme) {
            $tenant->forceFill(['theme' => $theme])->save();
            $document = HTMLDocument::createFromString($this->product('rva', $this->bag), LIBXML_NOERROR);
            $panel = $document->querySelector('section[aria-labelledby="panel-reviews-title"] [data-product-panel="reviews"]');
            $this->assertNotNull($panel, "{$theme} に出る");
            $this->assertSame('レビュー', $document->querySelector('#panel-reviews-title')?->textContent, $theme);
            $this->assertSame('5 段階中 4', $panel->querySelector('summary [role="img"]')?->getAttribute('aria-label'), $theme);
            $this->assertSame('どのテーマでも', $panel->querySelector('[data-product-panel-title]')?->textContent, $theme);
            $this->assertNotNull($this->guestOrderPage('rva', $order)->querySelector('[data-order-links] [data-test="order-link-reviews"]'), "{$theme} の注文の画面");
        }
    }

    public function test_the_average_is_rounded_without_floats(): void
    {
        $this->assertSame([43, 43, 45, 10, 50], [
            ReviewProductPanel::tenths(13, 3), // 4.333…
            ReviewProductPanel::tenths(17, 4), // 4.25 → 4.3（四捨五入）
            ReviewProductPanel::tenths(9, 2),  // 4.5
            ReviewProductPanel::tenths(1, 1),
            ReviewProductPanel::tenths(5, 1),
        ]);
    }

    /**
     * 書いたレビューの ID（お客さんの係を直に呼ぶ。店の文脈の中で）
     */
    private function write(string $order, string $listing, int $rating, string $title): string
    {
        return $this->inside('rva', function () use ($order, $listing, $rating, $title): string {
            $input = ReviewInput::from((string) $rating, $title, "{$title}の本文", null);
            $this->assertInstanceOf(ReviewInput::class, $input);

            return app(Reviews::class)->write($order, $listing, $input)->id;
        });
    }

    /**
     * @param  list<string>  $listings
     */
    private function order(string $shop, array $listings, string $email = 'hanako@example.com', bool $paid = true, bool $ship = true, bool $storefront = true): string
    {
        return $this->inside($shop, function () use ($listings, $email, $paid, $ship, $storefront): string {
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->firstOrFail();
            $lines = [];

            foreach ($listings as $id) {
                $listing = Listing::query()->findOrFail($id);
                app(StockService::class)->receive($listing, $location, 2);
                $lines[] = LineDraft::fromListing($listing, 1, 1000, $location);
            }

            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => $email, 'phone' => '09012345678', 'address' => ['city' => '渋谷区']]);
            $account = app(CustomerAccounts::class)->forEmail($email, 'storefront');
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, $lines, actor: 'storefront', customerAccount: $account);

            if ($storefront) {
                CheckoutOrder::create(['key_hash' => hash('sha256', $sale->id), 'transaction_id' => $sale->id]);
            }

            if ($paid) {
                $sale = app(TransactionService::class)->transition($sale, Phase::Payment, 'test');
            }

            if ($paid && $ship) {
                app(TransactionService::class)->transition($sale, Phase::Fulfillment, 'test');
            }

            return $sale->id;
        });
    }

    private function listing(string $title): string
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 4900, 'status' => ListingStatus::Active])->id;
    }

    /**
     * ゲストの書く欄の署名つきの道筋（ご注文の確認から開いたときと同じもの）
     */
    private function signedForm(string $shop, string $order, string $listing): string
    {
        return $this->inside($shop, fn (): string => ReviewLinks::form($order, $listing, true));
    }

    /**
     * ご注文の確認の画面（確認のメールの署名つきのリンクで開く）
     */
    private function guestOrderPage(string $shop, string $order): HTMLDocument
    {
        $link = $this->inside($shop, fn (): string => OrderReader::link(Transaction::query()->findOrFail($order), "http://{$shop}.localhost"));

        return $this->html($this->shop($shop, 'GET', (string) parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY))->assertOk());
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function html(TestResponse $response): HTMLDocument
    {
        return HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);
    }

    private function product(string $shop, string $listingId): string
    {
        return (string) $this->shop($shop, 'GET', "/products/{$listingId}")->assertOk()->getContent();
    }

    private function signIn(string $shop, string $email): void
    {
        $this->shop($shop, 'POST', '/account/login', ['email' => $email]);
        $this->shop($shop, 'POST', '/account/login/code', ['code' => Mail::sent(LoginCodeMail::class)->last()->code]);
        $this->assertNotNull($this->member);
    }

    /**
     * ショップへ送る。会員の印を持ち越し、要求ごとにログインの仕組みを作り直す
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function shop(string $shop, string $method, string $path, array $data = []): TestResponse
    {
        $this->defaultCookies = [];

        if ($this->member !== null) {
            $this->withCookie(MemberCookie::NAME, $this->member);
        }

        $this->app['auth']->forgetGuards();
        $this->app['cookie']->flushQueuedCookies();
        $url = "http://{$shop}.localhost{$path}";
        $server = ['REMOTE_ADDR' => $this->ip];
        $response = $method === 'GET' ? $this->withServerVariables($server)->get($url) : $this->withServerVariables($server)->post($url, $data);
        tenancy()->end();
        $cookie = $response->getCookie(MemberCookie::NAME);

        if ($cookie !== null) {
            $this->member = (string) $cookie->getValue() === '' ? null : (string) $cookie->getValue();
        }

        return $response;
    }
}
