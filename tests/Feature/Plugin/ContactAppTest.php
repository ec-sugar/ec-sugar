<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Crypto\BlindIndex;
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
use EcSugar\PluginContact\BotGuard;
use EcSugar\PluginContact\ContactInquiries;
use EcSugar\PluginContact\ContactLinks;
use EcSugar\PluginContact\ContactStatus;
use EcSugar\PluginContact\Mail\ContactMail;
use EcSugar\PluginContact\Models\ContactInquiry;
use EcSugar\PluginContact\Models\ContactReply;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * App「お問い合わせ」。ショップのフォーム（JavaScript なし）→ 受け付けのメール（本文の写しは載せない）と店への知らせ（本文なし）→ 管理画面で読んで返信する。
 * 誤った入力・ボットよけ・回数の上限・商品と注文の付け方（署名の改ざん・期限・ほかの店）・暗号の列・管理画面の権限と監査・返信のメール・店の境界・使わない店・片付け
 */
final class ContactAppTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private const string BASE = '/api/tenants/cta/apps/contact/records';

    private string $bag;

    private ?string $member = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::store()->flush();
        Mail::fake();
        $this->configureAdminHost();
        $this->tenant('cta');
        $this->bag = $this->inside('cta', function (): string {
            app(PluginStates::class)->set('contact', true, 'test');
            // 画面の設定の欄と同じく、選ぶ項目もいっしょに送る（秘密の項目だけを送ると、核の設定の表の制約に当たる。報告に書いた）
            app(PluginSettings::class)->update('contact', ['notify_email' => 'shop-staff@example.com', 'retention' => 'one_year'], 'test');

            return $this->listing('帆布のトートバッグ');
        });
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();
        parent::tearDown();
    }

    public function test_a_guest_sends_the_form_and_gets_a_receipt_without_a_copy_and_the_shop_is_told_without_the_body(): void
    {
        // フォームを開いて、書き始めの印を受け取る（JavaScript なしで送れる形）
        $form = $this->html($this->shop('GET', '/p/contact')->assertOk());
        $this->assertNotNull($form->querySelector('form[action="/p/contact"] textarea[name="body"]'));
        $this->assertNotNull($form->querySelector('div[hidden] input[name="website"]'));
        $stamp = (string) $form->querySelector('input[name="started"]')?->getAttribute('value');

        $this->travel(5)->seconds();
        $this->shop('POST', '/p/contact', ['kind' => 'other', 'name' => ' 山田 花子 ', 'email' => 'Hanako@Example.com', 'body' => "配送の日を\r\n選べますか？\t<script>alert(1)</script>", 'started' => $stamp, 'website' => ''])
            ->assertRedirect('http://cta.localhost/p/contact/sent');
        $this->shop('GET', '/p/contact/sent')->assertOk()->assertSee('お問い合わせを受け付けました');

        // 表には暗号化して置く（平文は残らない）。メールは索引でも引ける。改行は \n、タブは空白にそろえる
        [$inquiry, $plain] = $this->inside('cta', fn (): array => [$i = ContactInquiry::query()->sole(), [$i->name, $i->email, $i->body, $i->status]]);
        $this->assertSame(['山田 花子', 'Hanako@Example.com', "配送の日を\n選べますか？ <script>alert(1)</script>", ContactStatus::New], $plain);
        $raw = $this->inside('cta', fn (): string => json_encode(DB::table('contact_inquiries')->first(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        foreach (['山田', 'Hanako', 'hanako', '配送の日'] as $plain) {
            $this->assertStringNotContainsString($plain, $raw);
        }
        $this->assertSame($this->inside('cta', fn (): string => BlindIndex::of('email', 'hanako@example.com')), $inquiry->email_index);

        // 記録には ID と種類だけ（名前・メール・本文は入れない）
        $event = $this->inside('cta', fn (): DomainEvent => DomainEvent::query()->where('type', 'contact.received')->sole());
        $this->assertSame('storefront', $event->actor);
        $payload = json_encode($event->payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (['山田', 'anako', '配送'] as $plain) {
            $this->assertStringNotContainsString($plain, $payload);
        }

        // 受け付けのメール: 受付番号と種類だけ。お客さんの書いた文（名前・本文）は載せない
        $receipt = $this->lastMail('received');
        $this->assertTrue($receipt->hasTo('Hanako@Example.com'));
        $text = $receipt->render();
        $this->assertStringContainsString($inquiry->number(), $text);
        $this->assertStringContainsString('種類: そのほか', $text);
        $this->assertStringNotContainsString('配送の日', $text);
        $this->assertStringNotContainsString('山田', $text);

        // 店への知らせ: 設定のアドレスへ、1 件あることと管理画面のリンクだけ
        $notice = $this->lastMail('shop');
        $this->assertTrue($notice->hasTo('shop-staff@example.com'));
        $text = $notice->render();
        $this->assertStringContainsString('新しいお問い合わせが 1 件あります', $text);
        $this->assertStringContainsString('/tenants/cta/apps/contact/'.$inquiry->id, $text);
        foreach (['配送の日', '山田', 'anako@'] as $plain) {
            $this->assertStringNotContainsString($plain, $text);
        }
    }

    public function test_wrong_input_is_refused_with_the_text_kept(): void
    {
        $good = ['kind' => 'other', 'name' => '山田 花子', 'email' => 'hanako@example.com', 'body' => 'こんにちは'];
        $bad = [
            'name' => ['name' => ''],
            'email' => ['email' => 'not-an-address'],
            'body' => ['body' => str_repeat('あ', 2001)],
            'kind' => ['kind' => 'refund'],
        ];

        foreach ($bad as $field => $change) {
            $this->post_('/p/contact', $change + $good)->assertRedirect('http://cta.localhost/p/contact')->assertSessionHasErrors($field)->assertSessionHasInput('body');
        }

        // 制御文字・文字の向きを変える印・幅の無い空白は断る（見た目と中身をずらさない）
        $this->post_('/p/contact', ['name' => "山田\u{202E}子花"] + $good)->assertSessionHasErrors('name');
        $this->post_('/p/contact', ['body' => "お金を\u{200B}返して"] + $good)->assertSessionHasErrors('body');
        $this->post_('/p/contact', ['name' => "山田\n花子"] + $good)->assertSessionHasErrors('name');
        // 2,000 文字ちょうどは通る
        $this->post_('/p/contact', ['body' => str_repeat('あ', 2000)] + $good)->assertRedirect('http://cta.localhost/p/contact/sent');
        $this->assertSame(1, $this->inside('cta', fn (): int => ContactInquiry::query()->count()));
    }

    public function test_bots_are_dropped_with_the_same_answer_and_nothing_is_kept_or_sent(): void
    {
        $data = ['kind' => 'other', 'name' => 'bot', 'email' => 'bot@example.com', 'body' => '宣伝です'];

        // 見えない欄を埋めた・書き始めの印が無い・印を偽った・速すぎる（3 秒より前）は、受け付けと同じ答えで何もしない
        $this->post_('/p/contact', $data + ['website' => 'https://spam.example'])->assertRedirect('http://cta.localhost/p/contact/sent');
        $this->shop('POST', '/p/contact', $data)->assertRedirect('http://cta.localhost/p/contact/sent');
        $this->shop('POST', '/p/contact', $data + ['started' => (now()->getTimestamp() - 60).'.'.str_repeat('0', 64)])->assertRedirect('http://cta.localhost/p/contact/sent');
        $this->shop('POST', '/p/contact', $data + ['started' => $this->stamp(BotGuard::MIN_SECONDS - 1)], wait: false)->assertRedirect('http://cta.localhost/p/contact/sent');
        // ほかの店で出した印も通らない（署名に店の ID が入る）
        $this->tenant('ctb');
        $this->inside('ctb', fn () => app(PluginStates::class)->set('contact', true, 'test'));
        $theirs = $this->stamp(60, 'ctb');
        $this->shop('POST', '/p/contact', $data + ['started' => $theirs])->assertRedirect('http://cta.localhost/p/contact/sent');

        $this->assertSame(0, $this->inside('cta', fn (): int => ContactInquiry::query()->count()));
        Mail::assertNothingSent();

        // 人の速さ（書き始めから 3 秒より後）なら受け付ける
        $this->shop('POST', '/p/contact', $data + ['started' => $this->stamp(BotGuard::MIN_SECONDS)], wait: false)->assertRedirect('http://cta.localhost/p/contact/sent');
        $this->assertSame(1, $this->inside('cta', fn (): int => ContactInquiry::query()->count()));
    }

    public function test_sending_is_limited_per_connection_per_address_and_per_shop(): void
    {
        $data = static fn (string $email): array => ['kind' => 'other', 'name' => '山田', 'email' => $email, 'body' => 'こんにちは'];

        // 1 つの接続元は 1 時間に 5 回まで（メールを変えても）。超えたら受け付けず、書いた文は残して伝える
        for ($i = 0; $i < ContactInquiries::SOURCE_HOURLY; $i++) {
            $this->post_('/p/contact', $data("a{$i}@example.com"), '203.0.113.1')->assertRedirect('http://cta.localhost/p/contact/sent');
        }

        $this->post_('/p/contact', $data('a9@example.com'), '203.0.113.1')->assertRedirect('http://cta.localhost/p/contact')
            ->assertSessionHas('cart_error', fn (string $m): bool => str_contains($m, 'しばらくしてから'))->assertSessionHasInput('body');

        // 同じメールへは、接続元を変えても 1 日に 5 回まで（ほかの人のアドレスに受け付けのメールを送りつけ続けられない）
        for ($i = 0; $i < ContactInquiries::EMAIL_DAILY; $i++) {
            $this->post_('/p/contact', $data('hanako@example.com'), "198.51.100.{$i}")->assertRedirect('http://cta.localhost/p/contact/sent');
        }

        $this->post_('/p/contact', $data('HANAKO@example.com'), '198.51.100.99')->assertSessionHas('cart_error');
        $this->assertSame(ContactInquiries::SOURCE_HOURLY + ContactInquiries::EMAIL_DAILY, $this->inside('cta', fn (): int => ContactInquiry::query()->count()));

        // 店全体では 1 日に SHOP_DAILY 件まで（散らばった接続元からでも、表を膨らませない）
        for ($i = 0; $i < ContactInquiries::SHOP_DAILY; $i++) {
            RateLimiter::hit('contact|cta|shop', 86_400);
        }

        $this->post_('/p/contact', $data('taro@example.com'), '192.0.2.50')->assertSessionHas('cart_error', fn (string $m): bool => str_contains($m, 'いまはお問い合わせを受け付けられません'));
    }

    public function test_a_product_is_attached_from_the_product_page_even_when_sold_out(): void
    {
        // 商品の画面（売り切れでも）に「この商品について問い合わせる」
        $html = $this->page("/products/{$this->bag}");
        $this->assertStringContainsString('data-test="purchase-option-contact"', $html);
        $this->assertStringContainsString("/p/contact/product/{$this->bag}", $html);

        $form = $this->html($this->shop('GET', "/p/contact/product/{$this->bag}")->assertOk());
        $this->assertStringContainsString('帆布のトートバッグ', (string) $form->querySelector('[data-test="contact-listing"]')?->textContent);
        $this->assertTrue((bool) $form->querySelector('input[name="kind"][value="product"]')?->hasAttribute('checked'));
        $this->post_("/p/contact/product/{$this->bag}", ['kind' => 'product', 'name' => '山田', 'email' => 'hanako@example.com', 'body' => '色違いはありますか'])->assertRedirect('http://cta.localhost/p/contact/sent');
        $this->assertSame($this->bag, $this->inside('cta', fn () => ContactInquiry::query()->sole()->listing_id));
        $this->assertStringContainsString('商品: 帆布のトートバッグ', $this->lastMail('received')->render());

        // ショップに出ていない商品は付けない（ふつうのフォームとして受ける）
        $draft = $this->inside('cta', fn (): string => Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => '下書きの品', 'unit_amount' => 100, 'status' => ListingStatus::Draft])->id);
        $this->assertNull($this->html($this->shop('GET', "/p/contact/product/{$draft}")->assertOk())->querySelector('[data-test="contact-listing"]'));
        $this->post_("/p/contact/product/{$draft}", ['kind' => 'product', 'name' => '山田', 'email' => 'taro@example.com', 'body' => 'これは？'], '198.51.100.7');
        $this->assertNull($this->inside('cta', fn () => ContactInquiry::query()->whereBlind('email', 'taro@example.com')->sole()->listing_id));
    }

    public function test_an_order_is_attached_only_by_a_signed_link_from_the_order_page(): void
    {
        $order = $this->order('cta');
        $other = $this->order('cta', 'taro@example.com');

        // ご注文の確認（確認のメールの署名つきのリンク）に「この注文について問い合わせる」が出て、署名つきの道筋でフォームへ
        $link = $this->guestOrderPage('cta', $order)->querySelector('[data-test="order-link-contact"]')?->getAttribute('href');
        $this->assertIsString($link);
        $this->assertStringStartsWith("/p/contact/order/{$order}?", $link);
        $form = $this->html($this->shop('GET', $link)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer'));
        $this->assertSame('ご注文番号: '.strtoupper($order), $form->querySelector('[data-test="contact-order"]')?->textContent);
        $action = (string) $form->querySelector('[data-test="contact-form"] form')?->getAttribute('action');
        $this->post_($action, ['kind' => 'order', 'name' => '山田', 'email' => 'hanako@example.com', 'body' => '届け先を変えたい'])->assertRedirect('http://cta.localhost/p/contact/sent');
        $this->assertSame($order, $this->inside('cta', fn () => ContactInquiry::query()->sole()->transaction_id));

        // 署名の道筋の注文を書き換えると（ほかの人の注文）、注文を付けない。送っても受け付けない（黙って注文を外さない）
        $tampered = str_replace($order, $other, $link);
        $this->assertNull($this->html($this->shop('GET', $tampered)->assertOk())->querySelector('[data-test="contact-order"]'));
        $this->shop('GET', $tampered)->assertSee('ご注文の情報を添えられませんでした');
        $this->post_($tampered, ['kind' => 'order', 'name' => '山田', 'email' => 'hanako@example.com', 'body' => '他人の注文'], '198.51.100.8')
            ->assertRedirect('http://cta.localhost/p/contact')->assertSessionHas('cart_error')->assertSessionHasInput('body');
        // 署名の無い道筋・期限の切れた道筋も同じ
        $this->post_("/p/contact/order/{$other}", ['kind' => 'order', 'name' => '山田', 'email' => 'hanako@example.com', 'body' => '署名なし'], '198.51.100.9')->assertSessionHas('cart_error');
        $this->travel(ContactLinks::HOURS + 1)->hours();
        $this->post_($action, ['kind' => 'order', 'name' => '山田', 'email' => 'hanako@example.com', 'body' => '期限の後'], '198.51.100.10')->assertSessionHas('cart_error');
        $this->assertSame(1, $this->inside('cta', fn (): int => ContactInquiry::query()->count()));
    }

    public function test_a_member_sends_with_the_login_address_and_the_admin_links_to_the_customer(): void
    {
        $this->signIn('hanako@example.com');
        $form = $this->html($this->shop('GET', '/p/contact')->assertOk());
        $this->assertNull($form->querySelector('input[name="email"]'));
        $this->assertStringContainsString('hanako@example.com', (string) $form->querySelector('[data-test="contact-member"]')?->textContent);

        // メールの欄を送っても使わない（ログインのメールで受ける）
        $this->post_('/p/contact', ['kind' => 'other', 'name' => '山田', 'email' => 'someone-else@example.com', 'body' => '会員です']);
        [$inquiry, $email] = $this->inside('cta', fn (): array => [$i = ContactInquiry::query()->sole(), $i->email]);
        $this->assertSame('hanako@example.com', $email);
        $this->assertNotNull($inquiry->customer_account_id);

        $this->enrolledOperator();
        $facts = $this->send('GET', self::BASE.'/'.$inquiry->id)->assertOk()->collect('record.facts')->keyBy('label');
        $this->assertSame(['label' => 'お客さんの画面', 'kind' => 'customer', 'id' => $inquiry->customer_account_id], $facts['お客さん']['value']);
    }

    public function test_the_admin_reads_with_the_operate_level_replies_by_mail_and_changes_the_state(): void
    {
        $this->post_('/p/contact', ['kind' => 'other', 'name' => '山田 花子', 'email' => 'hanako@example.com', 'body' => "1 行目\n2 行目"]);
        $id = $this->inside('cta', fn (): string => ContactInquiry::query()->sole()->id);
        [$owner, $key] = $this->enrolledOperator();

        // 一覧: 種類・状態・日時だけで、名前・メール・本文は出さない。ホームのやることに新着の数
        $list = $this->send('GET', self::BASE)->assertOk()->assertJsonPath('filter', 'new')->assertJsonPath('meta.total', 1);
        $list->assertJsonPath('rows.0.cells.kind', 'そのほか')->assertJsonPath('rows.0.cells.status', ['label' => '新着', 'tone' => 'warn']);
        $this->assertStringNotContainsString('hanako', (string) $list->getContent());
        $this->assertNull($list->json('form'));
        $this->send('GET', '/api/tenants/cta/overview')->assertOk()->assertJsonPath('app_todo.0.label', '新しいお問い合わせ')->assertJsonPath('app_todo.0.count', 1);

        // 1 件（運用から上）: 名前・メール・本文を出し、見たことを監査ログに残す。返信の欄の言葉は「返信する」
        $record = $this->send('GET', self::BASE.'/'.$id)->assertOk();
        $record->assertJsonPath('record.personal', 'shown')->assertJsonPath('form.wording', ['title' => '返信する', 'submit' => 'メールで返信を送る', 'done' => '返信を送りました。']);
        $facts = $record->collect('record.facts')->keyBy('label');
        $this->assertSame(['山田 花子', 'hanako@example.com'], [$facts['お名前']['value'], $facts['メールアドレス']['value']]);
        $this->assertSame(['1 行目', '2 行目'], $record->collect('record.tables.0.rows')->pluck('cells.text')->all());
        // 監査ログは追記だけでテストの間も消えないので、この運営の分だけを数える
        $this->assertSame(1, AuditEvent::query()->where('type', 'operator.personal_data_viewed')->where('operator_id', $owner->id)->where('data->record_id', $id)->count());

        // 返信: メールで送り、送った文を残す（暗号化して）。新着は対応中に。記録には返信の ID だけ
        $this->send('PUT', self::BASE.'/'.$id, ['values' => ['reply' => "山田様\nお問い合わせありがとうございます。選べます & 承ります。"]])->assertOk()
            ->assertJsonPath('record.facts.0.value', ['label' => '対応中', 'tone' => 'good'])->assertJsonPath('form.values.reply', null);
        $mail = $this->lastMail('reply');
        $this->assertTrue($mail->hasTo('hanako@example.com'));
        $this->assertStringContainsString('選べます & 承ります。', $mail->render());
        [$reply, $sent] = $this->inside('cta', fn (): array => [$r = ContactReply::query()->sole(), $r->sent_at]);
        $this->assertNotNull($sent);
        $this->assertStringNotContainsString('承ります', $this->inside('cta', fn (): string => (string) DB::table('contact_replies')->value('body')));
        $event = $this->inside('cta', fn (): DomainEvent => DomainEvent::query()->where('type', 'contact.replied')->sole());
        $this->assertSame([$owner->actor(), ['reply_id' => $reply->id]], [$event->actor, $event->payload]);

        // 同じ文の押し直しは送らない。空の返信は断る
        $this->send('PUT', self::BASE.'/'.$id, ['values' => ['reply' => "山田様\nお問い合わせありがとうございます。選べます & 承ります。"]])->assertUnprocessable()->assertJsonValidationErrors('values.reply');
        $this->send('PUT', self::BASE.'/'.$id, ['values' => ['reply' => '']])->assertUnprocessable();
        $this->assertSame(1, $this->mails('reply'));

        // 状態: 済みにする（お客さんに知らせない）・対応中に戻す
        $this->send('POST', self::BASE.'/'.$id.'/actions/close')->assertOk()->assertJsonPath('record.facts.0.value.label', '済み');
        $this->assertNotNull($this->inside('cta', fn () => ContactInquiry::query()->sole()->closed_at));
        $this->send('POST', self::BASE.'/'.$id.'/actions/open')->assertOk()->assertJsonPath('record.facts.0.value.label', '対応中');
        $this->assertNull($this->inside('cta', fn () => ContactInquiry::query()->sole()->closed_at));
        $this->assertSame(1, $this->mails('reply'));

        // 消すのは直前の再確認の後だけ。消すと返信ごと無くなる
        $this->send('POST', self::BASE.'/'.$id.'/actions/delete')->assertStatus(423);
        $this->reconfirm($owner, $key);
        $this->send('POST', self::BASE.'/'.$id.'/actions/delete')->assertOk()->assertJsonPath('record', null);
        $this->assertSame([0, 0], $this->inside('cta', fn (): array => [ContactInquiry::query()->count(), ContactReply::query()->count()]));
    }

    public function test_the_read_level_sees_no_personal_data_and_cannot_reply_or_act(): void
    {
        $this->post_('/p/contact', ['kind' => 'other', 'name' => '山田 花子', 'email' => 'hanako@example.com', 'body' => '本文です']);
        $id = $this->inside('cta', fn (): string => ContactInquiry::query()->sole()->id);
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'cta', 'read', 'test');

        $record = $this->send('GET', self::BASE.'/'.$id)->assertOk()->assertJsonPath('record.personal', 'hidden');
        foreach (['hanako', '山田', '本文です'] as $plain) {
            $this->assertStringNotContainsString($plain, json_encode($record->json(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        $this->assertSame(0, AuditEvent::query()->where('type', 'operator.personal_data_viewed')->where('operator_id', $staff->id)->count());
        $this->send('PUT', self::BASE.'/'.$id, ['values' => ['reply' => 'こんにちは']])->assertForbidden();
        $this->send('POST', self::BASE.'/'.$id.'/actions/close')->assertForbidden();
        $this->assertSame([ContactStatus::New, 0], $this->inside('cta', fn (): array => [ContactInquiry::query()->sole()->status, ContactReply::query()->count()]));
    }

    public function test_another_shop_and_an_unused_app_see_nothing(): void
    {
        $order = $this->order('cta');
        $this->post_('/p/contact', ['kind' => 'other', 'name' => '山田', 'email' => 'hanako@example.com', 'body' => 'A の店へ']);
        $id = $this->inside('cta', fn (): string => ContactInquiry::query()->sole()->id);
        $this->tenant('ctb');
        $this->inside('ctb', fn () => app(PluginStates::class)->set('contact', true, 'test'));

        // B の店の管理画面にも、B の店の経路にも出ない。A の店の署名つきの道筋は B の店では通らない
        $this->enrolledOperator();
        $this->send('GET', '/api/tenants/ctb/apps/contact/records?filter=all')->assertOk()->assertJsonPath('meta.total', 0);
        $this->send('GET', '/api/tenants/ctb/apps/contact/records/'.$id)->assertNotFound();
        $signed = $this->inside('cta', fn (): string => ContactLinks::order($order));
        $this->post_($signed, ['kind' => 'order', 'name' => '山田', 'email' => 'hanako@example.com', 'body' => 'B の店で'], null, 'ctb')->assertSessionHas('cart_error');
        $this->assertSame(0, $this->inside('ctb', fn (): int => ContactInquiry::query()->count()));

        // 使うのをやめると、ショップのフォーム・商品の画面の入口・ご注文の画面のリンク・管理画面のどれにも出ない（届いたものは残る）
        $this->inside('cta', fn () => app(PluginStates::class)->set('contact', false, 'test'));
        $this->shop('GET', '/p/contact')->assertNotFound();
        $this->assertStringNotContainsString('purchase-option-contact', $this->page("/products/{$this->bag}"));
        $this->assertNull($this->guestOrderPage('cta', $order)->querySelector('[data-test="order-link-contact"]'));
        $this->send('GET', self::BASE)->assertNotFound();
        $this->assertSame(1, $this->inside('cta', fn (): int => ContactInquiry::query()->count()));
    }

    public function test_closed_inquiries_are_purged_after_the_shops_period(): void
    {
        $ids = [];

        foreach (['old@example.com', 'recent@example.com', 'open@example.com'] as $i => $email) {
            $this->post_('/p/contact', ['kind' => 'other', 'name' => '山田', 'email' => $email, 'body' => '片付けの見本'], "198.51.100.{$i}");
            $ids[$email] = $this->inside('cta', fn (): string => ContactInquiry::query()->whereBlind('email', $email)->sole()->id);
        }

        // 半年の設定。済んでから半年を過ぎたものだけ消す（済んでいないものは古くても消さない）
        $this->inside('cta', function () use ($ids): void {
            app(PluginSettings::class)->update('contact', ['retention' => 'half_year'], 'test');
            app(ContactInquiries::class)->reply($ids['old@example.com'], 'お返事です', 'test');
            foreach (['old@example.com', 'recent@example.com'] as $email) {
                app(ContactInquiries::class)->changeStatus($ids[$email], [ContactStatus::New, ContactStatus::Open], ContactStatus::Closed, 'test');
            }
            ContactInquiry::query()->whereKey($ids['old@example.com'])->update(['closed_at' => now()->subDays(183)]);
            ContactInquiry::query()->whereKey($ids['recent@example.com'])->update(['closed_at' => now()->subDays(181)]);
            ContactInquiry::query()->whereKey($ids['open@example.com'])->update(['created_at' => now()->subYears(3)]);
        });

        // 使うのをやめた店でも、消す分が残っていれば片付ける
        $this->inside('cta', fn () => app(PluginStates::class)->set('contact', false, 'test'));
        $this->artisan('contact:prune')->assertSuccessful();

        $left = $this->inside('cta', fn (): array => ContactInquiry::query()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$ids['recent@example.com'], $ids['open@example.com']], $left);
        $this->assertSame(0, $this->inside('cta', fn (): int => ContactReply::query()->count()));
        $event = $this->inside('cta', fn (): DomainEvent => DomainEvent::query()->where('type', 'contact.purged')->sole());
        $this->assertSame([ContactInquiries::SYSTEM, $ids['old@example.com']], [$event->actor, $event->aggregate_id]);

        // もう一度流しても同じ
        $this->artisan('contact:prune')->assertSuccessful();
        $this->assertCount(2, $this->inside('cta', fn (): array => ContactInquiry::query()->pluck('id')->all()));
    }

    private function listing(string $title): string
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 4900, 'status' => ListingStatus::Active])->id;
    }

    /**
     * ショップで確定して払われた注文（ご注文の確認の画面が開ける形）
     */
    private function order(string $shop, string $email = 'hanako@example.com'): string
    {
        return $this->inside($shop, function () use ($email): string {
            $seller = Seller::query()->firstOrFail();
            $location = Location::query()->firstOrFail();
            $listing = Listing::query()->findOrFail($this->bag);
            app(StockService::class)->receive($listing, $location, 2);
            $customer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => $email, 'phone' => '09012345678', 'address' => ['city' => '渋谷区']]);
            $account = app(CustomerAccounts::class)->forEmail($email, 'storefront');
            $sale = app(TransactionService::class)->open('sale', $seller, $customer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'storefront', customerAccount: $account);
            CheckoutOrder::create(['key_hash' => hash('sha256', $sale->id), 'transaction_id' => $sale->id]);
            app(TransactionService::class)->transition($sale, Phase::Payment, 'test');

            return $sale->id;
        });
    }

    /**
     * ご注文の確認の画面（確認のメールの署名つきのリンクで開く）
     */
    private function guestOrderPage(string $shop, string $order): HTMLDocument
    {
        $link = $this->inside($shop, fn (): string => OrderReader::link(Transaction::query()->findOrFail($order), "http://{$shop}.localhost"));

        return $this->html($this->shop('GET', (string) parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY), [], null, $shop)->assertOk());
    }

    /**
     * 書き始めの印（$ago 秒前にフォームを出した形。店の文脈の中で作る）
     */
    private function stamp(int $ago, string $shop = 'cta'): string
    {
        return $this->inside($shop, fn (): string => BotGuard::stamp(now()->getTimestamp() - $ago));
    }

    /**
     * 人が送る（10 秒前にフォームを出した印をつけて）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function post_(string $path, array $data, ?string $ip = null, string $shop = 'cta'): TestResponse
    {
        return $this->shop('POST', $path, $data + ['started' => $this->stamp(10, $shop), 'website' => ''], $ip, $shop);
    }

    private function mails(string $kind): int
    {
        return Mail::sent(ContactMail::class, static fn (ContactMail $m): bool => $m->kind === $kind)->count();
    }

    private function lastMail(string $kind): ContactMail
    {
        $mail = Mail::sent(ContactMail::class, static fn (ContactMail $m): bool => $m->kind === $kind)->last();
        $this->assertInstanceOf(ContactMail::class, $mail);

        return $mail;
    }

    private function page(string $path): string
    {
        return (string) $this->shop('GET', $path)->assertOk()->getContent();
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function html(TestResponse $response): HTMLDocument
    {
        return HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);
    }

    private function signIn(string $email): void
    {
        $this->shop('POST', '/account/login', ['email' => $email]);
        $this->shop('POST', '/account/login/code', ['code' => Mail::sent(LoginCodeMail::class)->last()->code]);
        $this->assertNotNull($this->member);
    }

    /**
     * ショップへ送る（会員の印を持ち回る。送るたびに 7 秒進める〔人の速さ。1 分の回数の上限にテスト自身が当たらないように〕。$wait が偽なら進めない）
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function shop(string $method, string $path, array $data = [], ?string $ip = null, string $shop = 'cta', bool $wait = true): TestResponse
    {
        $this->defaultCookies = [];

        if ($this->member !== null) {
            $this->withCookie(MemberCookie::NAME, $this->member);
        }

        if ($method !== 'GET' && $wait) {
            $this->travel(7)->seconds();
        }

        $this->app['auth']->forgetGuards();
        $this->app['cookie']->flushQueuedCookies();
        $url = "http://{$shop}.localhost{$path}";
        $this->serverVariables = $ip === null ? [] : ['REMOTE_ADDR' => $ip];
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $data);

        tenancy()->end();
        $cookie = $response->getCookie(MemberCookie::NAME);

        if ($cookie !== null) {
            $this->member = (string) $cookie->getValue() === '' ? null : (string) $cookie->getValue();
        }

        return $response;
    }
}
