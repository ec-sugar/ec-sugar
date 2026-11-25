<?php

declare(strict_types=1);

namespace App\Storefront\View;

use App\Core\Plugin\Manifest\PageKind;
use App\Core\Section\Models\ShopPage;
use App\Core\Section\Services\ShopPageService;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Access\PageKey;
use App\Storefront\Apps\AppsView;
use App\Storefront\Apps\PurchaseData;
use App\Storefront\Apps\StorefrontApps;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartService;
use App\Storefront\Cart\CartView;
use App\Storefront\Http\Middleware\NavigationOnly;
use App\Storefront\Http\Middleware\StorefrontSecurityHeaders;
use App\Storefront\Members\Member;
use App\Storefront\Seo\SeoBuilder;
use App\Storefront\Seo\SeoMeta;
use Illuminate\Http\Response;

/**
 * 今のテーマの画面を描く。テーマには、決まった名前の画面に、決まった形のデータ（$theme・$shop と画面ごとのデータ）だけを渡す。
 */
final class StorefrontView
{
    public const string SCRIPT = 'resources/storefront/cart.js';

    /** 検索に載せない画面の X-Robots-Tag（商品のプレビュー） */
    public const string NO_INDEX = 'noindex, nofollow';

    /**
     * 画面の種類（ADR 0034）。App のスクリプトを差し込んでよい画面だけをここに書く。書いていない画面（注文の入力と確認・ご注文の確認と状況・
     * エラー・核の画面、これから足す画面）は個人情報の画面として扱い、差し込まない（書き忘れは差し込まない側に倒れる）
     *
     * @var array<string, PageKind>
     */
    public const array PAGE_KINDS = [
        'home' => PageKind::General,
        'products' => PageKind::General,
        'product' => PageKind::General,
        'cart' => PageKind::General,
        'legal' => PageKind::General,
        // 自由なページ（ADR 0039 区切り 3）。店の人が区画で作る案内のページで、お客さんの情報は入らない
        'page' => PageKind::General,
        // プライバシーポリシー（ADR 0094）。店の人が書いた案内の文だけで、お客さんの情報は入らない
        'privacy' => PageKind::General,
        'checkout-complete' => PageKind::Purchase,
    ];

    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly ShopSettingsService $settings,
        private readonly CartService $carts,
        private readonly SectionResolver $sections,
        private readonly StorefrontApps $apps,
        private readonly ShopPageService $pages,
        private readonly SeoBuilder $seo,
        private readonly ThemeContentService $content,
    ) {}

    public static function kindOf(string $page): PageKind
    {
        return self::PAGE_KINDS[$page] ?? PageKind::Private;
    }

    private function cartCount(): int
    {
        $token = CartCookie::token(request());

        return $token === null ? 0 : $this->carts->count($this->carts->find($token));
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @return list<PageLink>
     */
    private function navPages(?ShopPage $current): array
    {
        return $this->pages->navigation()->map(static fn (ShopPage $page): PageLink => new PageLink(
            $page->title, route('storefront.page', ['slug' => $page->slug], false), $current?->id === $page->id,
        ))->values()->all();
    }

    /**
     * ヘッダーの会員の入口（ADR 0044 の 3）。会員かどうかは今の店で引いた会員か（belongsHere）で確かめる。
     * プレビューでは会員を引かない（運営が見る画面。会員の印の期限を延ばさない）。メールなど誰かが分かる値は渡さない
     */
    private function account(bool $preview): AccountLink
    {
        $member = $preview ? null : request()->user('customer');
        $current = request()->routeIs('storefront.account', 'storefront.account.*');

        return $member instanceof Member && $member->belongsHere()
            ? new AccountLink(route('storefront.account', [], false), 'マイページ', true, $current)
            : new AccountLink(route('storefront.account.login', [], false), 'ログイン', false, $current);
    }

    private function shop(Tenant $tenant): ShopView
    {
        return ShopView::of($tenant, $this->settings->legal(), $this->settings->hasPrivacyPolicy() ? route('storefront.privacy', [], false) : null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  ?PurchaseData  $purchase  購入の完了の画面で App に渡す値（それ以外の画面では使わない）
     */
    public function render(string $page, array $data = [], int $status = 200, ?PurchaseData $purchase = null): Response
    {
        $tenant = Tenant::current();
        $theme = $this->themes->forTenant($tenant);
        $kind = self::kindOf($page);
        // 検索と共有の見え方（ADR 0061）。載せる画面（トップ・商品・一覧・特商法の表記）だけ核が題と説明を決め、ほかは載せない
        $seo = $this->seo->forPage($page, $data, $status, (string) $tenant->getAttribute('name'));

        return $this->respond($theme->view($page), $tenant, $theme, $kind, $kind === PageKind::Purchase ? $purchase : null, $data, $status, sectionPage: $page === 'home' ? 'home' : null, seo: $seo);
    }

    /**
     * 自由なページ（ADR 0039 区切り 3）。公開していることは呼ぶ側が確かめる。テーマの画面 page で描き、無ければ核の既定の画面（storefront.page）。
     * $page に公開した区画の並び（題と URL つき）を渡す
     */
    public function renderFreePage(ShopPage $page): Response
    {
        $tenant = Tenant::current();
        $theme = $this->themes->forTenant($tenant);

        return $this->respond(self::freePageView($theme), $tenant, $theme, self::kindOf('page'), null, [], 200, sectionPage: $page->pageName(), freePage: $page,
            seo: $this->seo->forFreePage($page, (string) $tenant->getAttribute('name')));
    }

    /**
     * プライバシーポリシー（ADR 0094）。本文があることは呼ぶ側が確かめる。テーマの画面 privacy で描き、無ければ核の既定の画面（storefront.privacy。
     * テーマの外枠の中に題と本文を描く）。テーマ API の付け足し（画面は任意）なので、今のテーマは何もしなくても出せる
     */
    public function renderPrivacy(PrivacyPolicyView $privacy): Response
    {
        $tenant = Tenant::current();
        $theme = $this->themes->forTenant($tenant);
        $view = view()->exists($theme->view('privacy')) ? $theme->view('privacy') : 'storefront.privacy';
        $seo = $this->seo->forPage('privacy', [], 200, (string) $tenant->getAttribute('name'));

        return $this->respond($view, $tenant, $theme, self::kindOf('privacy'), null, ['privacy' => $privacy], 200, seo: $seo);
    }

    /**
     * 自由なページを描く画面の名前（テーマの page、無ければ核の既定の画面）
     */
    public static function freePageView(ThemeDefinition $theme): string
    {
        return view()->exists($theme->view('page')) ? $theme->view('page') : 'storefront.page';
    }

    /**
     * 核が描く画面（外部送信に関する公表・同意。ADR 0034）。テーマの外枠（layout）の中に描き、個人情報の画面と同じく差し込まない
     *
     * @param  array<string, mixed>  $data
     */
    public function renderCore(string $page, array $data = []): Response
    {
        $tenant = Tenant::current();

        return $this->respond("storefront.{$page}", $tenant, $this->themes->forTenant($tenant), PageKind::Private, null, $data, 200);
    }

    /**
     * App の画面（ADR 0050。定期便の申し込みなど）。App の Blade（自分の名前空間の画面）をテーマの外枠の中に描く。
     * 個人情報を扱う画面として、App のタグは差し込まない
     *
     * @param  array<string, mixed>  $data
     */
    public function renderApp(string $view, array $data = []): Response
    {
        if (preg_match('/\A[a-z][a-z0-9-]{1,31}::[a-z][a-z0-9.-]{0,63}\z/', $view) !== 1) {
            throw new \InvalidArgumentException('App の画面の名前の形が違います');
        }

        $tenant = Tenant::current();

        return $this->respond($view, $tenant, $this->themes->forTenant($tenant), PageKind::Private, null, $data, 200)->header('Cache-Control', 'no-store, private');
    }

    /**
     * App の検索に載せるページ（ADR 0072。お知らせ・ブログの一覧と記事など）。テーマに $page の画面があればそれで、無ければ App の既定の画面
     * （$fallback。App の名前空間の Blade）で、テーマの外枠の中に描く。自由なページと同じく、お客さんの情報の入らない案内のページとして
     * App のタグを差し込む（ふつうの画面）。検索と共有の見え方は核が作ったもの（SeoBuilder::forAppPage）を渡す。
     * $preview（管理画面からの署名つきのプレビュー。署名は呼ぶ側が確かめる）は商品のプレビューと同じ扱い: プレビューの帯・App を差し込まない・
     * 検索に載せない・どこにも残さない・URL を外へ渡さない
     *
     * @param  string  $page  テーマの画面の名前（news・news-post など。英小文字・数字・ハイフン）
     * @param  array<string, mixed>  $data
     */
    public function renderAppPage(string $page, string $fallback, array $data, SeoMeta $seo, bool $preview = false): Response
    {
        if (preg_match('/\A[a-z][a-z0-9-]{0,31}\z/', $page) !== 1 || preg_match('/\A[a-z][a-z0-9-]{1,31}::[a-z][a-z0-9.-]{0,63}\z/', $fallback) !== 1) {
            throw new \InvalidArgumentException('App のページの画面の名前の形が違います');
        }

        $tenant = Tenant::current();
        $theme = $this->themes->forTenant($tenant);
        $view = view()->exists($theme->view($page)) ? $theme->view($page) : $fallback;

        if (! $preview) {
            return $this->respond($view, $tenant, $theme, PageKind::General, null, $data, 200, seo: $seo);
        }

        $response = $this->respond($view, $tenant, $theme, PageKind::Private, null, $data, 200, preview: true, seo: SeoMeta::hidden($seo->siteName));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', self::NO_INDEX);
        StorefrontSecurityHeaders::withoutReferrer(request());

        return $response;
    }

    /**
     * 準備中のショップの「準備中です」の画面。テーマに password の画面があればそれで、無ければ核の既定の画面で描く。
     * テーマの外枠（メニュー・カート・フッター）は使わない（準備中の店の中身を見せない）。App のスクリプトは差し込まない
     */
    public function renderPassword(): Response
    {
        $tenant = Tenant::current();
        $theme = $this->themes->forTenant($tenant);
        $view = view()->exists($theme->view('password')) ? $theme->view('password') : 'storefront.password';

        // メニューのページも渡さない（準備中の店の中を見せない）
        return $this->respond($view, $tenant, $theme, PageKind::Private, null, [], 200, menu: false);
    }

    /**
     * 商品のプレビュー。署名を確かめた後で呼ぶ。テーマの画面で描き、App は差し込まない（個人情報の画面と同じ扱い）。
     * 画面の上にプレビューの帯（核の部品）、検索に載せない（X-Robots-Tag）、どこにも残さない（no-store）
     *
     * @param  array<string, mixed>  $data
     */
    public function renderPreview(string $page, array $data): Response
    {
        $tenant = Tenant::current();
        $theme = $this->themes->forTenant($tenant);
        $response = $this->respond($theme->view($page), $tenant, $theme, PageKind::Private, null, $data, 200, preview: true);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', self::NO_INDEX);
        // URL の署名を、同じ店の次の画面（そこで動く App のタグ）や外へ渡さない
        StorefrontSecurityHeaders::withoutReferrer(request());

        return $response;
    }

    /**
     * テーマの下書きのプレビュー（ADR 0039 区切り 2）。署名を確かめた後で呼ぶ。管理画面の編集の画面の枠（iframe）の中に、
     * そのテーマのそのページを、使うバージョンの**下書き**の区画で描く（店で使っていないテーマも。切り替える前に用意できる）。
     * 商品のプレビューと同じく App は差し込まず、検索に載せず、どこにも残さず、URL を外へ渡さない。
     * 枠に入れてよい親は管理画面のオリジンだけ（frame-ancestors）。核の部品が編集の印（data-sugar-editor）を描き、
     * 店頭の核の JS（resources/storefront/editor.js）が区画を押した時に親へ知らせる。カートに入れる所とリンクは押しても動かない
     *
     * 自由なページ（ADR 0039 区切り 3）は、公開していない（下書きの）ページも描く（$freePage。画面はテーマの page か核の既定の画面）
     *
     * 区画の試し見の断片の道筋（$trial。同じオリジンの署名つき）を編集の印に添える。
     * 見た目（ADR 0097）は下書きの値の CSS（$style。同じオリジンの署名つきの道筋）を読む（編集の画面が色を変えるたびに読み直させる）
     *
     * @param  string  $page  区画を積むページの名前（home か page-{ID}）
     * @param  array<string, mixed>  $data
     */
    public function renderDraftPreview(ThemeDefinition $theme, string $page, string $editorOrigin, array $data, ?ShopPage $freePage = null, ?string $trial = null, ?string $style = null): Response
    {
        $tenant = Tenant::current();
        $view = $freePage === null ? $theme->view($page) : self::freePageView($theme);
        $response = $this->respond($view, $tenant, $theme, PageKind::Private, null, $data, 200, preview: true, sectionPage: $page, editor: $editorOrigin, freePage: $freePage, trial: $trial, style: $style);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', self::NO_INDEX);
        StorefrontSecurityHeaders::withoutReferrer(request());
        StorefrontSecurityHeaders::framedBy(request(), $editorOrigin);

        return $response;
    }

    /**
     * 区画の試し見の断片。署名と種類・パターンを確かめた後で呼ぶ。区画 1 つ（$section。見本の値）だけを、テーマの部品で描いた HTML。
     * 外枠（layout）も App も描かない。編集の部品（editor.js）が同じオリジンから読み、プレビューの中に仮に差し込む（枠には入れないので frame-ancestors は 'none' のまま）。
     * 検索に載せず、どこにも残さず、URL を外へ渡さない。部品が読む値（$shop・$sections・$page・$listings など）は下書きのプレビューと同じ
     *
     * @param  array<string, mixed>  $data
     */
    public function renderSectionTrial(ThemeDefinition $theme, string $page, string $editorOrigin, SectionView $section, array $data, ?ShopPage $freePage = null): Response
    {
        $response = $this->respond('storefront.section-trial', Tenant::current(), $theme, PageKind::Private, null, ['trial' => $section] + $data, 200,
            preview: true, sectionPage: $page, editor: $editorOrigin, freePage: $freePage, menu: false);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', self::NO_INDEX);
        StorefrontSecurityHeaders::withoutReferrer(request());

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  bool  $preview  商品のプレビューの画面か（テーマには $preview で渡す）
     * @param  string|null  $sectionPage  区画を積むページの名前（home か page-{ID}。その並びを $page で渡す）
     * @param  string|null  $editor  テーマの下書きのプレビューなら、枠の親（管理画面のオリジン）。区画は下書きで描く
     * @param  ShopPage|null  $freePage  自由なページなら、そのページ（$page に題と URL を付ける）
     * @param  bool  $menu  メニューに載せるページを渡すか（準備中の画面では渡さない）
     * @param  string|null  $trial  区画の試し見の断片の道筋（下書きのプレビューだけ）
     * @param  SeoMeta|null  $seo  検索と共有の見え方（ADR 0061）。無ければ検索に載せない画面
     * @param  string|null  $style  見た目の下書きの CSS の道筋（下書きのプレビューだけ。ADR 0097）
     */
    private function respond(string $view, Tenant $tenant, ThemeDefinition $theme, PageKind $kind, ?PurchaseData $purchase, array $data, int $status, bool $preview = false, ?string $sectionPage = null, ?string $editor = null, ?ShopPage $freePage = null, bool $menu = true, ?string $trial = null, ?SeoMeta $seo = null, ?string $style = null): Response
    {
        $shop = $this->shop($tenant);
        // 個人情報の画面の印（NavigationOnly。ADR 0088）の経路は、どの画面の名前で描いても App を差し込まない
        // （画面の鍵〔PageKey〕はこの画面にだけ書くので、タグの JavaScript と同じ画面に置かない）
        $kind = request()->attributes->get(NavigationOnly::PASSED) === true ? PageKind::Private : $kind;

        // 区画の中身をすべての画面に（お知らせの帯などは外枠が使う。ADR 0028）。下書きのプレビューでは下書きの値で。
        // プレビューと個人情報の画面では、App の区画（ADR 0040）は外へ何も読み込まない
        $sections = $this->sections->forTheme($theme, draft: $editor !== null, live: ! $preview && $kind !== PageKind::Private);
        // App の区画が枠に読み込む宛先（この応答で出す区画の分だけ。CSP の frame-src に足す）
        $frames = [];
        // 区画を積むページ（トップと自由なページ。ADR 0039）には、公開した並び（プレビューでは下書き）を渡す（テーマが部品で描く）
        if ($sectionPage !== null) {
            $page = $sections->page($sectionPage);
            $data['page'] = $freePage === null ? $page : $page->withTitle($freePage->title, route('storefront.page', ['slug' => $freePage->slug], false));

            foreach ($page as $section) {
                if ($section->app?->connects() === true) {
                    $frames = [...$frames, ...$section->app->frames];
                }
            }
        }
        // 差し込む App（ADR 0034）。足す CSP は、この応答の防御ヘッダが要求から読む（StorefrontSecurityHeaders）。プレビューでは差し込まない
        $apps = $this->apps->forPage(request(), $preview ? PageKind::Private : $kind, $purchase, array_values(array_unique($frames)));
        $apps = $preview ? $apps->previewing($editor, $trial) : $apps;
        request()->attributes->set(AppsView::class, $apps);
        // 店が選んだ見た目（ADR 0097）。下書きのプレビューでは下書きの値の CSS を、ほかは公開した値の CSS（テーマのままなら読まない）。
        // 核の部品 <x-storefront::theme-style /> が要求から読む
        $themeStyle = ThemeStyleView::of(ThemeStyleView::sheet($this->content, $theme, draft: $editor !== null), $editor !== null ? $style : null);
        request()->attributes->set(ThemeStyleView::class, $themeStyle);

        return response()
            ->view($view, [
                'theme' => $theme,
                'shop' => $shop,
                // 検索と共有の見え方（ADR 0061）。テーマは layout の <head> に核の部品 <x-storefront::seo :seo="$seo"> を置くだけ
                'seo' => $seo ?? SeoMeta::hidden($shop->name),
                'sections' => $sections,
                // ヘッダのカートの数（合い言葉の Cookie があるときだけ DB を見る）
                'cartCount' => ($data['cart'] ?? null) instanceof CartView ? $data['cart']->count : $this->cartCount(),
                // ヘッダーの会員の入口（ログイン中はマイページ、ほかはログイン。メールは渡さない）
                'account' => $this->account($preview),
                // 店頭の核の部品（カートを滑らかにする JavaScript。テーマは layout で @vite する。ADR 0020）
                'storefrontScript' => self::SCRIPT,
                // カートの操作の結果（フォームで送ったあと）
                'flash' => ['message' => $this->text(session('cart_message')), 'error' => $this->text(session('cart_error'))],
                // App の差し込み（核の部品 <x-storefront.apps> と <x-storefront.app-links> に渡すだけ）
                'apps' => $apps,
                // 店が選んだ見た目（ADR 0097）。外枠の <html> に字の組と角の丸みの名前を出す（data-sugar-font・data-sugar-radius）
                'themeStyle' => $themeStyle,
                // 商品のプレビューの画面か（帯は核の部品が描くので、テーマは見せ方を変えたいときだけ見る）
                'preview' => $preview,
                // メニューに載せる自由なページ（ADR 0039 区切り 3。公開していてメニューに載せるものだけ、並び順）
                'navPages' => $menu ? $this->navPages($freePage) : [],
                // 画面の鍵（ADR 0088）。個人情報の画面（navigation-only の印の経路）でだけ値があり、ほかは空。
                // 画面の中の核の JavaScript が同じ画面の一部を読む時に data-sugar-page-key で渡す（App の画面の動く QR など）
                'pageKey' => PageKey::forPage(request()),
            ] + $data, $status)
            ->header('Cache-Control', 'no-cache, private');
    }
}
