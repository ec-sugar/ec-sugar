<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Admin\Http\Presenters\TransactionPresenter;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Order\Models\OrderFieldValue;
use App\Core\Order\Services\OrderFields;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\ThemeRegistry;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Checkout\CheckoutOrder;
use App\Storefront\Members\LoginCodeMail;
use App\Storefront\Members\MemberCookie;
use App\Storefront\Orders\OrderReader;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * 注文の項目（ADR 0066）。店が足した欄が注文の入力に出て（4 つのテーマとも核の部品）、サーバーが店の決まりで確かめ
 * （必須・長さ・選択肢の中か。使わない欄・知らない欄の値は捨てる）、確かめの画面の後に欄が変われば確定しない。
 * 値は暗号化して注文に残り（記録には欄の ID だけ）、確かめの画面（入れた本人）には全部、ご注文の状況・ご注文の確認のメール・マイページには
 * 選ぶ・チェックの答えだけが注文の時の欄の名前で出る（文の答えは「お店に届いています」の一言だけ。ADR 0066 の決定 5）
 */
final class OrderFieldsTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    private ?string $token = null;

    private ?string $member = null;

    private string $knit;

    /** @var array<string, mixed> */
    private array $valid = [
        'buyer' => ['email' => 'hanako@example.com', 'name' => '山田 花子', 'phone' => '090-1234-5678', 'postal' => '150-0001', 'prefecture' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前 1-2-3', 'line2' => ''],
        'same_as_buyer' => '1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        FakeStripe::install();
        $this->tenant = Tenant::create(['id' => 'ofd', 'name' => '店']);
        $this->tenant->domains()->create(['domain' => 'ofd.localhost']);
        $this->knit = $this->inside(function (): string {
            $seller = Seller::query()->value('id');
            $knit = Listing::create(['seller_id' => $seller, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4400, 'status' => 'active']);
            app(StockService::class)->receive($knit, Location::query()->orderBy('id')->firstOrFail(), 20);
            app(ShopSettingsService::class)->setShipping(['fee' => 550, 'free_over' => null]);
            app(PaymentGatewayService::class)->configureStripe(['secret_key' => implode('_', ['sk', 'test', 'FakeForTestsOnly0001234']), 'webhook_secret' => implode('_', ['whsec', 'FakeForTestsOnly0001234'])]);

            return $knit->id;
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();
        FakeStripe::reset();
        parent::tearDown();
    }

    public function test_a_shop_without_fields_shows_nothing_and_ignores_posted_values(): void
    {
        $this->addToCart();

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $this->send('GET', '/checkout')->assertOk()->assertDontSee('data-test="order-fields"', false);
        }

        $this->send('POST', '/checkout', [...$this->valid, 'fields' => ['01jbx0000000000000000000aa' => 'のしを付けて']])->assertRedirect('http://ofd.localhost/checkout/confirm');
        $this->send('GET', '/checkout/confirm')->assertOk()->assertDontSee('data-test="order-fields-summary"', false);
        $this->place();

        $this->inside(function (): void {
            $this->assertSame(0, OrderFieldValue::query()->count());
            $this->assertSame(0, DomainEvent::query()->where('type', OrderFields::RECORDED)->count());
        });
    }

    public function test_the_values_reach_the_order_and_every_place_it_is_shown_under_the_names_of_that_time(): void
    {
        $ids = $this->defineFields();
        $this->addToCart();

        // 4 つのテーマとも、核の部品が使う欄を並びの順に出す（使わない欄は出さない）
        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $html = (string) $this->send('GET', '/checkout')->assertOk()->getContent();
            $this->assertStringContainsString('data-test="order-fields"', $html, $theme);
            $this->assertSame(['ご用途', 'のし', 'ご要望', '名入れの文字'], $this->labels($html), $theme);
            $this->assertStringContainsString('<option value="お祝い"', $html, $theme);
            $this->assertStringNotContainsString('使わない欄', $html, $theme);
        }

        $this->send('POST', '/checkout', [...$this->valid, 'fields' => [
            $ids['use'] => 'お祝い',
            $ids['noshi'] => '1',
            $ids['wish'] => "  午前中に\r\n届くと助かります  ",
            $ids['name'] => '',
            $ids['off'] => '捨てられる値',
        ]])->assertRedirect('http://ofd.localhost/checkout/confirm');
        // 戻ったときは入れた値が入っている
        $back = (string) $this->send('GET', '/checkout')->getContent();
        $this->assertStringContainsString('<option value="お祝い" selected', $back);
        $this->assertStringContainsString("午前中に\n届くと助かります</textarea>", $back);

        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $html = (string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent();
            $this->assertStringContainsString('data-test="order-fields-summary"', $html, $theme);
            $this->assertSame([['ご用途', 'お祝い'], ['のし', 'はい'], ['ご要望', "午前中に\n届くと助かります"]], $this->summary($html), $theme);
        }

        $this->place();

        $link = $this->inside(function () use ($ids): string {
            $transaction = Transaction::query()->sole();
            // 値は暗号文（DB に平文で残らない）。注文の時の名前と種類の写しと一緒に
            $raw = DB::table('order_field_values')->orderBy('position')->get();
            $this->assertCount(3, $raw);
            foreach ($raw as $row) {
                $this->assertStringNotContainsString('お祝い', (string) $row->value);
                $this->assertStringNotContainsString('午前中', (string) $row->value);
                $this->assertNotSame('1', (string) $row->value);
            }
            $this->assertSame(['ご用途', 'のし', 'ご要望'], $raw->pluck('name')->all());
            // 記録には欄の ID だけ（値は入れない）。ほかの記録にも値は無い（欄の決まりの記録には、店が決めた選択肢が入る）
            $event = DomainEvent::query()->where('type', OrderFields::RECORDED)->sole();
            $this->assertSame($transaction->id, $event->aggregate_id);
            $this->assertSame(['field_ids' => [$ids['use'], $ids['noshi'], $ids['wish']]], $event->payload);
            foreach (DB::table('domain_events')->where('type', 'not like', 'order\\_field.%')->pluck('payload') as $payload) {
                $this->assertStringNotContainsString('午前中', (string) $payload);
                $this->assertStringNotContainsString('お祝い', (string) $payload);
            }

            // お客さんの注文の様子・ご注文の確認のメール: 選ぶ・チェックの答えだけ。文の答えは中身を出さず一言だけ（発送の知らせには何も出さない）
            $view = app(OrderReader::class)->read($transaction);
            $this->assertSame([['name' => 'ご用途', 'value' => 'お祝い'], ['name' => 'のし', 'value' => 'はい']], $view->fields);
            $this->assertTrue($view->fieldsWithheld);
            foreach (['order_paid', 'order_awaiting_payment'] as $kind) {
                $text = view('mail.order-text', ['kind' => $kind, 'shopName' => '店', 'order' => $view, 'link' => 'https://ofd.localhost/x'])->render();
                $this->assertStringContainsString("ご用途: お祝い\nのし: はい\nご注文時にご記入いただいた内容は、ショップで受け付けました。", $text, $kind);
                $html = view('mail.order', ['kind' => $kind, 'shopName' => '店', 'order' => $view, 'link' => 'https://ofd.localhost/x'])->render();
                $this->assertStringContainsString('ご注文時にご記入いただいた内容は、ショップで受け付けました。', $html, $kind);
                foreach ([$text, $html] as $mail) {
                    $this->assertStringNotContainsString('午前中', $mail, $kind);
                    $this->assertStringNotContainsString('ご要望:', $mail, $kind);
                }
            }
            $shipped = view('mail.order-text', ['kind' => 'order_shipped', 'shopName' => '店', 'order' => $view, 'link' => 'https://ofd.localhost/x'])->render();
            $this->assertStringNotContainsString('ご用途', $shipped);
            $this->assertStringNotContainsString('お店に届いています', $shipped);

            // 管理画面の注文の詳細: 選ぶ・チェックの値は出し、自由な文は値を入れない（個人情報の入口でだけ）
            $this->assertSame([
                ['name' => 'ご用途', 'type' => 'select', 'value' => 'お祝い', 'private' => false],
                ['name' => 'のし', 'type' => 'checkbox', 'value' => 'はい', 'private' => false],
                ['name' => 'ご要望', 'type' => 'textarea', 'value' => null, 'private' => true],
            ], app(TransactionPresenter::class)->present($transaction)['order_fields']);

            // 後で欄の名前を変えても、過去の注文は当時の名前で
            app(OrderFields::class)->update($ids['use'], ['name' => '贈る相手', 'type' => 'select', 'options' => ['お祝い', '内祝い'], 'required' => true, 'enabled' => true], 'test');
            $this->assertSame('ご用途', app(OrderReader::class)->read($transaction)->fields[0]['name']);

            return OrderReader::link($transaction, 'http://ofd.localhost');
        });

        // ご注文の状況のページ（4 つのテーマとも核の部品）: 選ぶ・チェックの答えと、文の答えは一言だけ
        foreach (app(ThemeRegistry::class)->names() as $theme) {
            $this->tenant->forceFill(['theme' => $theme])->save();
            $html = (string) $this->send('GET', (string) parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY))->assertOk()->getContent();
            $this->assertSame([['ご用途', 'お祝い'], ['のし', 'はい']], $this->summary($html), $theme);
            $this->assertStringContainsString('ご注文時にご記入いただいた内容は、ショップで受け付けました。', $html, $theme);
            $this->assertStringNotContainsString('午前中', $html, $theme);
        }

        // 会員のマイページの注文（同じメールの注文）: 同じく文の答えは出さない
        $this->signIn('hanako@example.com');
        $account = (string) $this->send('GET', '/account')->assertOk()->getContent();
        $this->assertSame([['ご用途', 'お祝い'], ['のし', 'はい']], $this->summary($account));
        $this->assertStringContainsString('ご注文時にご記入いただいた内容は、ショップで受け付けました。', $account);
        $this->assertStringNotContainsString('午前中', $account);
    }

    public function test_each_type_is_checked_on_the_server_and_unknown_or_unused_fields_are_dropped(): void
    {
        $ids = $this->defineFields(required: true);
        $this->addToCart();
        $this->send('GET', '/checkout')->assertOk();
        $ok = [$ids['use'] => 'お祝い', $ids['noshi'] => '1', $ids['wish'] => 'よろしく', $ids['name'] => 'はなこ'];

        $refused = [
            [[$ids['name'] => ''], 'name', '「名入れの文字」を入力してください。'],                         // 必須の空
            [[$ids['name'] => '   '], 'name', '「名入れの文字」を入力してください。'],                      // 空白だけ
            [[$ids['name'] => str_repeat('あ', 201)], 'name', '「名入れの文字」は 200 文字以内で入力してください。'],
            [[$ids['name'] => "1 行目\n2 行目"], 'name', '「名入れの文字」は改行せずに 1 行で入力してください。'],
            [[$ids['name'] => "はな\u{202E}こ"], 'name', '「名入れの文字」に使えない特殊な文字（制御文字など）が含まれています。その文字を消してから、もう一度お試しください。'],
            [[$ids['name'] => "はな\u{2028}こ"], 'name', '「名入れの文字」に使えない特殊な文字（制御文字など）が含まれています。その文字を消してから、もう一度お試しください。'],   // 行の区切り
            [[$ids['name'] => "はな\u{200B}こ"], 'name', '「名入れの文字」に使えない特殊な文字（制御文字など）が含まれています。その文字を消してから、もう一度お試しください。'],   // 幅の無い空白
            [[$ids['wish'] => "よろ\u{2029}しく"], 'wish', '「ご要望」に使えない特殊な文字（制御文字など）が含まれています。その文字を消してから、もう一度お試しください。'],      // 段落の区切り
            [[$ids['wish'] => "よろ\u{200B}しく"], 'wish', '「ご要望」に使えない特殊な文字（制御文字など）が含まれています。その文字を消してから、もう一度お試しください。'],
            [[$ids['wish'] => "よろ\u{FEFF}しく"], 'wish', '「ご要望」に使えない特殊な文字（制御文字など）が含まれています。その文字を消してから、もう一度お試しください。'],
            [[$ids['wish'] => str_repeat('あ', 1001)], 'wish', '「ご要望」は 1,000 文字以内で入力してください。'],
            [[$ids['wish'] => "よろ\x07しく"], 'wish', '「ご要望」に使えない特殊な文字（制御文字など）が含まれています。その文字を消してから、もう一度お試しください。'],
            [[$ids['use'] => 'お葬式'], 'use', '「ご用途」は、表示されている選択肢から選んでください。'],        // 選択肢の外
            [[$ids['use'] => 'お祝'], 'use', '「ご用途」は、表示されている選択肢から選んでください。'],        // 完全に同じ文だけ（前後の空白は入口がそろえる）
            [[$ids['use'] => ''], 'use', '「ご用途」を選んでください。'],
            [[$ids['use'] => ['お祝い']], 'use', '「ご用途」を選んでください。'],                        // 文字でない
            [[$ids['noshi'] => '0'], 'noshi', '「のし」にチェックを入れてください。'],                   // 必須のチェック
            [[$ids['noshi'] => 'yes'], 'noshi', '「のし」にチェックを入れてください。'],
        ];

        foreach ($refused as [$values, $key, $message]) {
            $this->send('POST', '/checkout', [...$this->valid, 'fields' => [...$ok, ...$values]])
                ->assertRedirect('http://ofd.localhost/checkout')->assertSessionHasErrors(['fields.'.$ids[$key] => $message]);
        }
        // 誤りは欄の下に出る（セッションを確かめた後の画面は、テストの都合でもう一度送ってから開く）
        $this->send('POST', '/checkout', [...$this->valid, 'fields' => [...$ok, $ids['noshi'] => '0']]);
        $this->send('GET', '/checkout')->assertOk()->assertSee('「のし」にチェックを入れてください。')->assertSee('data-test="order-field-error"', false);
        $this->send('GET', '/checkout/confirm')->assertRedirect('http://ofd.localhost/checkout');
        // fields が配列でない（誤りは直前に開いた画面へ戻すので、入力の画面を開いてから）
        $this->send('GET', '/checkout')->assertOk();
        $this->send('POST', '/checkout', [...$this->valid, 'fields' => 'x'])->assertRedirect('http://ofd.localhost/checkout')->assertSessionHasErrors(['fields.'.$ids['name']]);

        // 長さの境界はちょうどなら通る。使わない欄・知らない欄・形の違う鍵の値は捨てる
        $this->send('POST', '/checkout', [...$this->valid, 'fields' => [...$ok,
            $ids['name'] => str_repeat('あ', 200), $ids['wish'] => str_repeat('い', 1000),
            $ids['off'] => '使わない欄', '01jbx0000000000000000000aa' => '知らない欄', 'name' => '形の違う鍵',
        ]])->assertRedirect('http://ofd.localhost/checkout/confirm');
        $this->place();
        $this->inside(function () use ($ids): void {
            $values = OrderFieldValue::query()->orderBy('position')->get();
            $this->assertSame([$ids['use'], $ids['noshi'], $ids['wish'], $ids['name']], $values->pluck('order_field_id')->all());
            $this->assertSame(str_repeat('あ', 200), $values->last()?->value);
        });
    }

    public function test_optional_fields_can_be_left_empty_and_an_unchecked_box_is_a_no(): void
    {
        $ids = $this->defineFields();
        $this->addToCart();
        $this->send('POST', '/checkout', [...$this->valid, 'fields' => [$ids['use'] => 'お祝い', $ids['noshi'] => '0']])->assertRedirect('http://ofd.localhost/checkout/confirm');
        $this->assertSame([['ご用途', 'お祝い'], ['のし', 'いいえ']], $this->summary((string) $this->send('GET', '/checkout/confirm')->getContent()));
        $this->place();
        $this->inside(fn () => $this->assertSame(['お祝い', '0'], OrderFieldValue::query()->orderBy('position')->get()->pluck('value')->all()));
    }

    public function test_a_field_changed_after_the_confirm_page_stops_the_order(): void
    {
        $ids = $this->defineFields();
        $this->addToCart();

        $use = [$ids['use'] => 'お祝い'];
        $withWish = [...$use, $ids['wish'] => 'よろしく'];

        foreach ([
            '選択肢を変えた' => [$use, fn () => app(OrderFields::class)->update($ids['use'], ['name' => 'ご用途', 'type' => 'select', 'options' => ['お祝い', 'お返し'], 'required' => true, 'enabled' => true], 'test')],
            '任意の欄を必須にした' => [$use, fn () => app(OrderFields::class)->update($ids['wish'], ['name' => 'ご要望', 'type' => 'textarea', 'required' => true, 'enabled' => true], 'test')],
            '欄の名前を変えた' => [$withWish, fn () => app(OrderFields::class)->update($ids['noshi'], ['name' => 'のし紙', 'type' => 'checkbox', 'required' => false, 'enabled' => true], 'test')],
            '欄の説明を変えた' => [$withWish, fn () => app(OrderFields::class)->update($ids['use'], ['name' => 'ご用途', 'description' => '包み方と表書きを合わせます', 'type' => 'select', 'options' => ['お祝い', 'お返し'], 'required' => true, 'enabled' => true], 'test')],
            '欄を消した' => [$withWish, fn () => app(OrderFields::class)->delete($ids['name'], 'test')],
            '新しく必須の欄を足した' => [$withWish, fn () => app(OrderFields::class)->create(['name' => 'お名前の読み', 'type' => 'text', 'required' => true, 'enabled' => true], 'test')],
        ] as $case => [$posted, $change]) {
            $this->send('POST', '/checkout', [...$this->valid, 'fields' => $posted])->assertRedirect('http://ofd.localhost/checkout/confirm');
            $shown = $this->confirm();
            $this->inside($change);

            $this->send('POST', '/checkout/confirm', $shown)->assertRedirect('http://ofd.localhost/checkout/confirm');
            $this->inside(function () use ($case): void {
                $this->assertSame(0, Transaction::query()->count(), $case);
                $this->assertSame(0, CheckoutOrder::query()->count(), $case);
                $this->assertSame(0, OrderFieldValue::query()->count(), $case);
            });
        }

        // 確かめの画面は、今の決まりに合わない入力を入力の画面へ戻す（足された必須の欄）
        $this->send('GET', '/checkout/confirm')->assertRedirect('http://ofd.localhost/checkout');
        $this->send('GET', '/checkout')->assertOk()->assertSee('「お名前の読み」を入力してください。');
    }

    public function test_reordering_after_the_confirm_page_still_places_and_the_description_is_kept_with_the_answer(): void
    {
        $ids = $this->defineFields();
        $this->addToCart();
        $this->send('POST', '/checkout', [...$this->valid, 'fields' => [$ids['use'] => 'お祝い', $ids['noshi'] => '1']])->assertRedirect('http://ofd.localhost/checkout/confirm');
        $shown = $this->confirm();

        // 並べ替えただけなら答えの意味は変わらないので、確定できる（要約の中の欄は ID の順）
        $this->inside(fn () => app(OrderFields::class)->reorder([$ids['name'], $ids['wish'], $ids['off'], $ids['noshi'], $ids['use']], 'test'));
        $response = $this->send('POST', '/checkout/confirm', $shown);
        $response->assertStatus(303);

        // 値には、その時の欄の説明も写す（同意のチェックなら、どの文に同意したかを後から示せる）
        $this->inside(function () use ($ids): void {
            $this->assertSame('包み方を合わせます', OrderFieldValue::query()->where('order_field_id', $ids['use'])->value('description'));
            $this->assertNull(OrderFieldValue::query()->where('order_field_id', $ids['noshi'])->value('description'));
            $this->assertSame('包み方を合わせます', collect(app(OrderFields::class)->forTransaction(Transaction::query()->sole()->id))->firstWhere('name', 'ご用途')['description'] ?? null);
        });
    }

    public function test_gift_orders_still_ask_the_buyer(): void
    {
        $ids = $this->defineFields();
        $this->inside(fn () => app(PluginStates::class)->set('gift', true, 'test'));
        $this->addToCart();

        $html = (string) $this->send('GET', '/checkout')->assertOk()->getContent();
        $this->assertStringContainsString('data-test="gift-fields"', $html);
        $this->assertStringContainsString('data-test="order-fields"', $html);

        $this->send('POST', '/checkout', [...$this->valid, 'gift' => ['on' => '1', 'sender' => 'はなこ', 'message' => ''], 'fields' => [$ids['use'] => 'お祝い', $ids['noshi'] => '1']])
            ->assertRedirect('http://ofd.localhost/checkout/confirm');
        $this->place();
        $this->inside(fn () => $this->assertSame(['お祝い', '1'], OrderFieldValue::query()->orderBy('position')->get()->pluck('value')->all()));
    }

    public function test_values_are_shown_as_text_not_html(): void
    {
        $ids = $this->defineFields();
        // 店の人が決めた選択肢の文も HTML として解釈しない
        $option = '<img src=x onerror=alert(3)>';
        $this->inside(fn () => app(OrderFields::class)->update($ids['use'], ['name' => '<b>ご用途</b>', 'description' => '<script>alert(4)</script>', 'type' => 'select', 'options' => ['お祝い', $option], 'required' => true, 'enabled' => true], 'test'));
        $this->addToCart();
        $form = (string) $this->send('GET', '/checkout')->getContent();
        $this->assertStringNotContainsString($option, $form);
        $this->assertStringNotContainsString('<script>alert(4)</script>', $form);
        $this->assertStringContainsString(e('<b>ご用途</b>'), $form);

        $attack = '<script>alert(1)</script><img src=x onerror=alert(2)>';
        $this->send('POST', '/checkout', [...$this->valid, 'fields' => [$ids['use'] => $option, $ids['wish'] => $attack]])->assertRedirect('http://ofd.localhost/checkout/confirm');

        // 確かめの画面（入れた本人）には文の答えも出るが、文字のまま
        $confirm = (string) $this->send('GET', '/checkout/confirm')->getContent();
        $this->assertStringNotContainsString($attack, $confirm);
        $this->assertStringContainsString(e($attack), $confirm);
        $this->assertStringNotContainsString($option, $confirm);
        $this->assertStringContainsString(e($attack).'</textarea>', (string) $this->send('GET', '/checkout')->getContent());
        $this->place();

        // メールとご注文の状況には文の答えは出ない。選ぶの答え（店の文）は文字のまま
        $link = $this->inside(function () use ($attack, $option): string {
            $transaction = Transaction::query()->sole();
            $html = view('mail.order', ['kind' => 'order_paid', 'shopName' => '店', 'order' => app(OrderReader::class)->read($transaction), 'link' => 'https://ofd.localhost/x'])->render();
            $this->assertStringNotContainsString('alert(1)', $html);
            $this->assertStringNotContainsString($option, $html);
            $this->assertStringContainsString(e($option), $html);
            $this->assertStringNotContainsString($attack, $html);

            return OrderReader::link($transaction, 'http://ofd.localhost');
        });
        $page = (string) $this->send('GET', (string) parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY))->assertOk()->getContent();
        $this->assertStringNotContainsString('alert(1)', $page);
        $this->assertStringNotContainsString($option, $page);
        $this->assertStringContainsString(e($option), $page);
    }

    public function test_the_database_keeps_values_fixed_and_used_fields_in_place(): void
    {
        $ids = $this->defineFields();
        $this->addToCart();
        $this->send('POST', '/checkout', [...$this->valid, 'fields' => [$ids['use'] => 'お祝い']])->assertRedirect();
        $this->place();

        $this->inside(function () use ($ids): void {
            // 使われた欄は消せない（「使わない」にはできる）
            try {
                app(OrderFields::class)->delete($ids['use'], 'test');
                $this->fail('使われた欄を消した');
            } catch (ValidationException $e) {
                $this->assertSame(['order_field' => ['「ご用途」は注文で回答されたため削除できません。お客さんの画面に表示しないときは、「使う」をオフにしてください。']], $e->errors());
            }
            app(OrderFields::class)->update($ids['use'], ['name' => 'ご用途', 'type' => 'select', 'options' => ['お祝い'], 'required' => false, 'enabled' => false], 'test');
            // 使われていない欄は消せる
            app(OrderFields::class)->delete($ids['name'], 'test');

            // 値は追記専用（書き換え・消す・空にするを DB が断る）。使われた欄も DB が消させない
            foreach ([
                'UPDATE' => [static fn () => DB::table('order_field_values')->update(['name' => '書き換え']), '追記専用'],
                'DELETE' => [static fn () => DB::table('order_field_values')->delete(), '追記専用'],
                'TRUNCATE' => [static fn () => DB::statement('TRUNCATE order_field_values'), '追記専用'],
                '欄を消す' => [static fn () => DB::table('order_fields')->where('id', $ids['use'])->delete(), 'order_field_values_order_field_id_foreign'],
            ] as $case => [$write, $guard]) {
                try {
                    DB::transaction($write);
                    $this->fail('DB が通した: '.$case);
                } catch (QueryException $e) {
                    $this->assertStringContainsString($guard, $e->getMessage(), $case);
                }
            }
            $this->assertSame(2, OrderFieldValue::query()->count()); // ご用途とのし（いいえ）。何も消えていない
        });
    }

    /**
     * 欄を 5 つ作る（選ぶ・チェック・複数行・1 行と、使わない欄）
     *
     * @return array{use: string, noshi: string, wish: string, name: string, off: string}
     */
    private function defineFields(bool $required = false): array
    {
        return $this->inside(function () use ($required): array {
            $fields = app(OrderFields::class);

            return [
                'use' => $fields->create(['name' => 'ご用途', 'description' => '包み方を合わせます', 'type' => 'select', 'options' => ['お祝い', '内祝い'], 'required' => true, 'enabled' => true], 'test')->id,
                'noshi' => $fields->create(['name' => 'のし', 'type' => 'checkbox', 'required' => $required, 'enabled' => true], 'test')->id,
                'off' => $fields->create(['name' => '使わない欄', 'type' => 'text', 'required' => true, 'enabled' => false], 'test')->id,
                'wish' => $fields->create(['name' => 'ご要望', 'type' => 'textarea', 'required' => false, 'enabled' => true], 'test')->id,
                'name' => $fields->create(['name' => '名入れの文字', 'type' => 'text', 'required' => $required, 'enabled' => true], 'test')->id,
            ];
        });
    }

    /**
     * 注文の項目の欄の名前（必須・任意の札を除く）
     *
     * @return list<string>
     */
    private function labels(string $html): array
    {
        preg_match_all('/<span class="sugar-order-fields__label">\s*([^<\s][^<]*?)\s*(?:<|$)/u', $html, $m);

        return array_map(static fn (string $s): string => html_entity_decode(trim($s)), $m[1]);
    }

    /**
     * 注文の項目の値の並び（[名前, 値]）
     *
     * @return list<array{0: string, 1: string}>
     */
    private function summary(string $html): array
    {
        preg_match_all('/<dt class="sugar-order-fields-summary__label">(.*?)<\/dt>\s*<dd class="sugar-order-fields-summary__value">(.*?)<\/dd>/su', $html, $m, PREG_SET_ORDER);

        return array_map(static fn (array $row): array => [html_entity_decode($row[1]), html_entity_decode($row[2])], $m);
    }

    private function addToCart(): void
    {
        $this->send('POST', '/cart/items', ['listing_id' => $this->knit, 'quantity' => '1'])->assertRedirect();
    }

    /**
     * 確認の画面を開き、確定のフォームの値を取り出す
     *
     * @return array<string, string>
     */
    private function confirm(): array
    {
        $html = (string) $this->send('GET', '/checkout/confirm')->assertOk()->getContent();
        preg_match('/name="order_key" value="([^"]+)"/', $html, $key);
        preg_match('/name="fingerprint" value="([^"]+)"/', $html, $fingerprint);

        return ['order_key' => $key[1] ?? '', 'fingerprint' => $fingerprint[1] ?? ''];
    }

    private function place(): void
    {
        $response = $this->send('POST', '/checkout/confirm', $this->confirm());
        $response->assertStatus(303);
        $this->assertStringStartsWith('https://checkout.stripe.com/', (string) $response->headers->get('Location'));
    }

    private function signIn(string $email): void
    {
        $this->send('POST', '/account/login', ['email' => $email])->assertRedirect('http://ofd.localhost/account/login/code');
        $code = Mail::sent(LoginCodeMail::class)->last()->code;
        $this->send('POST', '/account/login/code', ['code' => $code])->assertRedirect('http://ofd.localhost/account');
        $this->assertNotNull($this->member);
    }

    /**
     * Cookie（カートの合い言葉・会員の印とセッション）を持ち越して送る
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $path, array $data = []): TestResponse
    {
        if ($this->token !== null) {
            $this->withCookie(CartCookie::NAME, $this->token);
        }

        if ($this->member !== null) {
            $this->withCookie(MemberCookie::NAME, $this->member);
        }

        $this->app['auth']->forgetGuards();
        $url = "http://ofd.localhost{$path}";
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        tenancy()->end();

        foreach ([CartCookie::NAME => 'token', MemberCookie::NAME => 'member'] as $name => $property) {
            $cookie = $response->getCookie($name);
            if ($cookie !== null) {
                $this->{$property} = (string) $cookie->getValue() === '' ? null : (string) $cookie->getValue();
            }
        }

        return $response;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function inside(Closure $work): mixed
    {
        tenancy()->initialize($this->tenant);

        try {
            return $work();
        } finally {
            tenancy()->end();
        }
    }
}
