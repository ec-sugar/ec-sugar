<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 部品どうしの依存の向きを見張る（2026-11-01。部品が絡み合わず、簡素な設計を保つため）。
 * 核（app/Core）が一番下、その上に管理画面（app/Admin）とショップ（app/Storefront）が並ぶ。上から下へは頼ってよく、下から上・横どうしは頼らない。
 * 今ある例外は理由つきで書き出し、増えたら落とす（減らすのはよい。減ったら一覧からも消す）
 */
final class ArchitectureTest extends TestCase
{
    /**
     * 核の文脈どうしで、互いに頼り合っている組（今ある分だけ）。取引と支払いは、注文の流れを Core\\Order へ出して無くした。残りはモデルどうしのつながりが中心。
     * 店（Shop）との頼り合い 3 組（Catalog・Payment・Plugin）は、責務ごとに割って無くした（ADR 0107 の段 2）。
     * 代わりに残る 3 組は、割った文脈の間で**同じトランザクション・同じロックの中で整合を守るため**に要るもの（SPLIT_EDGES_ALLOWED に理由を書いた）
     */
    private const array CORE_CYCLES_ALLOWED = [
        'Catalog <-> Listing',
        'Catalog <-> Theme',
        'Fulfillment <-> Transaction',
        'Inventory <-> Listing',
        'Ledger <-> Transaction',
        'Listing <-> Media',
        'Listing <-> Shop',
        'Media <-> Theme',
        'Section <-> Theme',
    ];

    /**
     * 店（Shop）から責務ごとに割った文脈（ADR 0107 の段 2）。この 5 つの間は、下の SPLIT_EDGES_ALLOWED に書いた向きだけ
     */
    private const array SPLIT_CONTEXTS = ['Media', 'Sample', 'Section', 'Shop', 'Theme'];

    /**
     * 割った文脈の間で許す向き（ここに無い向きが生えたら落ちる。要らなくなったら消す）。
     * **店（Shop）から Section・Theme・Sample への向きは、ここに足さない**（別のテストが例外なしで断る。足すものは呼ぶ側の Admin・Storefront へ寄せる）
     */
    private const array SPLIT_EDGES_ALLOWED = [
        // 使っている写真は消させない: 写真の置き場がテーマの値（区画の写真の ID）を見る（ShopMediaService::usageMap）
        'Media -> Theme',
        // 入れたときのサンプルは、商品・写真・テーマの中身・送料・特商法の見本をまとめて書くので、ほかの 4 つを使う（ADR 0053）
        'Sample -> Media',
        'Sample -> Section',
        'Sample -> Shop',
        'Sample -> Theme',
        // ページの住所を変えたら店の転送に乗せる（RedirectService）・題と説明の整え（SeoText）
        'Section -> Shop',
        // ページを完全に消すとき、同じトランザクションでテーマの値から中身（page-{ID}）も消す（ShopPageService::purge → ThemeContentService::forgetPage）
        'Section -> Theme',
        // SEO の共有の写真（ShopSeo の外部キーの先）
        'Shop -> Media',
        // 区画の写真の ID が本当にあるかを、写真を消す側と同じロックの中で確かめる
        'Theme -> Media',
        // テーマの値は区画の宣言（SectionSchema・PageSpec・CoreSections）で形を確かめる
        'Theme -> Section',
    ];

    /**
     * 管理画面が使ってよいショップの部品（プレビューの署名つきの URL・本物のプレビューを描く部品・送った知らせの記録）。増やす時は、核へ移せないかを先に考える
     */
    private const array ADMIN_USES_STOREFRONT = [
        'App\\Storefront\\Access\\ClientSource',
        'App\\Storefront\\Access\\ListingPreviewLink',
        'App\\Storefront\\Access\\ThemePreviewLink',
        'App\\Storefront\\Catalog\\Catalog',
        'App\\Storefront\\Orders\\SentMail',
        'App\\Storefront\\Themes\\ThemeSelector',
        'App\\Storefront\\View\\SectionResolver',
        'App\\Storefront\\View\\StorefrontUrls',
    ];

    /**
     * ショップが使ってよい管理画面の部品（運営がテーマを試しに見る時に、運営を確かめて記録する。ThemeSelector。
     * 注文の回数の上限で断った回数を中央の監査ログに残す。UnpaidOrderLimits。ADR 0085）
     */
    private const array STOREFRONT_USES_ADMIN = [
        'app/Storefront/Checkout/UnpaidOrderLimits.php: App\\Admin\\Services\\AuditLog',
        'app/Storefront/Themes/ThemeSelector.php: App\\Admin\\Models\\Operator',
        'app/Storefront/Themes/ThemeSelector.php: App\\Admin\\Services\\AuditLog',
    ];

    /**
     * Stripe の名前を持つクラス（`Stripe\…` の部品・`App\…\Stripe…`）を読み込んでよいファイル（ADR 0107 の段 3）。
     * 核の金の流れ・店頭・App は口（PaymentDriver・PaymentCredentialStore・PaymentWebhookReceiver）だけを見る。
     * この一覧は減る方向にだけ動かす（増やすときは、なぜ口では足りないかを ADR に書く）
     */
    private const array STRIPE_ALLOWED = [
        // ドライバそのもの（出す予定の 1,234 行）
        'app/Core/Payment/Stripe/',
        // 鍵の値（置き場とドライバの間だけを行き来する）
        'app/Core/Payment/StripeCredentials.php',
        // 鍵の置き場（PaymentCredentialStore の実装）と、つなぐ手順（ADR 0022）
        'app/Core/Payment/Services/PaymentGatewayService.php',
        'app/Core/Payment/Services/StripeConnectService.php',
        // 鍵を貼る・つなぐ画面の API と、登録の状態の問い合わせ直し、中央 DB の連結アカウント
        'app/Admin/Http/Controllers/Api/PaymentSettingsController.php',
        'app/Storefront/Console/RefreshStripeConnectCommand.php',
        'app/Models/StripeConnectedAccount.php',
    ];

    public function test_the_core_does_not_know_the_admin_the_storefront_or_http(): void
    {
        $outward = [];

        foreach ($this->imports('app/Core') as [$file, $class]) {
            if (preg_match('/\AApp\\\\(Admin|Storefront|Http)\\\\/', $class) === 1) {
                $outward[] = "{$file}: {$class}";
            }
        }

        $this->assertSame([], $outward, '核は管理画面・ショップ・Web の受け口を知らない。要るものは核へ移す');
    }

    public function test_core_contexts_do_not_grow_new_cycles(): void
    {
        $edges = [];

        foreach ($this->imports('app/Core') as [$file, $class]) {
            $from = $this->context($file);
            $to = preg_match('/\AApp\\\\Core\\\\([A-Za-z]+)\\\\/', $class, $m) === 1 ? $m[1] : null;

            if ($from !== null && $to !== null && $from !== $to) {
                $edges["{$from}>{$to}"] = [$from, $to];
            }
        }

        $cycles = [];

        foreach ($edges as [$from, $to]) {
            if (isset($edges["{$to}>{$from}"])) {
                $pair = [$from, $to];
                sort($pair);
                $cycles[implode(' <-> ', $pair)] = true;
            }
        }

        $found = array_keys($cycles);
        sort($found);
        $this->assertSame([], array_values(array_diff($found, self::CORE_CYCLES_ALLOWED)), '核の文脈どうしで、新しく互いに頼り合っている。どちらかの向きを無くす');
        $this->assertSame([], array_values(array_diff(self::CORE_CYCLES_ALLOWED, $found)), '頼り合いが減った。一覧（CORE_CYCLES_ALLOWED）からも消す');
    }

    public function test_the_shop_does_not_know_the_contexts_split_out_of_it(): void
    {
        $downward = [];

        foreach ($this->imports('app/Core/Shop') as [$file, $class]) {
            if (preg_match('/\AApp\\\\Core\\\\(Section|Theme|Sample)\\\\/', $class) === 1) {
                $downward[] = "{$file}: {$class}";
            }
        }

        sort($downward);
        $this->assertSame([], $downward, '店（Core\\Shop）は、割って出したセクション・テーマ・サンプルを知らない（ADR 0107）。逆向き（セクション・テーマ・サンプル → 店）は可。要るものは呼ぶ側（Admin・Storefront）へ寄せる');
    }

    public function test_the_contexts_split_out_of_the_shop_keep_their_direction(): void
    {
        $edges = [];

        foreach ($this->imports('app/Core') as [$file, $class]) {
            $from = $this->context($file);
            $to = preg_match('/\AApp\\\\Core\\\\([A-Za-z]+)\\\\/', $class, $m) === 1 ? $m[1] : null;

            if ($from !== null && $to !== null && $from !== $to
                && in_array($from, self::SPLIT_CONTEXTS, true) && in_array($to, self::SPLIT_CONTEXTS, true)) {
                $edges["{$from} -> {$to}"] = true;
            }
        }

        $found = array_keys($edges);
        sort($found);
        $allowed = self::SPLIT_EDGES_ALLOWED;
        sort($allowed);

        $this->assertSame([], array_values(array_diff($found, $allowed)), '割った文脈（セクション・テーマ・サンプル・写真・店）の間に新しい向きが生えた。混ざり直さないよう、向きを無くすか、理由を書いて一覧（SPLIT_EDGES_ALLOWED）に足す');
        $this->assertSame([], array_values(array_diff($allowed, $found)), '割った文脈の間の向きが減った。一覧（SPLIT_EDGES_ALLOWED）からも消す');
    }

    public function test_nothing_in_the_core_below_depends_on_the_order_flow(): void
    {
        $upward = [];

        foreach ($this->imports('app/Core') as [$file, $class]) {
            if (! str_starts_with($file, 'app/Core/Order/') && str_starts_with($class, 'App\\Core\\Order\\')) {
                $upward[] = "{$file}: {$class}";
            }
        }

        $this->assertSame([], $upward, '注文の流れ（Core\\Order）は核のいちばん上の段。取引・支払い・配送などから頼らない');
    }

    /**
     * 核は商品の CSV を持たない（ADR 0107 の段 1）。商品の CSV は App（plugins/plugin-product-csv）で、
     * 配送の CSV（plugin-shipping-csv）と同じ居場所。核へ戻すと、同じ形の機能が核と App に分かれる
     */
    public function test_the_core_does_not_hold_the_product_csv(): void
    {
        $this->assertDirectoryDoesNotExist(dirname(__DIR__, 2).'/app/Core/ProductCsv', '商品の CSV は App（plugins/plugin-product-csv）。核に戻さない');

        // 核は App の名前空間（EcSugar\Plugin…）を知らない。向きは App → 核だけ（App は核の口を使う）
        $root = dirname(__DIR__, 2);
        $inward = [];

        foreach ($this->files('app/Core') as $relative) {
            preg_match_all('/^use (EcSugar\\\\[A-Za-z0-9\\\\]+)(?: as \w+)?;/m', (string) file_get_contents("{$root}/{$relative}"), $matches);

            foreach ($matches[1] as $class) {
                $inward[] = "{$relative}: {$class}";
            }
        }

        $this->assertSame([], $inward, '核が App（EcSugar\\Plugin…）を知っている。核からは App を名前で呼ばず、App の側から核の口を使う');
    }

    public function test_the_admin_and_the_storefront_do_not_grow_into_each_other(): void
    {
        $adminUses = [];
        $storefrontUses = [];

        foreach ($this->imports('app/Admin') as [, $class]) {
            if (str_starts_with($class, 'App\\Storefront\\')) {
                $adminUses[$class] = true;
            }
        }

        foreach ($this->imports('app/Storefront') as [$file, $class]) {
            if (str_starts_with($class, 'App\\Admin\\')) {
                $storefrontUses["{$file}: {$class}"] = true;
            }
        }

        $this->assertSame([], array_values(array_diff(array_keys($adminUses), self::ADMIN_USES_STOREFRONT)), '管理画面がショップの新しい部品を使っている。核へ移せないかを先に考える');
        $this->assertSame([], array_values(array_diff(array_keys($storefrontUses), self::STOREFRONT_USES_ADMIN)), 'ショップが管理画面の部品を使っている');
    }

    /**
     * App の口（Core\Plugin）が知っている核の文脈。口を 1 つ足すたびに、核の中の結線が黙って広がらないようにする。
     * Core\Plugin は 8,700 行を超えて核でいちばん大きく、11-01 から 4 倍に伸びた（新しい口はほぼここに足される）。
     * 割るのは循環が 3 つできるのでやらないと決めた（backlog の ）。代わりに「どこまで知ってよいか」を固定する。
     * 増やすときは、その文脈を App の口が直に知る必要があるか（値を渡してもらう形にできないか）を先に考え、理由をここに書く
     *
     * @var list<string>
     */
    private const array PLUGIN_KNOWS = [
        'Crypto',       // App の秘密の設定を店の鍵で包む
        'Event',        // App の後片付けと記録
        'Listing',      // 商品の画面の欄・出す出さない・説明のブロックの上限
        'Media',        // App の設定に置く写真
        'Money',        // 料金の行の按分
        'RateLimit',    // App の受け口の回数の制限
        'Sample',       // 入れたときのサンプルとデモの店
        'Section',      // App の区画
        'Transaction',  // 注文に料金の行を足す口・売り方を押さえる口
    ];

    public function test_the_plugin_context_does_not_reach_into_more_of_the_core(): void
    {
        $knows = [];

        foreach ($this->imports('app/Core/Plugin') as [, $class]) {
            if (preg_match('/\AApp\\\\Core\\\\([A-Za-z]+)\\\\/', $class, $m) === 1 && $m[1] !== 'Plugin') {
                $knows[$m[1]] = true;
            }
        }

        $found = array_keys($knows);
        sort($found);
        $allowed = self::PLUGIN_KNOWS;
        sort($allowed);

        $this->assertSame([], array_values(array_diff($found, $allowed)), 'App の口（Core\\Plugin）が、核の新しい文脈を直に知った。値を渡してもらう形にできないかを先に考える（増やすなら PLUGIN_KNOWS に理由を書く）');
        $this->assertSame([], array_values(array_diff($allowed, $found)), 'App の口が知らなくなった文脈がある。一覧（PLUGIN_KNOWS）からも消す');
    }

    /**
     * 決済代行ごとの印の形（鍵・支払いの画面・連結の口座の頭）を、ドライバと鍵の置き場の外で**文字列として**持たない。
     * `use` の行だけを見る上の規則はこれを捕まえられず、実際に「支払いの画面の ID が cs_test_ で始まるか」で
     * テストのモードを見分けている所が 2 つ残っていた（支払いの試みの test_mode 列を見る形に直した）。
     * ADR 0107 の段 3b で Stripe が外へ出ると、別のドライバの店ではこの手の見分けが黙って誤答する。
     *
     * 一覧は減る方向にだけ動かす。コメントと docblock は見ない（境界の説明そのものを書けなくなるため）
     *
     * @var list<string>
     */
    private const array PAYMENT_SHAPES_ALLOWED = [
        // 上の STRIPE_ALLOWED と同じ所（ドライバ・鍵の値・鍵の置き場・つなぐ手順・貼る画面の API・中央 DB の連結アカウント）
        'app/Core/Payment/Stripe/',
        'app/Core/Payment/StripeCredentials.php',
        'app/Core/Payment/Services/PaymentGatewayService.php',
        'app/Core/Payment/Services/StripeConnectService.php',
        'app/Admin/Http/Controllers/Api/PaymentSettingsController.php',
        'app/Storefront/Console/RefreshStripeConnectCommand.php',
        'app/Models/StripeConnectedAccount.php',
        // デモの店を作り直すときに、持ち越す鍵がテストの鍵かを念のため確かめている（本当の関所は
        // PaymentGatewayService::configureStripe で、そちらが live を断る）。段 3b で口へ寄せる（backlog の ）
        'app/Admin/Demo/DemoShops.php',
    ];

    /** 決済代行の印の形（Stripe の鍵・支払いの画面・連結の口座）。SQL の LIKE のエスケープつきの形でも当たるよう、下線の前のバックスラッシュを許す（これが無くて 2 か所見逃した） */
    private const string PAYMENT_SHAPES = '/\b(?:sk|rk|pk)\\\\*_(?:test|live)\\\\*_|\bcs\\\\*_(?:test|live)\\\\*_|\bwhsec\\\\*_|\bacct\\\\*_1/';

    public function test_no_one_outside_the_payment_driver_matches_the_shape_of_a_providers_identifiers(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (['app', 'plugins'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$directory}", \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($root) + 1);

                if (array_filter(self::PAYMENT_SHAPES_ALLOWED, static fn (string $allowed): bool => str_starts_with($relative, $allowed)) !== []) {
                    continue;
                }

                foreach ($this->codeStrings($file->getPathname()) as $line => $text) {
                    if (preg_match(self::PAYMENT_SHAPES, $text) === 1) {
                        $offenders[] = "{$relative}:{$line}: {$text}";
                    }
                }
            }
        }

        sort($offenders);
        $this->assertSame([], $offenders, '決済代行の印の形をドライバの外で持っている。テストのモードは payment_attempts.test_mode、支払いの突き合わせは provider と provider_ref をそのまま使う（ADR 0107 の段 3）');
    }

    /**
     * ファイルの中の文字列と変数の名前（コメント・docblock を除いた中身）を 1 行ずつ返す
     *
     * @return array<int, string>
     */
    private function codeStrings(string $path): array
    {
        $found = [];

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $found[$token[2]] = ($found[$token[2]] ?? '').' '.$token[1];
        }

        return $found;
    }

    public function test_only_the_payment_driver_and_its_settings_know_stripe(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/app", \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);

            if (array_filter(self::STRIPE_ALLOWED, static fn (string $allowed): bool => str_starts_with($relative, $allowed)) !== []) {
                continue;
            }

            preg_match_all('/^use ([A-Za-z0-9\\\\]+)(?: as \w+)?;/m', (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $class) {
                if (str_contains($class, 'Stripe')) {
                    $offenders[] = "{$relative}: {$class}";
                }
            }
        }

        sort($offenders);
        $this->assertSame([], $offenders, '核の金の流れ・店頭・App は決済代行の具象を知らない。口（PaymentDriver・PaymentCredentialStore・PaymentWebhookReceiver）を通す（ADR 0107 の段 3）');
    }

    /**
     * @return list<array{0: string, 1: string}> [リポジトリからのファイルの道筋, 読み込んだクラス]
     */
    private function imports(string $directory): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        foreach ($this->files($directory) as $relative) {
            preg_match_all('/^use (App\\\\[A-Za-z0-9\\\\]+)(?: as \w+)?;/m', (string) file_get_contents("{$root}/{$relative}"), $matches);

            foreach ($matches[1] as $class) {
                $found[] = [$relative, $class];
            }
        }

        return $found;
    }

    /**
     * そのフォルダの下の PHP のファイル（リポジトリからの道筋）
     *
     * @return list<string>
     */
    private function files(string $directory): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$directory}", \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $found[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        return $found;
    }

    private function context(string $file): ?string
    {
        return preg_match('#\Aapp/Core/([A-Za-z]+)/#', $file, $m) === 1 ? $m[1] : null;
    }
}
