<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\Plugin\Contracts\AdminOrderPanelProvider;
use App\Core\Plugin\Contracts\AdminPageHandler;
use App\Core\Plugin\Contracts\AgeCheckHandler;
use App\Core\Plugin\Contracts\CatalogBrowseHandler;
use App\Core\Plugin\Contracts\CheckoutOptInHandler;
use App\Core\Plugin\Contracts\ContentAccessProvider;
use App\Core\Plugin\Contracts\DemoRestockHandler;
use App\Core\Plugin\Contracts\GiftHandler;
use App\Core\Plugin\Contracts\ListingFilesHandler;
use App\Core\Plugin\Contracts\ListingPanelHandler;
use App\Core\Plugin\Contracts\ListingVisibilityProvider;
use App\Core\Plugin\Contracts\LoginEntryProvider;
use App\Core\Plugin\Contracts\MemberBenefitsHandler;
use App\Core\Plugin\Contracts\OrderChargeHandler;
use App\Core\Plugin\Contracts\OrderDocumentHandler;
use App\Core\Plugin\Contracts\OrderFactProvider;
use App\Core\Plugin\Contracts\OrderLinkProvider;
use App\Core\Plugin\Contracts\PluginDataPurger;
use App\Core\Plugin\Contracts\PreorderHandler;
use App\Core\Plugin\Contracts\ProductPanelProvider;
use App\Core\Plugin\Contracts\PurchaseLimitProvider;
use App\Core\Plugin\Contracts\PurchaseOptionProvider;
use App\Core\Plugin\Contracts\SaleHoldProvider;
use App\Core\Plugin\Contracts\ShopPagesProvider;
use App\Core\Plugin\Contracts\StockComponentsProvider;
use App\Core\Plugin\Manifest\AppManifest;
use App\Core\Plugin\Sections\AppSectionHandler;
use Closure;

/**
 * プラグインの定義。プラグインのパッケージは自分のサービスプロバイダでこれを PluginRegistry に登録する（ADR 0025）。
 * ショップの経路は自分で登録せず、ここに渡す（核が storefront・public・plugin:<名前> の印を付けて登録する。使わない店では 404）。
 * 核の口（ショップへの差し込み・CSP・設定・同意・公表。ADR 0034）を使う App は、manifest に自分の composer.json を渡す。
 * 登録簿が extra.ec-sugar を読んで確かめ、$app に入れる（App は $app を渡さない）。
 * 外から埋め込む区画（マニフェストの sections の embed。ADR 0040）を宣言した App は、区画の係（AppSectionHandler）を sectionHandler に渡す
 */
final readonly class PluginDefinition
{
    /**
     * @param  string  $name  プラグインの名前（英小文字・数字・ハイフン。マニフェストの name と同じ）
     * @param  string  $title  画面に出す名前
     * @param  int  $pluginApi  合わせて作ったプラグイン API のバージョン
     * @param  ?Closure(): void  $storefrontRoutes  ショップの経路を足す（Route::get などを呼ぶ）
     * @param  ?string  $manifest  composer.json の道筋（核の口を使うときだけ。パッケージの根もここから決まる）
     * @param  ?AppManifest  $app  確かめた後のマニフェスト（PluginRegistry が入れる）
     * @param  ?AppSectionHandler  $sectionHandler  App の区画の係（区画の欄の値の確かめと、埋め込む URL を作る。ADR 0040）
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public int $pluginApi,
        public ?Closure $storefrontRoutes = null,
        public ?string $manifest = null,
        public ?AppManifest $app = null,
        public ?AppSectionHandler $sectionHandler = null,
        // 店の DB に足す表の移行のフォルダ（ADR 0050 の 2）。表の名前はプラグインの名前で始める（核の表を変えない。テストが見張る）
        public ?string $tenantMigrations = null,
        // ショップの商品の画面に置く買い方（ADR 0050）と、管理画面の商品の画面の欄。使う店だけで呼ばれる
        public ?PurchaseOptionProvider $purchaseOptions = null,
        public ?ListingPanelHandler $listingPanel = null,
        // 会員のマイページに置く入口（使う店だけで出す）
        public ?AccountLink $accountLink = null,
        // 管理画面の App の画面（一覧と 1 件。ADR 0051）。中身だけを渡し、核の部品が描く
        public ?AdminPageHandler $adminPage = null,
        // ショップの商品の画面の説明の下に置く、開いて読む並び（よくある質問など）。使う店だけで呼ばれる
        public ?ProductPanelProvider $productPanel = null,
        // お客さんのご注文の画面（ご注文の確認・マイページの注文の履歴）に置くリンク（レビューを書く・領収書など。2026-11-04）。使う店だけで、その注文を見てよい時に呼ばれる
        public ?OrderLinkProvider $orderLinks = null,
        // 注文の書類（納品書・領収書など）。管理画面の注文の画面のボタンと印刷用のページ。使う店だけで呼ばれる
        public ?OrderDocumentHandler $orderDocuments = null,
        // 贈り物（ADR 0055）。注文の入力の「贈り物として送る」と、受け取りのリンク。使う店だけで呼ばれる（店で 1 つの App だけ）
        public ?GiftHandler $gift = null,
        // 注文の入力の「お知らせのメールを受け取る」（いつも選んでいない状態から）。使う店だけで出す（店で 1 つの App だけ）
        public ?CheckoutOptInHandler $checkoutOptIn = null,
        // 検索に載せるショップのページ（ADR 0072。お知らせ・ブログの /news など）。経路は核が頭の下に登録し、使う店だけで動く
        public ?ShopPagesProvider $shopPages = null,
        // ご注文の画面とご注文の知らせのメールに足す注文の事実（予約の日時・場所・人数など）。使う店だけで呼ばれる
        public ?OrderFactProvider $orderFacts = null,
        // 予約販売（ADR 0073）。発売日の前の商品の決まりと、注文の確定の錠の中での予約の数え直し。使う店だけで呼ばれる（店で 1 つの App だけ）
        public ?PreorderHandler $preorder = null,
        // 会員のログインの画面に置く入口（「LINE でログイン」など。ADR 0070）。使う店だけで、設定がそろっている時だけ出す
        public ?LoginEntryProvider $loginEntry = null,
        // 年齢の確かめ（ADR 0077）。商品ごとの年齢・会員が確かめた年齢・年齢の門。使う店だけで呼ばれる（店で 1 つの App だけ）
        public ?AgeCheckHandler $ageCheck = null,
        // 注文に料金の行を足す口（ラッピング・のしなど。ADR 0078）。注文の入力の欄と、確定の錠の中での見積もり直し。使う店だけで呼ばれる（店で 1 つの App だけ）
        public ?OrderChargeHandler $orderCharges = null,
        // 会員の特典（ADR 0079。会員価格・ポイントの率の上乗せ・会員だけの商品）。店で 1 つの App だけ
        public ?MemberBenefitsHandler $memberBenefits = null,
        // 商品の売り方を押さえる（ADR 0080。抽選販売の受付から結果の片付けまで、カートとほかの App の 1 品の注文に入れさせない）。使う店だけで呼ばれる
        public ?SaleHoldProvider $saleHold = null,
        // この商品の在庫はどの品からいくつ引くか（セット商品）。使う店だけで呼ばれる。
        // 読めなかったら取引を止める（在庫を引かずに売らない）
        public ?StockComponentsProvider $stockComponents = null,
        // お客さんが「読む権利（鍵）」を持っているかを答える口（有料の記事・会員だけのコンテンツ）。使う店だけで呼ばれる。
        // 真を返すことは「本文を送ってよい」という意味なので、答える側は期限・解約・返金を必ず見る
        public ?ContentAccessProvider $contentAccess = null,
        // 商品の画面の App の欄に置くファイル（売るファイルなど。ADR 0084）。受け取り・確かめは核、置く場所と表は App。欄（listingPanel）を持つ App だけ
        public ?ListingFilesHandler $listingFiles = null,
        // 管理画面の注文の画面に出す App の事実（ダウンロードの回数など）。使う店だけで呼ばれる
        public ?AdminOrderPanelProvider $adminOrderPanel = null,
        // App を止めた店でも開くショップの経路（もう払ったお客さんが買ったものを受け取る画面。ダウンロード。見直し）。
        // 頭と名前は storefrontRoutes と同じ（/p/<名前>/…・storefront.plugin.<名前>.…）で、核は plugin の印を付けない。App は入っている間だけ動く
        public ?Closure $keptStorefrontRoutes = null,
        // デモの店（ADR 0086）で、App が数える売れる数（予約の枠・予約販売の数・席）を作った時の数へ戻す。1 時間ごとの demo:restock が、使う店だけで呼ぶ
        public ?DemoRestockHandler $demoRestock = null,
        // 店で App のデータを消す口（ADR 0092）。止めた店で、オーナーが直前の再確認の後に選ぶと核が呼ぶ。渡さない App は消せない（ボタンが出ない）
        public ?PluginDataPurger $purger = null,
        // 商品を「今はショップに出さない」「今は売らない」とする口（公開の日時の前は一覧に出さない・販売の終わりの後は売り切れ）。
        // 見せ方だけで、カートと注文の確定を断るのは売り方を押さえる口（saleHold）。使う店だけで呼ばれる
        public ?ListingVisibilityProvider $listingVisibility = null,
        // ショップの一覧の並びと絞り込み。店が手で決めた並びと、お客さんに出す絞り込み（値段の帯・タグ・在庫あり）。店で 1 つの App だけ
        public ?CatalogBrowseHandler $catalogBrowse = null,
        // 1 回のご注文の決まり（ADR 0109。商品ごとの「何点まで」と店の「◯ 円以上から」）。使う店だけで呼ばれる。
        // 読めなかったらご注文を受けない（決まりが分からないまま売らない）
        public ?PurchaseLimitProvider $purchaseLimits = null,
    ) {}

    /**
     * 核の口の宣言（マニフェストの無いプラグインは空）
     */
    public function app(): AppManifest
    {
        return $this->app ?? AppManifest::none();
    }

    public function withApp(AppManifest $app): self
    {
        return new self(
            name: $this->name,
            title: $this->title,
            description: $this->description,
            pluginApi: $this->pluginApi,
            storefrontRoutes: $this->storefrontRoutes,
            manifest: $this->manifest,
            app: $app,
            sectionHandler: $this->sectionHandler,
            tenantMigrations: $this->tenantMigrations,
            purchaseOptions: $this->purchaseOptions,
            listingPanel: $this->listingPanel,
            accountLink: $this->accountLink,
            adminPage: $this->adminPage,
            productPanel: $this->productPanel,
            orderLinks: $this->orderLinks,
            orderDocuments: $this->orderDocuments,
            gift: $this->gift,
            checkoutOptIn: $this->checkoutOptIn,
            shopPages: $this->shopPages,
            orderFacts: $this->orderFacts,
            preorder: $this->preorder,
            loginEntry: $this->loginEntry,
            ageCheck: $this->ageCheck,
            orderCharges: $this->orderCharges,
            memberBenefits: $this->memberBenefits,
            saleHold: $this->saleHold,
            stockComponents: $this->stockComponents,
            contentAccess: $this->contentAccess,
            listingFiles: $this->listingFiles,
            adminOrderPanel: $this->adminOrderPanel,
            keptStorefrontRoutes: $this->keptStorefrontRoutes,
            demoRestock: $this->demoRestock,
            purger: $this->purger,
            listingVisibility: $this->listingVisibility,
            catalogBrowse: $this->catalogBrowse,
            purchaseLimits: $this->purchaseLimits,
        );
    }
}
