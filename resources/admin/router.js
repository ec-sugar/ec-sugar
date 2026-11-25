import { createRouter, createWebHistory } from 'vue-router';
import { api } from './api.js';
import { session, refreshSession } from './session.js';
import { loadTenantNames, rememberTenant } from './tenants.js';
import LoginPage from './pages/LoginPage.vue';
import EnrollPage from './pages/EnrollPage.vue';
import RecoverPage from './pages/RecoverPage.vue';
import RecoverEmailPage from './pages/RecoverEmailPage.vue';
import PasskeysPage from './pages/PasskeysPage.vue';
import OAuthConsentPage from './pages/OAuthConsentPage.vue';
import TenantsPage from './pages/TenantsPage.vue';
import TenantPage from './pages/TenantPage.vue';
import ListingsPage from './pages/ListingsPage.vue';
import SettingsPage from './pages/settings/SettingsPage.vue';
import LegalSettingsPage from './pages/settings/LegalSettingsPage.vue';
import PrivacySettingsPage from './pages/settings/PrivacySettingsPage.vue';
import OrderTermsSettingsPage from './pages/settings/OrderTermsSettingsPage.vue';
import PaymentSettingsPage from './pages/settings/PaymentSettingsPage.vue';
import BasicSettingsPage from './pages/settings/BasicSettingsPage.vue';
import RecordsSettingsPage from './pages/settings/RecordsSettingsPage.vue';
import MailSettingsPage from './pages/settings/MailSettingsPage.vue';
import ShippingSettingsPage from './pages/settings/ShippingSettingsPage.vue';
import DeliveryDateSettingsPage from './pages/settings/DeliveryDateSettingsPage.vue';
import OrderFieldSettingsPage from './pages/settings/OrderFieldSettingsPage.vue';
import ConsentSettingsPage from './pages/settings/ConsentSettingsPage.vue';
import PointSettingsPage from './pages/settings/PointSettingsPage.vue';
import GiftCardSettingsPage from './pages/settings/GiftCardSettingsPage.vue';
import SeoSettingsPage from './pages/settings/SeoSettingsPage.vue';
import AgentSettingsPage from './pages/settings/AgentSettingsPage.vue';
import AgentProposalsPage from './pages/AgentProposalsPage.vue';
import AgentProposalPage from './pages/AgentProposalPage.vue';
import ThemeContentPage from './pages/ThemeContentPage.vue';
import ThemePageEditorPage from './pages/ThemePageEditorPage.vue';
import PhotosPage from './pages/PhotosPage.vue';
import ShopPagesPage from './pages/ShopPagesPage.vue';
import CategoriesPage from './pages/CategoriesPage.vue';
import ListingPage from './pages/ListingPage.vue';
import ListingImportPage from './pages/ListingImportPage.vue';
import TransactionsPage from './pages/TransactionsPage.vue';
import TransactionPage from './pages/TransactionPage.vue';
import CustomersPage from './pages/CustomersPage.vue';
import CustomerPage from './pages/CustomerPage.vue';
import CouponsPage from './pages/CouponsPage.vue';
import CouponPage from './pages/CouponPage.vue';
import SalesPage from './pages/SalesPage.vue';
import NotFoundPage from './pages/NotFoundPage.vue';
import HelpPage from './pages/HelpPage.vue';
import HelpArticlePage from './pages/HelpArticlePage.vue';
import MembersPage from './pages/MembersPage.vue';
import LoginHistoryPage from './pages/LoginHistoryPage.vue';
import PluginsPage from './pages/PluginsPage.vue';
import OperationsPage from './pages/OperationsPage.vue';
import TracePage from './pages/TracePage.vue';
import TenantPluginsPage from './pages/TenantPluginsPage.vue';
import TenantPluginPage from './pages/TenantPluginPage.vue';
import AppRecordsPage from './pages/AppRecordsPage.vue';
import AppRecordPage from './pages/AppRecordPage.vue';

// 画面の経路。題（見出し・パンくず・タブの題）はメニューの定義（navigation.js）から作る（タブの題は App.vue）。サーバー側（routes/admin.php の殻の経路）にも同じものを列挙する。
// meta.guest: ログイン前だけの画面 / meta.public: ログインの有無を問わない画面 / それ以外はログインが要る
// meta.bare: メニューの骨組みを出さず、中央に 1 枚だけ置く画面
// meta.fullscreen: ログインが要り、メニューの骨組みを出さず、画面の全体を自分で使う画面（ページの編集。ADR 0039）
// 画面の出し分けは表示のためだけ。データを守るのはサーバーの認可（API がログインを確認する）
// ホーム: 見られる店が 1 つなら、その店の画面へ。0 か 2 つ以上なら店の一覧へ（既定は 1 システム・1 店舗。CONCEPT 4.1）
// 振り分けは表示のためだけ（どの店を見られるかはサーバーが決める）
async function openHome() {
    try {
        const { tenants, meta } = await api('GET', '/api/tenants?per_page=2');

        if (meta.total === 1 && tenants.length === 1) {
            const [tenant] = tenants;
            rememberTenant(tenant.id, tenant.name, tenant.storefront_url, tenant.level);

            return { name: 'tenant', params: { tenant: tenant.id }, replace: true };
        }
    } catch {
        // 読めなければ一覧へ（一覧の画面が状態ごとの一言を出す）
    }

    return { name: 'tenants', replace: true };
}

export const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', name: 'home', component: TenantsPage, beforeEnter: openHome },
        { path: '/login', name: 'login', component: LoginPage, meta: { guest: true, bare: true } },
        { path: '/enroll', name: 'enroll', component: EnrollPage, meta: { public: true, bare: true } },
        { path: '/recover', name: 'recover', component: RecoverPage, meta: { guest: true, bare: true } },
        { path: '/recover/email', name: 'recover-email', component: RecoverEmailPage, meta: { guest: true, bare: true } },
        { path: '/passkeys', name: 'passkeys', component: PasskeysPage },
        // AI エージェントのつなぎの許可（OAuth の許可の画面。ADR 0101）。ログインが要る（していなければログインの後にここへ戻る）。
        // :request は頼みの合い言葉（/oauth/authorize が付ける）。無いときは行き先へ送れない誤り（?error=）だけを出す
        { path: '/oauth/consent/:request([A-Za-z0-9_-]{43})?', name: 'oauth-consent', component: OAuthConsentPage, props: true, meta: { bare: true } },
        { path: '/tenants', name: 'tenants', component: TenantsPage },
        { path: '/members', name: 'members', component: MembersPage },
        { path: '/login-history', name: 'login-history', component: LoginHistoryPage },
        { path: '/plugins', name: 'plugins', component: PluginsPage },
        // 運営全体の様子（オーナーだけ。API が断る）
        { path: '/operations', name: 'operations', component: OperationsPage },
        { path: '/help', name: 'help', component: HelpPage },
        // 説明のページ（/help/<key>。中身は resources/admin/help/。知らない名前は画面の中で「見つかりません」）
        { path: '/help/:article([a-z][a-z0-9-]{1,31})', name: 'help-article', component: HelpArticlePage, props: true },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})',
            name: 'tenant',
            component: TenantPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/listings',
            name: 'listings',
            component: ListingsPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/listings/new',
            name: 'listing-new',
            component: ListingPage,
            props: true,
        },
        {
            // 商品の CSV の取り込み（docs/design/product-csv.md）
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/listings/import',
            name: 'listing-import',
            component: ListingImportPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/listings/:listing([0-9a-z]{26})',
            name: 'listing',
            component: ListingPage,
            props: true,
        },
        // クーポン（ADR 0054 の区切り 2）。一覧と、作る・直すの 1 枚
        {
            // AI エージェントの案（承認の一覧と 1 件。ADR 0101）
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/agent-proposals',
            name: 'agent-proposals',
            component: AgentProposalsPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/agent-proposals/:proposal([0-9a-z]{26})',
            name: 'agent-proposal',
            component: AgentProposalPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/coupons',
            name: 'coupons',
            component: CouponsPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/coupons/new',
            name: 'coupon-new',
            component: CouponPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/coupons/:coupon([0-9a-z]{26})',
            name: 'coupon',
            component: CouponPage,
            props: true,
        },
        // 売上の集計（ADR 0060）。お金の段階から
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/sales',
            name: 'sales',
            component: SalesPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/categories',
            name: 'categories',
            component: CategoriesPage,
            props: true,
        },
        {
            // 設定の目次。Stripe の登録の画面からの戻り（?stripe=return / refresh。戻り先の URL はサーバーが作る）は支払いの画面へ送る
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings',
            name: 'settings',
            component: SettingsPage,
            props: true,
            beforeEnter: (to) => (['return', 'refresh'].includes(to.query.stripe) ? { name: 'settings-payments', params: to.params, query: to.query, replace: true } : true),
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings/basic',
            name: 'settings-basic',
            component: BasicSettingsPage,
            props: true,
        },
        {
            // 前の「ショップの公開」の URL。中身は「基本」へ移した
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings/publish',
            redirect: (to) => ({ name: 'settings-basic', params: to.params }),
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings/legal',
            name: 'settings-legal',
            component: LegalSettingsPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings/privacy',
            name: 'settings-privacy',
            component: PrivacySettingsPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings/order-terms',
            name: 'settings-order-terms',
            component: OrderTermsSettingsPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings/payments',
            name: 'settings-payments',
            component: PaymentSettingsPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings/shipping',
            name: 'settings-shipping',
            component: ShippingSettingsPage,
            props: true,
        },
        {
            // お届け日時（ADR 0059）
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings/delivery-dates',
            name: 'settings-delivery-dates',
            component: DeliveryDateSettingsPage,
            props: true,
        },
        {
            // 注文の項目（ADR 0066）
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings/order-fields',
            name: 'settings-order-fields',
            component: OrderFieldSettingsPage,
            props: true,
        },
        {
            // ポイント（ADR 0068）
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings/points',
            name: 'settings-points',
            component: PointSettingsPage,
            props: true,
        },
        {
            // ギフトカード（ADR 0076）
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings/gift-cards',
            name: 'settings-gift-cards',
            component: GiftCardSettingsPage,
            props: true,
        },
        {
            // 同意の帯
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings/consent',
            name: 'settings-consent',
            component: ConsentSettingsPage,
            props: true,
        },
        {
            // 検索と共有（ADR 0061）。トップの題と説明・共有の写真・古い URL からの転送
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings/seo',
            name: 'settings-seo',
            component: SeoSettingsPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings/records',
            name: 'settings-records',
            component: RecordsSettingsPage,
            props: true,
        },
        {
            // メールの記録（ADR 0095）。一覧と 1 通の様子・送れなかった理由・試しのメール
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings/mails',
            name: 'settings-mails',
            component: MailSettingsPage,
            props: true,
        },
        {
            // AI エージェントの鍵とつなぎ方（ADR 0100）。鍵の API は店の外（/api/agent-tokens）で、店はフォームの中で選ぶ
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/settings/agents',
            name: 'settings-agents',
            component: AgentSettingsPage,
            props: true,
        },
        {
            // 処理を追う（ADR 0087。オーナーだけ。API が断る）。処理の ID でこの店の関係する記録を並べる
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/traces/:trace([0-9a-z]{26})',
            name: 'trace',
            component: TracePage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/theme',
            name: 'theme-content',
            component: ThemeContentPage,
            props: true,
        },
        {
            // ページの編集（ADR 0039 区切り 2・3）。:page はトップ（home）か自由なページ（page-{ID}）。編集するテーマは ?theme=（無ければショップで使っているテーマ）。
            // 真ん中のプレビューの枠を許す CSP は、この画面の殻にだけ付く（ShellController）。SPA は画面を移る時に殻を読み直さないので、
            // ほかの画面から来た時は文書ごと読み直して入る（最初に開いた画面ならそのまま）
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/theme/pages/:page([a-z][a-z0-9_-]{0,31})',
            name: 'theme-page',
            component: ThemePageEditorPage,
            props: true,
            meta: { fullscreen: true },
            beforeEnter: (to, from) => {
                if (from.matched.length === 0) {
                    return true;
                }

                window.location.assign(to.fullPath);

                return false;
            },
        },
        {
            // 自由なページの一覧（ADR 0039 区切り 3）: 作る・題と URL とメニューと公開の状態・並べる・ゴミ箱へ。中身は上のページの編集の画面
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/pages',
            name: 'shop-pages',
            component: ShopPagesPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/photos',
            name: 'photos',
            component: PhotosPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/plugins',
            name: 'tenant-plugins',
            component: TenantPluginsPage,
            props: true,
        },
        {
            // プラグインごとの画面（使う・使わないと設定）。名前の形は API の経路（routes/admin.php の {plugin}）と同じ
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/plugins/:plugin([a-z][a-z0-9-]{1,31})',
            name: 'tenant-plugin',
            component: TenantPluginPage,
            props: true,
        },
        {
            // App の画面（ADR 0051。定期便の一覧など）: App が中身を渡し、核の部品が描く。App の名前の形は API の経路と同じ、記録の ID は ULID
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/apps/:plugin([a-z][a-z0-9-]{1,31})',
            name: 'app-page',
            component: AppRecordsPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/apps/:plugin([a-z][a-z0-9-]{1,31})/:record([0-9a-z]{26})',
            name: 'app-record',
            component: AppRecordPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/transactions',
            name: 'transactions',
            component: TransactionsPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/transactions/:transaction([0-9a-z]{26})',
            name: 'transaction',
            component: TransactionPage,
            props: true,
        },
        {
            // お客さん（ADR 0044 の 4）。ID の形は API の経路（whereUlid）と同じ
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/customers',
            name: 'customers',
            component: CustomersPage,
            props: true,
        },
        {
            path: '/tenants/:tenant([a-z0-9][a-z0-9_-]{0,62})/customers/:customer([0-9a-z]{26})',
            name: 'customer',
            component: CustomerPage,
            props: true,
        },
        { path: '/:path(.*)*', name: 'not-found', component: NotFoundPage, meta: { public: true } },
    ],
});

router.beforeEach(async (to) => {
    if (!session.checked) {
        await refreshSession();
    }

    if (to.meta.public) {
        return true;
    }

    if (to.meta.guest) {
        return session.operator ? { name: 'home' } : true;
    }

    if (!session.operator) {
        session.returnTo = to.fullPath;

        return { name: 'login' };
    }

    // 店の数でメニューとパンくずの形が変わるので、画面を開く前に 1 回だけ読む（2 回目からは読んだものを使う）
    await loadTenantNames();

    return true;
});

