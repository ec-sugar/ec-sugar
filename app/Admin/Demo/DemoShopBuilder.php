<?php

declare(strict_types=1);

namespace App\Admin\Demo;

use App\Core\Discount\Models\Coupon;
use App\Core\Discount\Services\CouponService;
use App\Core\Event\EventStore;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Services\ListingService;
use App\Core\Payment\Services\DirectMethodSettings;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\AdminForms;
use App\Core\Plugin\Services\AdminPages;
use App\Core\Plugin\Services\AppSectionCatalog;
use App\Core\Plugin\Services\ListingFileUploads;
use App\Core\Plugin\Services\ListingPanels;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Points\Services\PointSettings;
use App\Core\Sample\Models\DemoStep;
use App\Core\Sample\Models\SampleMark;
use App\Core\Sample\SampleSets;
use App\Core\Sample\Services\SampleInstaller;
use App\Core\Sample\Services\ShopSamples;
use App\Core\Section\Models\ShopPage;
use App\Core\Section\Services\ShopPageService;
use App\Core\Shop\Services\DeliveryDates;
use App\Core\Shop\Services\ShippingMethods;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\Shipping\Regions;
use App\Core\Shop\ShopStatus;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * デモの店の中身を入れる（ADR 0086。店の文脈の中で呼ぶ）。
 *
 * - 商品・カテゴリ・テーマの中身・特商法の表記・送料は、入れたときのサンプルと同じ仕組み（SampleInstaller）で入れ、入れ終えたら
 *   サンプルの印を閉じてお店の物にする（管理画面に「サンプルをまとめて消す」を出さない）
 * - App の設定・商品の欄・App の画面の記録・クーポン・お知らせ・ページ・店の設定は、どれも管理画面と同じ核のサービスを通す
 *   （連鎖のロックとイベント。記録の actor は cli:demo-shop）
 * - 済ませた手順は demo_steps に印を付け、流し直しても二重に作らない（冪等）。手順と印は同じトランザクション。最後に `built:<扱い>` を付ける（毎晩の戻しの条件の 1 つ）
 * - 入っていない App（公開用の 1.0 の木には定期便・予約・チケット・抽選販売が無い。ADR 0098）は使おうとせず飛ばす。その App の欄・記録・記事と、
 *   作らなかった商品（その App の扱いの商品）の欄とファイルも飛ばす
 */
final class DemoShopBuilder
{
    public const string ACTOR = 'cli:demo-shop';

    /** @var array<string, string> 写真の名前 => 取れなかった理由 */
    private array $missing = [];

    /** @var (Closure(string): void)|null */
    private ?Closure $say = null;

    public function __construct(
        private readonly EventStore $events,
        private readonly SampleInstaller $installer,
        private readonly ShopSamples $samples,
        private readonly PluginStates $states,
        private readonly PluginSettings $pluginSettings,
        private readonly ListingPanels $panels,
        private readonly ListingFileUploads $files,
        private readonly AdminForms $forms,
        private readonly AdminPages $adminPages,
        private readonly CouponService $coupons,
        private readonly ShopPageService $pages,
        private readonly ThemeContentService $content,
        private readonly ThemeRegistry $themes,
        private readonly AppSectionCatalog $appSections,
        private readonly PointSettings $points,
        private readonly DeliveryDates $delivery,
        private readonly DirectMethodSettings $direct,
        private readonly ShippingMethods $shipping,
        private readonly ListingService $listings,
        private readonly ShopSettingsService $settings,
        private readonly StorefrontAccessService $access,
        private readonly PluginRegistry $plugins,
    ) {}

    /**
     * 中身を入れる（もう入っている物は飛ばす）
     *
     * @param  string  $photos  写真のフォルダ（`<名前>.webp|jpg|jpeg|png`）
     * @param  array<string, string>  $missing  もう分かっている取れなかった写真
     * @param  (Closure(string): void)|null  $say  途中の知らせ
     * @return array{added: int, missing: array<string, string>}
     */
    public function build(DemoDefinition $def, string $photos, array $missing = [], ?Closure $say = null): array
    {
        $this->say = $say;
        $this->missing = $missing;
        $added = 0;

        $absent = array_values(array_filter(array_keys($def->apps()), fn (string $plugin): bool => ! $this->plugins->has($plugin)));

        if ($absent !== []) {
            $this->tell('入っていない App を飛ばします: '.implode('・', $absent));
        }

        // App を先に（商品の欄は、その App を使っている店でだけ書ける）
        foreach ($def->apps() as $plugin => $values) {
            if (! $this->plugins->has($plugin)) {
                continue;
            }

            $added += $this->once('app:'.$plugin, function () use ($plugin, $values): void {
                $this->states->set($plugin, true, self::ACTOR);

                if ($values !== []) {
                    $this->pluginSettings->update($plugin, $values, self::ACTOR);
                }
            });
        }

        $this->tell('商品と写真を入れています');
        $result = $this->installer->run($def, $photos, false, $this->missing, fn (string $message) => $this->tell($message), self::ACTOR);
        $this->missing = $result['missing'];
        $added += $result['added'];
        $this->samples->adopt($def);
        // 作った商品（扱いを足す App が入っていない商品は作っていない）
        $made = $def->listings();

        foreach ($def->panels() as $key => $plugins) {
            if (! isset($made[$key])) {
                continue;
            }

            foreach ($plugins as $plugin => $values) {
                if (! $this->plugins->has($plugin)) {
                    continue;
                }

                $added += $this->once('panel:'.substr(hash('sha256', "{$key}|{$plugin}"), 0, 24), function () use ($def, $key, $plugin, $values): void {
                    $this->panels->write($plugin, $this->listing($def, $key), $this->resolveAll($def, $values), self::ACTOR);
                });
            }
        }

        foreach ($def->files() as $key => $files) {
            if (! isset($made[$key])) {
                continue;
            }

            foreach ($files as [$name, $recipe]) {
                $added += $this->once('file:'.substr(hash('sha256', "{$key}|{$name}"), 0, 24), fn () => $this->upload($this->listing($def, $key), $name, $recipe));
            }
        }

        foreach ($def->forms() as $plugin => $records) {
            if (! $this->plugins->has($plugin)) {
                continue;
            }

            foreach ($records as $index => $values) {
                $added += $this->once('form:'.substr(hash('sha256', "{$plugin}|{$index}"), 0, 24), fn () => $this->forms->create($plugin, $this->resolveAll($def, $values), self::ACTOR));
            }
        }

        foreach ($def->coupons() as $index => $coupon) {
            $added += $this->once('coupon:'.substr(hash('sha256', (string) ($coupon['code'] ?? $coupon['label'] ?? $index)), 0, 24), fn () => $this->coupon($def, $coupon));
        }

        foreach ($def->posts() as $post) {
            if (isset($post['app']) && ! $this->plugins->has($post['app'])) {
                continue;
            }

            $added += $this->once('post:'.$post['slug'], function () use ($post): void {
                $id = $this->forms->create('blog', ['title' => $post['title'], 'slug' => $post['slug'], 'new_category' => 'お知らせ', 'excerpt' => $post['excerpt'], 'body' => $post['body']], self::ACTOR);
                $this->adminPages->act('blog', $id, 'publish', self::ACTOR, static fn () => null);
            });
        }

        $added += $this->applySettings($def);
        $added += $this->once('page:about', fn () => $this->page($def, 'このお店のこと', 'about', true, $this->aboutSections($def)));
        $added += $this->once('page:photo-credits', fn () => $this->page($def, '写真のクレジット', 'photo-credits', false, $this->creditSections($def)));

        // 公開する（デモの店は誰でも見られる）
        if ($this->access->status() !== ShopStatus::Open) {
            $this->access->setStatus(ShopStatus::Open, self::ACTOR);
        }

        $added += $this->once('built:'.$def->kind(), static fn () => null);

        return ['added' => $added, 'missing' => $this->missing];
    }

    /**
     * 店の設定（ポイント・お届け日時・銀行振込と代引き・店頭受け取り・注文の案内）
     */
    private function applySettings(DemoDefinition $def): int
    {
        $settings = $def->settings();
        $added = 0;

        if (isset($settings['points'])) {
            $added += $this->once('settings:points', fn () => $this->points->update($settings['points'], self::ACTOR));
        }

        if (isset($settings['delivery'])) {
            $added += $this->once('settings:delivery', fn () => $this->delivery->update($settings['delivery'], self::ACTOR));
        }

        if (isset($settings['direct'])) {
            $added += $this->once('settings:direct', fn () => $this->direct->update($settings['direct'], self::ACTOR));
        }

        if (isset($settings['order_terms'])) {
            $added += $this->once('settings:order-terms', fn () => $this->settings->setOrderTerms($settings['order_terms'], self::ACTOR));
        }

        if (isset($settings['pickup']) && is_array($settings['pickup'])) {
            $added += $this->once('settings:pickup', function () use ($def, $settings): void {
                $methods = array_map(static fn (array $m): array => ['id' => $m['id'], 'name' => $m['name'], 'free_over' => $m['free_over'], 'rates' => $m['rates']], $this->shipping->all());
                $methods[] = ['id' => null, 'name' => (string) ($settings['pickup_name'] ?? '店頭でお受け取り'), 'free_over' => null, 'rates' => array_fill_keys(Regions::PREFECTURES, 0)];
                $saved = $this->shipping->replace(['methods' => $methods], self::ACTOR);
                $pickup = end($saved)['id'] ?? throw new LogicException('店頭受け取りの配送の方法を作れませんでした');

                foreach ($settings['pickup'] as $key) {
                    $listing = $this->listing($def, (string) $key);
                    $this->listings->update($listing, $listing->version, ['shipping_method_id' => $pickup], self::ACTOR);
                }
            });
        }

        return $added;
    }

    /**
     * 済ませていない手順だけを行い、印を付ける
     *
     * @param  Closure(): mixed  $work
     * @return int 行ったら 1
     */
    private function once(string $key, Closure $work): int
    {
        // 手順と印を同じトランザクションで（手順の途中で落ちたら印も残らず、次に流した時にもう一度。印だけ残って手順が抜けることが無い）。
        // 連鎖のロックを先に取るので、同じ店で 2 つ流しても手順は 1 回だけ（中のサービスは同じロックを取り直すだけ）
        return DB::transaction(function () use ($key, $work): int {
            $this->events->lock();

            if (DemoStep::query()->where('key', $key)->exists()) {
                return 0;
            }

            $work();
            DemoStep::query()->create(['key' => $key]);

            return 1;
        });
    }

    /**
     * デモの商品（入れたときのサンプルの印から。無ければ null）
     */
    public static function find(DemoDefinition $def, string $key): ?Listing
    {
        $id = SampleMark::query()->where('key', SampleSets::key($def, 'listing', $key))->value('subject_id');

        return is_string($id) ? Listing::query()->find($id) : null;
    }

    private function listing(DemoDefinition $def, string $key): Listing
    {
        return self::find($def, $key) ?? throw new LogicException("デモの商品が見つかりません: {$key}");
    }

    /**
     * 値の中の名前と日付を替える
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function resolveAll(DemoDefinition $def, array $values): array
    {
        $out = [];

        foreach ($values as $name => $value) {
            $out[$name] = is_string($value) ? $this->resolve($def, $value) : $value;
        }

        return $out;
    }

    private function resolve(DemoDefinition $def, string $value): string
    {
        if (preg_match('/\A\+(\d{1,3})d(?: (\d{2}):(\d{2}))?\z/', $value, $m) === 1) {
            $day = CarbonImmutable::now('Asia/Tokyo')->addDays((int) $m[1]);

            return isset($m[2]) ? $day->setTime((int) $m[2], (int) $m[3])->format('Y-m-d H:i') : $day->format('Y-m-d');
        }

        if (preg_match('/\Anow(?:-(\d{1,2})h)?\z/', $value, $m) === 1) {
            return CarbonImmutable::now('Asia/Tokyo')->subHours((int) ($m[1] ?? 0))->format('Y-m-d H:i');
        }

        if (preg_match('/\Alisting:([a-z0-9-]+)\z/', $value, $m) === 1) {
            return $this->listing($def, $m[1])->id;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $coupon
     */
    private function coupon(DemoDefinition $def, array $coupon): void
    {
        if (isset($coupon['code']) && Coupon::query()->where('code', strtoupper((string) $coupon['code']))->exists()) {
            return;
        }

        if (isset($coupon['target_ids']) && is_array($coupon['target_ids'])) {
            $coupon['target_ids'] = array_map(fn (string $ref): string => str_starts_with($ref, 'category:')
                ? (string) DB::table('categories')->where('slug', substr($ref, 9))->value('id')
                : $this->resolve($def, $ref), $coupon['target_ids']);
        }

        $this->coupons->create($coupon, self::ACTOR);
    }

    /**
     * ダウンロード販売の商品にファイルを置く（核の受け取りの道を通す: 形・大きさ・中身の型を確かめる）
     */
    private function upload(Listing $listing, string $name, string $recipe): void
    {
        $content = match (true) {
            $recipe === 'wav' => self::silentWav(4),
            str_starts_with($recipe, 'txt:') => substr($recipe, 4)."\n",
            default => throw new LogicException("ファイルの作り方が違います: {$recipe}"),
        };

        $path = tempnam(sys_get_temp_dir(), 'demo-file-');

        if ($path === false) {
            throw new LogicException('一時ファイルを作れません');
        }

        try {
            file_put_contents($path, $content);
            $started = $this->files->start('downloads', $listing, $name, (string) strlen($content), self::ACTOR);
            $this->files->chunk('downloads', $listing, $started['upload'], '0', new UploadedFile($path, $name, null, null, true), self::ACTOR);
            $this->files->finish('downloads', $listing, $started['upload'], self::ACTOR);
        } finally {
            @unlink($path);
        }
    }

    /**
     * 無音の WAV（8 kHz・8 ビット・モノラル。見本の音源）
     */
    private static function silentWav(int $seconds): string
    {
        $data = str_repeat(chr(128), 8000 * $seconds);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8)
            .'data'.pack('V', strlen($data)).$data;
    }

    /**
     * 自由なページを作って公開する（区画はショップのテーマの宣言で確かめる）
     *
     * @param  list<array<string, mixed>>  $sections
     */
    private function page(DemoDefinition $def, string $title, string $slug, bool $nav, array $sections): void
    {
        $page = ShopPage::query()->where('slug', $slug)->first() ?? $this->pages->create(['title' => $title, 'slug' => $slug, 'in_nav' => $nav], self::ACTOR);
        $theme = $this->themes->get($def->theme());
        $schema = $this->appSections->schema($theme->schema());
        $spec = $schema->page($page->pageName()) ?? throw new LogicException('自由なページの宣言がありません');
        $version = $this->content->current($theme->name);
        $version = $this->content->savePage($theme->name, $schema, $spec->name, $version?->id, $version->revision ?? 0, $sections, self::ACTOR);
        $this->content->publish($theme->name, $schema, $version->id, $version->revision, self::ACTOR);
        $this->pages->publish($page->id, self::ACTOR);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aboutSections(DemoDefinition $def): array
    {
        return [
            ['type' => 'basic_text', 'pattern' => 'left', 'values' => ['heading' => (string) tenant()?->getAttribute('name'), 'body' => $def->story()]],
            ['type' => 'basic_text', 'pattern' => 'left', 'values' => ['heading' => 'このお店の課題と、EC-SUGAR での解決の仕方', 'body' => $def->pitch()]],
            ['type' => 'basic_text', 'pattern' => 'left', 'values' => ['heading' => 'デモのお店について', 'body' => "このお店は EC-SUGAR のデモのために作った架空のお店です。実在のお店・人・銘柄とは関係ありません。\n注文しても代金は動かず、商品は届きません（カードはテスト用の番号 4242 4242 4242 4242 で試せます）。メールは送らず、画面の上の「送るはずだったメール」に出ます。\nお店の記録は毎晩、最初の状態に戻ります。"]],
            ['type' => 'basic_button', 'pattern' => null, 'values' => ['label' => '写真のクレジット', 'link' => '/pages/photo-credits']],
        ];
    }

    /**
     * 写真のクレジット（Unsplash の写真家。入れられた写真だけ）
     *
     * @return list<array<string, mixed>>
     */
    private function creditSections(DemoDefinition $def): array
    {
        $lines = [];

        foreach ($def->photos() as $name => $source) {
            if (isset($this->missing[$name])) {
                continue;
            }

            $lines[$source['author'] ?? 'Unsplash'][] = $source['page'] ?? '';
        }

        ksort($lines);
        $rows = [];

        foreach ($lines as $author => $pages) {
            $rows[] = "{$author}（Unsplash）";

            foreach (array_filter($pages) as $page) {
                $rows[] = "  {$page}";
            }
        }

        $sections = [['type' => 'basic_text', 'pattern' => 'left', 'values' => ['heading' => '写真のクレジット', 'body' => "このデモのお店の写真は Unsplash（https://unsplash.com/）の写真です。撮影した方々に感謝します。\n写真は Unsplash のライセンス（https://unsplash.com/license）で使っています。"]]];

        foreach (array_chunk($rows, 24) as $chunk) {
            $sections[] = ['type' => 'basic_text', 'pattern' => 'left', 'values' => ['heading' => '', 'body' => implode("\n", $chunk)]];
        }

        return $sections;
    }

    private function tell(string $message): void
    {
        if ($this->say !== null) {
            ($this->say)($message);
        }
    }
}
