<?php

declare(strict_types=1);

use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\PluginRoutes;
use App\Storefront\Http\Controllers\AccountController;
use App\Storefront\Http\Controllers\AppAssetController;
use App\Storefront\Http\Controllers\AppPageController;
use App\Storefront\Http\Controllers\CartController;
use App\Storefront\Http\Controllers\CheckoutController;
use App\Storefront\Http\Controllers\DemoController;
use App\Storefront\Http\Controllers\MediaController;
use App\Storefront\Http\Controllers\MemberAddressController;
use App\Storefront\Http\Controllers\MemberPasskeyController;
use App\Storefront\Http\Controllers\MemberSecurityController;
use App\Storefront\Http\Controllers\OrderController;
use App\Storefront\Http\Controllers\PageController;
use App\Storefront\Http\Controllers\PasswordController;
use App\Storefront\Http\Controllers\PostalController;
use App\Storefront\Http\Controllers\ProductController;
use App\Storefront\Http\Controllers\SeoController;
use App\Storefront\Http\Controllers\ThemePreviewController;
use App\Storefront\Http\Controllers\ThemeStyleController;
use App\Storefront\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| テナントの店頭のルート（ADR 0018）
|--------------------------------------------------------------------------
| ドメインでテナントを特定し、中央ドメインからは触れない（'storefront' ミドルウェア群。bootstrap/app.php）。
| 以降は必ずテナント文脈の中で動く（テナント DB をまたがない）。画面は今のテーマが描き、テーマは DB に触らない。
| 店頭の経路は公開が基本なので、足すたびに公開の印と理由を付ける（RouteAudit が見張る）。
| 経路の引数でモデルを結びつけない（ID は文字列で受け、テナント文脈の中で探す）。
*/

// 公開: 商品の写真（公開中の商品のものだけ返す）とショップの写真（置き場に今あるものだけ。ヒーローなどショップに出すための写真。ADR 0028）。セッションを持たない群（bootstrap/app.php の storefront-media）
Route::middleware(['storefront-media', 'public'])->group(function () {
    Route::get('/media/listings/{imageId}/{variant}', [MediaController::class, 'listing'])
        ->whereUlid('imageId')->whereIn('variant', ['large', 'medium'])->name('storefront.media.listing');
    Route::get('/media/shop/{mediaId}/{variant}', [MediaController::class, 'shop'])
        ->whereUlid('mediaId')->whereIn('variant', ['large', 'medium'])->name('storefront.media.shop');

    // 公開: App のスクリプトのファイル（ADR 0034）。店が使っている App の、マニフェストで宣言した assets/ の中の .js だけ（審査した App のコード）。
    // ショップの画面に核が <script src> で差し込むためのもので、ほかに中身は無い。名前とファイルは決まった形だけ（道筋の外へ出る書き方を経路で断る）
    Route::get('/apps/{plugin}/{file}', AppAssetController::class)
        ->where('plugin', '[a-z][a-z0-9-]{1,31}')->where('file', '[a-z0-9][a-z0-9_-]{0,63}\.js')->name('storefront.apps.asset');

    // 公開: 検索エンジン向けの robots.txt とサイトマップ（ADR 0061）。読むのは検索エンジンで、ログインは無い。セッションも Cookie も持たない。
    // 準備中のショップでも関所の外（この群に関所は無い）: robots.txt は全部を止め、サイトマップは 404 を返す（受け口が状態を見る）
    Route::get('/robots.txt', [SeoController::class, 'robots'])->name('storefront.robots');
    Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->middleware('throttle:storefront')->name('storefront.sitemap');

    // 公開: 店が選んだ見た目（色の組と主な色）の小さな CSS（ADR 0097）。テーマの見た目の値（#rrggbb の色）だけで、個人情報も店の秘密も含まない。
    // 公開した値から作り、印（中身のハッシュ）が合うときだけ返す（長くキャッシュする）。準備中のショップでも関所の外（「準備中です」の画面もテーマの色で描く）
    Route::get('/theme/style-{mark}.css', [ThemeStyleController::class, 'published'])->where('mark', '[0-9a-f]{16}')->name('storefront.theme.style');
    // 公開: 見た目の下書きの CSS（編集の画面の真ん中のプレビュー）。署名（店・テーマ・期限、用途の違う鍵）を受け口が確かめ、合わなければ中身の無い 404。
    // 秘密の道筋（secret-link）: 参照元を渡さない・残さない・検索に載せないは核がまとめて掛ける
    Route::get('/preview/themes/{theme}/style.css', [ThemeStyleController::class, 'preview'])
        ->where('theme', '[a-z][a-z0-9-]{1,31}')->middleware('secret-link')->name('storefront.preview.theme.style');
});

// 公開: 決済代行からの通知（ADR 0019）。送るのは Stripe で、ログインは無い。セッションも CSRF も持たない群で受け、
// 店の Webhook の署名の鍵で確かめる（署名の無い・違う・古い通知は 400）。接続元ごとの回数制限つき
Route::middleware(['storefront-media', 'public', 'throttle:webhooks'])->group(function () {
    Route::post('/webhooks/stripe', [WebhookController::class, 'stripe'])->name('storefront.webhooks.stripe');
});

// 店頭の画面はほとんど公開（会員だけの経路は下の auth:customer の群。公開の群に混ぜない）。
// 準備中のショップでは、群の最後の関所（RequireShopAccess）が、パスワードを入れた人のほかを「準備中です」へ送る
Route::middleware(['storefront', 'public'])->group(function () {
    // 公開: 準備中のショップの「準備中です」とパスワードの欄。関所が通す経路はこの 2 つだけ。
    // 送るのは CSRF と接続元ごとの回数制限つき（間違いの回数は、この店での接続元ごととショップごとに受け口でも止める）
    Route::get('/password', [PasswordController::class, 'show'])->name('storefront.password');
    Route::post('/password', [PasswordController::class, 'unlock'])->middleware('throttle:storefront-password')->name('storefront.password.unlock');

    // 公開: 店のトップ（公開中の商品だけ）
    Route::get('/', [PageController::class, 'home'])->name('storefront.home');

    // 公開: 商品の一覧と詳細（公開中の商品だけ）。すべて・1 つの商品・カテゴリごと
    Route::get('/products', [ProductController::class, 'index'])->name('storefront.products');
    Route::get('/products/{listingId}', [ProductController::class, 'show'])->whereUlid('listingId')->name('storefront.product');
    // 公開: 商品のプレビュー。管理画面が作る署名つきの期限つきのリンク（30 分）で、その商品の画面だけを状態に関係なく開く。
    // 署名が違う・期限切れ・ほかの店・ほかの商品は中身を出さない。準備中のショップでも開ける（関所の外の一覧に入れてある）。App は差し込まない
    // 秘密の道筋（secret-link。署名は用途ごとの鍵で受け口が確かめる）: 参照元を渡さない・残さない・検索に載せないは核がまとめて掛ける
    Route::get('/preview/products/{listingId}', [ProductController::class, 'preview'])->whereUlid('listingId')->middleware('secret-link')->name('storefront.preview');
    // 公開: テーマの下書きのプレビュー（ADR 0039 区切り 2）。管理画面の編集の画面が作る署名つきの期限つきのリンク（30 分）で、
    // そのテーマのそのページを下書きの区画で描き、管理画面の枠（iframe）にだけ入れられる（frame-ancestors）。署名が違う・期限切れ・ほかの店・
    // 入っていないテーマ・無いページは中身を出さない。準備中のショップでも開ける（関所の外の一覧に入れてある）。App は差し込まない
    Route::get('/preview/themes/{theme}/{page}', [ThemePreviewController::class, 'show'])
        ->where(['theme' => '[a-z][a-z0-9-]{1,31}', 'page' => '[a-z][a-z0-9_-]{0,31}'])->middleware('secret-link')->name('storefront.preview.theme');
    // 公開: 区画の試し見の断片。下書きのプレビューの画面に置いた署名つきの道筋（画面のリンクと同じ店・テーマ・ページ・期限、鍵は別）で、
    // そのページに置ける種類 × パターンの区画 1 つを、テーマの見本の値（写真は無地の仮の写真）で描く。DB には何も書かない（読むだけ）。
    // 編集の部品（editor.js）が同じオリジンから読み、プレビューの中に仮に差し込む。断るときはどれも中身の無い 404（どれで断ったかを見せない）。準備中のショップでも開ける
    Route::get('/preview/themes/{theme}/{page}/section', [ThemePreviewController::class, 'trial'])
        ->where(['theme' => '[a-z][a-z0-9-]{1,31}', 'page' => '[a-z][a-z0-9_-]{0,31}'])->middleware('secret-link')->name('storefront.preview.theme.section');
    // 公開: 自由なページ（ADR 0039 区切り 3。私たちについて・キャンペーンなど店の人が区画で作るページ）。公開したページだけを描き、下書き・ゴミ箱・無いページは「見つかりません」。
    // 住所は /pages/{slug}（今の経路とぶつからない。slug は英小文字・数字・ハイフンで、予約語は作るときに断る）。準備中のショップでは関所の内側
    Route::get('/pages/{slug}', [PageController::class, 'page'])->where('slug', '[a-z0-9]+(-[a-z0-9]+)*')->name('storefront.page');
    Route::get('/categories/{slug}', [ProductController::class, 'category'])->where('slug', '[a-z0-9]+(-[a-z0-9]+)*')->name('storefront.category');

    // 公開: カート（ADR 0021）。お客さんのログインは無く、カートは合い言葉の Cookie で紐付ける。書き込みは CSRF と接続元ごとの回数制限つき
    Route::get('/cart', [CartController::class, 'show'])->name('storefront.cart');
    Route::middleware('throttle:storefront-cart')->group(function () {
        Route::post('/cart/items', [CartController::class, 'add'])->name('storefront.cart.add');
        // カートの行で指す（同じ商品でも種類が違えば別の行。ADR 0036）
        Route::post('/cart/items/{lineId}/quantity', [CartController::class, 'quantity'])->whereUlid('lineId')->name('storefront.cart.quantity');
        Route::post('/cart/items/{lineId}/remove', [CartController::class, 'remove'])->whereUlid('lineId')->name('storefront.cart.remove');
        Route::post('/cart/coupon/remove', [CartController::class, 'removeCoupon'])->name('storefront.cart.coupon.remove');
    });
    // クーポンのコードを入れる（ADR 0054）。当てずっぽうを防ぐため、カートより回数を絞る
    Route::post('/cart/coupon', [CartController::class, 'coupon'])->middleware('throttle:storefront-coupon')->name('storefront.cart.coupon');

    // 公開: 注文の入力と最終確認（会員登録なしで買える）。入力はセッションに置き、DB には書かない。送るのは CSRF と回数制限つき。
    // 入力と確かめは名前・届け先・メールを出すので、ブラウザが画面を開く時だけ通す（navigation-only。ADR 0088。タグの JavaScript の fetch に中身を渡さない）。
    // 送るのも画面のフォームからだけ（同じオリジンの fetch で入力をすり替えさせない・確定させない）。購入の完了（complete）はタグを差し込む画面なので印を付けない
    Route::middleware('navigation-only')->group(function () {
        Route::get('/checkout', [CheckoutController::class, 'form'])->name('storefront.checkout');
        Route::post('/checkout', [CheckoutController::class, 'submit'])->middleware('throttle:storefront-cart')->name('storefront.checkout.submit');
        Route::get('/checkout/confirm', [CheckoutController::class, 'confirm'])->name('storefront.checkout.confirm');
        Route::post('/checkout/confirm', [CheckoutController::class, 'place'])->middleware('throttle:storefront-cart')->name('storefront.checkout.place');
        Route::post('/checkout/pay', [CheckoutController::class, 'pay'])->middleware('throttle:storefront-cart')->name('storefront.checkout.pay');
    });
    Route::get('/checkout/complete', [CheckoutController::class, 'complete'])->name('storefront.checkout.complete');

    // 公開: ご注文の確認（会員が無い間）。注文番号とメールで照会する（接続元ごとの回数制限、注文番号ごとの制限は受け口で）。
    // 注文の様子は、確認のメールの期限つきの署名つきリンクか、このセッションで照会した注文だけ（秘密の道筋。どちらかを受け口が確かめる）
    Route::get('/orders', [OrderController::class, 'lookup'])->name('storefront.orders.lookup');
    Route::post('/orders', [OrderController::class, 'find'])->middleware('throttle:storefront-order-lookup')->name('storefront.orders.find');
    // 注文の様子は名前・届け先を出すので、ブラウザが画面を開く時だけ（navigation-only。ADR 0088）。URL に署名を持つので秘密の道筋の宣言も（secret-link。ADR 0081）
    Route::get('/orders/{orderId}', [OrderController::class, 'show'])->where('orderId', '[0-9A-Za-z]{26}')->middleware(['secret-link', 'navigation-only'])->name('storefront.orders.show');

    // 公開: 会員のログイン（ADR 0044 の 3）。メールに 6 桁のコードを送り、コードで入る（登録の画面は別に無い）。
    // 送るのは CSRF と接続元ごとの回数制限つき（メール × 接続元・接続元ごとの送る数と、メールごとの外れの数は MemberLogin で）。
    // メールの有る無しで答えを変えない。どのメールに送ったかはこのブラウザのセッションに置く
    Route::get('/account/login', [AccountController::class, 'login'])->name('storefront.account.login');
    Route::post('/account/login', [AccountController::class, 'send'])->middleware('throttle:storefront-member')->name('storefront.account.send');
    // コードを入れる画面は送ったメールのアドレスを出すので、ブラウザが画面を開く時だけ（ADR 0088）
    Route::get('/account/login/code', [AccountController::class, 'code'])->middleware('navigation-only')->name('storefront.account.code');
    Route::post('/account/login/code', [AccountController::class, 'verify'])->middleware('throttle:storefront-member')->name('storefront.account.verify');

    // 公開: パスキーでログイン（区切り 3c）。オプション（チャレンジ）はこのブラウザのセッションに 1 回分。確かめられなければ理由は言わない
    Route::post('/account/login/passkey/options', [MemberPasskeyController::class, 'requestOptions'])->middleware('throttle:storefront-member')->name('storefront.account.login.passkey.options');
    Route::post('/account/login/passkey', [MemberPasskeyController::class, 'login'])->middleware('throttle:storefront-member')->name('storefront.account.login.passkey');

    // 公開: 郵便番号から住所。注文の入力の補助。外に送るのは郵便番号だけ。接続元ごとの回数制限つき
    Route::get('/postal/{code}', [PostalController::class, 'show'])->where('code', '[0-9]{7}')->middleware('throttle:storefront-postal')->name('storefront.postal');

    // 公開: 特定商取引法に基づく表記（法律で常に見せる情報）
    Route::get('/legal/commerce', [PageController::class, 'legal'])->name('storefront.legal');

    // 公開: プライバシーポリシー（ADR 0094。店の人が設定で書いた本文。無ければ「見つかりません」）。準備中のショップでは関所の内側
    // （まだお客さんの情報を受けない。特商法の表記と違い、関所の外に出す理由が無い）。住所は予約語なので自由なページとは重ならない
    Route::get('/privacy', [PageController::class, 'privacy'])->name('storefront.privacy');

    // 公開: 外部送信に関する公表（ADR 0034。電気通信事業法の外部送信規律で、利用者が見られるようにしておく情報）。その店で使っている App の分だけ
    Route::get('/legal/external', [AppPageController::class, 'disclosure'])->name('storefront.disclosure');

    // 公開: 同意（Cookie の設定。ADR 0034）。お客さんのログインは無く、決めたことはこのブラウザの Cookie にだけ残す。送るのは CSRF と回数制限つき
    Route::get('/consent', [AppPageController::class, 'consent'])->name('storefront.consent');
    Route::post('/consent', [AppPageController::class, 'updateConsent'])->middleware('throttle:storefront-consent')->name('storefront.consent.update');

    // 公開: デモの店だけの画面（ADR 0086。ほかの店では「見つかりません」）。送るはずだったメールは、このセッションの来訪者の受け箱だけを見せる。
    // デモの会員でログインは、この来訪者だけのアドレスの会員で入る（ほかの来訪者と分け合わない）。CSRF と接続元ごとの回数制限つき（受け口でも 1 時間の数を数える）。
    // 受け箱はログインのコード・注文の名前と届け先を写したメールを出すので、ブラウザが画面を開く時だけ（navigation-only。ADR 0088）。
    // デモの会員でログインも帯のフォームから送る時だけ（タグの JavaScript の fetch で来訪者を会員にして、マイページを開かせない・回数の枠を減らさせない）
    Route::get('/demo/mails', [DemoController::class, 'mails'])->middleware('navigation-only')->name('storefront.demo.mails');
    Route::post('/demo/member', [DemoController::class, 'member'])->middleware(['navigation-only', 'throttle:storefront-member'])->name('storefront.demo.member');

    // 公開: 一覧に無い経路はテーマの「見つかりません」
    Route::fallback([PageController::class, 'missing']);
});

// 会員だけ（ADR 0044 の 3）: マイページとログアウト。印（Cookie）が無い・効かないときはログインの画面へ。
// 準備中のショップでは、群の最後の関所の内側（パスワードを入れた人だけ）。
// どれもブラウザが画面を開く時・画面のフォームから送る時だけ通す（navigation-only。ADR 0088。App のタグの JavaScript に読ませない・送らせない）。
// 画面の中の核の JavaScript が読むパスキーのオプションだけは、下の群で画面の鍵も通す
Route::middleware(['storefront', 'auth:customer', 'navigation-only'])->group(function () {
    Route::get('/account', [AccountController::class, 'show'])->name('storefront.account');
    Route::post('/account/logout', [AccountController::class, 'logout'])->name('storefront.account.logout');

    // 届け先（区切り 3a）。いくつも持て、いつもの届け先を注文の入力に入れる。ほかの会員の届け先の ID は見つからないのと同じ
    Route::get('/account/addresses', [MemberAddressController::class, 'index'])->name('storefront.account.addresses');
    Route::post('/account/addresses', [MemberAddressController::class, 'store'])->middleware('throttle:storefront-cart')->name('storefront.account.addresses.store');
    Route::get('/account/addresses/{addressId}', [MemberAddressController::class, 'edit'])->whereUlid('addressId')->name('storefront.account.address');
    Route::middleware('throttle:storefront-cart')->group(function () {
        Route::post('/account/addresses/{addressId}', [MemberAddressController::class, 'update'])->whereUlid('addressId')->name('storefront.account.address.update');
        Route::post('/account/addresses/{addressId}/delete', [MemberAddressController::class, 'destroy'])->whereUlid('addressId')->name('storefront.account.address.delete');
        Route::post('/account/addresses/{addressId}/default', [MemberAddressController::class, 'makeDefault'])->whereUlid('addressId')->name('storefront.account.address.default');
    });

    // 本人の再確認（区切り 3a）: 大事な操作の前に、その場でメールに新しいコードを送る。送るのはボタンを押した時だけ。回数はログインと同じ
    Route::get('/account/confirm', [MemberSecurityController::class, 'confirm'])->name('storefront.account.confirm');
    Route::post('/account/confirm/send', [MemberSecurityController::class, 'send'])->middleware('throttle:storefront-member')->name('storefront.account.confirm.send');
    Route::post('/account/confirm', [MemberSecurityController::class, 'verify'])->middleware('throttle:storefront-member')->name('storefront.account.confirm.verify');

    // メールの変更（区切り 3b）: 再確認の後だけ。新しいアドレスへコードを送り、合えば変えて前のアドレスへ知らせる
    Route::get('/account/email', [MemberSecurityController::class, 'emailForm'])->name('storefront.account.email');
    Route::post('/account/email', [MemberSecurityController::class, 'emailSend'])->middleware('throttle:storefront-member')->name('storefront.account.email.send');
    Route::post('/account/email/verify', [MemberSecurityController::class, 'emailVerify'])->middleware('throttle:storefront-member')->name('storefront.account.email.verify');

    // パスキー（区切り 3c）: 一覧・足す・消すは再確認の後だけ。本人の再確認にもパスキーを使える（その会員のパスキーだけ）
    Route::get('/account/passkeys', [MemberPasskeyController::class, 'index'])->name('storefront.account.passkeys');
    Route::middleware('throttle:storefront-member')->group(function () {
        Route::post('/account/passkeys', [MemberPasskeyController::class, 'store'])->name('storefront.account.passkeys.store');
        Route::post('/account/passkeys/{passkeyId}/delete', [MemberPasskeyController::class, 'destroy'])->whereUlid('passkeyId')->name('storefront.account.passkey.delete');
        Route::post('/account/confirm/passkey', [MemberPasskeyController::class, 'confirm'])->name('storefront.account.confirm.passkey');
    });

    // 退会（区切り 3a）: 再確認の後だけ。会員の印・届け先を消し、台帳はゲストの形に（注文の記録は残す）
    Route::get('/account/leave', [MemberSecurityController::class, 'leaveForm'])->name('storefront.account.leave');
    Route::post('/account/leave', [MemberSecurityController::class, 'leave'])->name('storefront.account.leave.submit');
});

// 会員だけ: パスキーのオプション（区切り 3c）。パスキーの画面と再確認の画面の核の JavaScript（passkeys.js）が fetch で読む。
// 画面を開く要求のほかは、その画面に書いた画面の鍵（PageKey。タグを差し込まない画面にだけ書く）の付いた要求だけ通す（ADR 0088）
Route::middleware(['storefront', 'auth:customer', 'navigation-only:page-key', 'throttle:storefront-member'])->group(function () {
    Route::post('/account/passkeys/options', [MemberPasskeyController::class, 'createOptions'])->name('storefront.account.passkeys.options');
    Route::post('/account/confirm/passkey/options', [MemberPasskeyController::class, 'requestOptions'])->name('storefront.account.confirm.passkey.options');
});

// 公開: プラグインのショップの画面（ADR 0025）。/p/<名前> の下だけ、使わない店では 404。印と置き場所は PluginRoutes が決める
PluginRoutes::storefront(app(PluginRegistry::class));
