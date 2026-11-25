<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\StockService;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerAccounts;
use App\Core\Transaction\LineDraft;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Services\TransactionService;
use Carbon\CarbonImmutable;
use EcSugar\PluginBlog\Models\BlogCategory;
use EcSugar\PluginBlog\Models\BlogPost;
use EcSugar\PluginBundle\Models\BundleComponent;
use EcSugar\PluginCartReminder\Models\CartReminder;
use EcSugar\PluginCatalogSort\Models\CatalogSortItem;
use EcSugar\PluginContact\Models\ContactInquiry;
use EcSugar\PluginDownloads\Models\DownloadFile;
use EcSugar\PluginDownloads\Models\DownloadGrant;
use EcSugar\PluginFaq\Models\FaqItem;
use EcSugar\PluginGift\Models\GiftOrder;
use EcSugar\PluginLine\Models\LineMessage;
use EcSugar\PluginNewsletter\Models\NewsletterCampaign;
use EcSugar\PluginPreorder\Models\PreorderListing;
use EcSugar\PluginProductFeed\Models\Feed;
use EcSugar\PluginReferral\Models\Referral;
use EcSugar\PluginRestock\Models\RestockRequest;
use EcSugar\PluginReviews\Models\Review;
use EcSugar\PluginSalePeriod\Models\SaleWindow;
use EcSugar\PluginShippingCsv\Models\ShippingImport;
use EcSugar\PluginShopNotify\Models\ShopNotice;
use EcSugar\PluginWrapping\Models\WrappingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * App の契約のテストの部品: App の管理画面の画面（ADR 0051）に出る 1 件を、今の店に 1 つ作る（店の中で呼ぶ）。
 * 中身は「一覧と 1 件に出る」だけの最小の形で、表の制約に合わせて直に入れる（App の流れそのものは App ごとのテストが確かめる）。
 * 画面を持つ App を足したら、ここに 1 件の作り方を足す（無いとテストが「足す」と落ちる）。
 * 公開の木（ADR 0098）に入らない App（定期便・予約・チケット・抽選販売）の分は、App が入っていなければ呼ばれない
 * （App の一覧は plugins/ から読む）。その App のクラスを使う所は BookingContractRecord・LotteryContractRecord・
 * SubscriptionContractRecord・TicketContractRecord に分け（許す一覧で公開の木に入らない）、class_exists で囲んで呼ぶ（TestCase::missingPart）
 */
trait AppContractRecords
{
    /** @var array<string, CarbonImmutable> App の名前 => 1 件の操作が出る時刻（今でないときだけ） */
    private static array $contractMoments = [];

    /**
     * 画面の 1 件の ID（画面を持たない App は null）
     */
    private function contractRecord(string $plugin, string $shop): ?string
    {
        return match ($plugin) {
            'blog' => $this->saved(new BlogPost, [
                'category_id' => BlogCategory::query()->orderBy('position')->value('id'),
                'title' => '契約の確認の記事', 'slug' => 'contract-'.Str::lower(Str::random(8)), 'body' => [], 'status' => 'draft',
            ]),
            'booking' => $this->booking(),
            // セット商品: 画面の 1 件の印はセットの商品の ID（構成の品を 1 つ持つ）
            'bundle' => $this->bundle(),
            'downloads' => $this->download($shop),
            'lottery' => $this->lotteryDraw(),
            // 会員ランクの画面の 1 件は会員ごと
            'member-ranks' => $this->contractAccount('rank@example.com')->id,
            'wrapping' => $this->saved(new WrappingType, ['name' => '包装紙', 'price' => 300, 'position' => (int) WrappingType::query()->max('position') + 1]),
            'cart-reminder' => $this->saved(new CartReminder, ['transaction_id' => $this->contractOrder()->id, 'status' => 'queued']),
            // 並びを決めた商品（一覧と 1 件に出る。並びは店で 1 つなので、いちばん後ろに足す）
            'catalog-sort' => $this->saved(new CatalogSortItem, ['listing_id' => $this->contractListing('並びを決めた品')->id, 'position' => (int) CatalogSortItem::query()->max('position') + 1, 'tags' => ['新作']]),
            'contact' => $this->saved(new ContactInquiry, ['kind' => 'other', 'name' => '山田 花子', 'email' => 'hanako@example.com', 'body' => 'お問い合わせの本文', 'status' => 'new']),
            'faq' => $this->saved(new FaqItem, ['question' => '送料はいくらですか', 'answer' => '全国一律です', 'position' => (int) FaqItem::query()->max('position') + 1]),
            'favorites' => $this->favorite(),
            'gift' => $this->saved(new GiftOrder, ['transaction_id' => $this->contractOrder()->id, 'sender' => '山田 太郎', 'message' => 'おめでとう']),
            'line' => $this->lineMessage(),
            'newsletter' => $this->saved(new NewsletterCampaign, ['subject' => '冬のお知らせ', 'body' => '本文', 'status' => 'draft']),
            'preorder' => $this->saved(new PreorderListing, ['listing_id' => $this->contractListing()->id, 'release_date' => CarbonImmutable::now()->addMonth()->toDateString()]),
            'product-feed' => $this->feed(),
            'referral' => $this->referral(),
            'restock' => $this->restock(),
            'reviews' => $this->review(),
            // 公開の予約と販売期間: 1 商品に 1 行。終わりの日時だけを入れた「販売中」の行
            'sale-period' => $this->saved(new SaleWindow, ['listing_id' => $this->contractListing()->id, 'ends_at' => CarbonImmutable::now()->addMonth()]),
            'shipping-csv' => $this->saved(new ShippingImport, ['carrier' => 'yamato', 'file_hash' => hash('sha256', Str::random(16)), 'rows_count' => 0, 'ready_count' => 0, 'skipped_count' => 0, 'created_by' => 'test']),
            'shop-notify' => $this->saved(new ShopNotice, ['kind' => 'test', 'dedupe_key' => strtolower((string) Str::ulid()), 'channel' => 'mail']),
            'subscription' => $this->subscription(),
            'tickets' => $this->paidTicket($shop),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function saved(Model $model, array $values): string
    {
        $model->forceFill($values)->save();

        return (string) $model->getKey();
    }

    private function contractListing(string $title = '契約の確認の品'): Listing
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 3000, 'status' => ListingStatus::Active]);
    }

    private function contractAccount(string $email): CustomerAccount
    {
        $account = app(CustomerAccounts::class)->forEmail($email, 'test');
        CustomerAccount::query()->whereKey($account->id)->update(['member_since' => CarbonImmutable::now()]);

        return CustomerAccount::query()->findOrFail($account->id);
    }

    private function contractOrder(?Listing $listing = null, ?CustomerAccount $account = null): Transaction
    {
        $listing ??= $this->contractListing();
        $location = Location::query()->firstOrFail();
        app(StockService::class)->receive($listing, $location, 5);
        $buyer = Party::create(['kind' => PartyKind::Customer, 'name' => '山田 花子', 'email' => 'hanako@example.com', 'phone' => '09012345678', 'address' => ['city' => '渋谷区']]);

        return app(TransactionService::class)->open('sale', Seller::query()->firstOrFail(), $buyer, [LineDraft::fromListing($listing, 1, 1000, $location)], actor: 'test', customerAccount: $account);
    }

    /**
     * 当日払いの予約（予約の流れから）。予約の 1 件の操作（「当日払いを受け取った」〔money〕など）は、枠の日になってから出るので、
     * その時刻を contractMoments に残す（テストはその時刻へ移ってから確かめる）
     */
    private function booking(): string
    {
        if (! class_exists(BookingContractRecord::class)) {
            $this->missingPart('tests/Feature/Plugin/BookingContractRecord.php');
        }

        [$id, $moment] = (new BookingContractRecord)->onSiteReservation($this->contractAccount('booking@example.com'));
        self::$contractMoments['booking'] = $moment;

        return $id;
    }

    /**
     * セット商品: 在庫のある品 1 つを構成に持つセットの商品。画面の 1 件の印はセットの商品の ID
     */
    private function bundle(): string
    {
        $part = $this->contractListing('契約の確認の構成の品');
        app(StockService::class)->receive($part, Location::query()->firstOrFail(), 5);
        $bundle = $this->contractListing('契約の確認のセット');
        $this->saved(new BundleComponent, [
            'listing_id' => $bundle->id,
            'part_listing_id' => $part->id,
            'variant_id' => (string) ListingVariant::query()->where('listing_id', $part->id)->value('id'),
            'quantity' => 2,
        ]);

        return $bundle->id;
    }

    /**
     * 受付中の抽選（部品は LotteryContractRecord）
     */
    private function lotteryDraw(): string
    {
        if (! class_exists(LotteryContractRecord::class)) {
            $this->missingPart('tests/Feature/Plugin/LotteryContractRecord.php');
        }

        return (new LotteryContractRecord)->openDraw($this->contractListing('限定の万年筆'));
    }

    /**
     * 払った入場券（「入場済みにする」「無効にする」が出る。部品は TicketContractRecord）
     */
    private function paidTicket(string $shop): string
    {
        if (! class_exists(TicketContractRecord::class)) {
            $this->missingPart('tests/Feature/Plugin/TicketContractRecord.php');
        }

        return (new TicketContractRecord)->paidTicket($shop);
    }

    /**
     * ファイルを渡した注文（ダウンロード販売の画面の 1 件は注文ごと）
     */
    private function download(string $shop): string
    {
        $listing = $this->contractListing('写真集の PDF');
        $sale = $this->contractOrder($listing);
        $file = strtolower((string) Str::ulid());
        $this->saved(new DownloadFile, [
            'id' => $file, 'listing_id' => $listing->id, 'name' => 'photos.pdf', 'extension' => 'pdf', 'mime' => 'application/pdf',
            'bytes' => 1024, 'sha256' => hash('sha256', $file), 'path' => "tenants/{$shop}/files/{$file}.pdf",
        ]);
        $this->saved(new DownloadGrant, ['transaction_id' => $sale->id, 'file_id' => $file, 'max_count' => 5, 'expires_at' => CarbonImmutable::now()->addDays(30)]);

        return $sale->id;
    }

    private function favorite(): string
    {
        $listing = $this->contractListing('帆布のトートバッグ');
        DB::table('favorites_items')->insert(['id' => strtolower((string) Str::ulid()), 'customer_account_id' => $this->contractAccount('fav@example.com')->id, 'listing_id' => $listing->id, 'created_at' => CarbonImmutable::now()]);

        // お気に入りの画面の 1 件は商品ごと
        return $listing->id;
    }

    private function lineMessage(): string
    {
        $sale = $this->contractOrder();

        return $this->saved(new LineMessage, ['dedupe_key' => "order_paid|{$sale->id}", 'kind' => 'order_paid', 'transaction_id' => $sale->id, 'status' => 'pending']);
    }

    /**
     * フィードの記録（Google と Meta）は App の移行が作る。テストの店は移行を流した型から写すので、どの店でも同じ ID になる
     * （本物の店は店ごとに移行を流すので違う）。ほかの店の ID として確かめられるよう、この店の分だけ新しい ID にする
     */
    private function feed(): string
    {
        $id = strtolower((string) Str::ulid());
        Feed::query()->where('format', 'google')->update(['id' => $id]);

        return $id;
    }

    private function referral(): string
    {
        $referrer = $this->contractAccount('referrer@example.com');
        $friend = $this->contractAccount('friend@example.com');

        // 確かめ待ち（同じ接続元）の紹介。「ポイントを付ける」「付けない」が出る
        return $this->saved(new Referral, [
            'referrer_account_id' => $referrer->id, 'friend_account_id' => $friend->id, 'status' => 'review', 'reason' => 'same_source',
            'transaction_id' => $this->contractOrder(account: $friend)->id, 'linked_at' => CarbonImmutable::now(), 'ordered_at' => CarbonImmutable::now(),
        ]);
    }

    private function restock(): string
    {
        $listing = $this->contractListing('白いマグカップ');
        $variant = (string) ListingVariant::query()->where('listing_id', $listing->id)->value('id');
        $this->saved(new RestockRequest, [
            'listing_id' => $listing->id, 'variant_id' => $variant, 'email' => 'restock@example.com', 'token_hash' => hash('sha256', Str::random(16)),
            'status' => 'waiting', 'confirmed_at' => CarbonImmutable::now(),
        ]);

        // 再入荷のお知らせの画面の 1 件は商品の種類ごと
        return $variant;
    }

    private function review(): string
    {
        $listing = $this->contractListing('麻のシャツ');

        return $this->saved(new Review, [
            'transaction_id' => $this->contractOrder($listing)->id, 'listing_id' => $listing->id, 'rating' => 5,
            'title' => 'ちょうどいい', 'body' => '毎日着ています', 'display_name' => '購入者', 'status' => 'pending',
        ]);
    }

    /**
     * 続いている定期便（部品は SubscriptionContractRecord）
     */
    private function subscription(): string
    {
        if (! class_exists(SubscriptionContractRecord::class)) {
            $this->missingPart('tests/Feature/Plugin/SubscriptionContractRecord.php');
        }

        return (new SubscriptionContractRecord)->activeSubscription($this->contractListing('珈琲豆の定期便'), $this->contractAccount('sub@example.com'));
    }
}
