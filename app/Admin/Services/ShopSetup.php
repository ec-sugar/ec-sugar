<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Payment\Contracts\PaymentCredentialStore;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Sample\Services\ShopSamples;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Shop\Services\StorefrontAccessService;
use App\Core\Shop\ShopStatus;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use LogicException;

/**
 * 開設の手順（管理画面のホーム）。済んだかは店のデータから決める（手で「済」にしない）。読むだけで、何も書かない。
 * 置き場は管理画面（で核から移した）。店・商品・支払い・テーマ・サンプル・App をまたいで束ねる画面のための読み取りなので、核のどの文脈にも属さない。
 *
 * 手順と「済」の決まり（並びはこのまま画面に出す）:
 *   legal     店の情報と特商法: 表記が 1 項目以上あり、入れたときのままのサンプルの見本ではない
 *   shipping  送料: お店が送料を保存したことがある（最後の送料の記録がサンプルを入れたコマンドのものではない）
 *   payment   支払い: お支払いを受けられる（Stripe の鍵を置いたか、接続して登録が済んだ）。テストのモードでも済（mode で知らせる）
 *   listing   最初の商品: 公開中の商品が 1 つ以上あり、入れたときのままのサンプルではない（書き換えたサンプルはお店の物）
 *   design    デザイン: 使っているテーマの中身を公開したことがあり、入れたときのままのサンプルではない
 *   publish   公開: ショップの状態が「公開」
 *   customers お客さんを集める: タグの App（ショップに差し込むスクリプトを持つ App）を使っていて、入れるべき設定がそろっている
 *
 * 返すのは手順の名前・済か・少しの補足（数と状態）だけ。個人情報・鍵・設定の値は返さない
 */
final class ShopSetup
{
    /** 手順の並び（画面の番号の順） */
    public const array STEPS = ['legal', 'shipping', 'payment', 'listing', 'design', 'publish', 'customers'];

    public function __construct(
        private readonly ShopSamples $samples,
        private readonly ShopSettingsService $settings,
        private readonly PaymentCredentialStore $gateways,
        private readonly StorefrontAccessService $access,
        private readonly ThemeRegistry $themes,
        private readonly ThemeContentService $content,
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
        private readonly PluginSettings $pluginSettings,
    ) {}

    /**
     * @return array{steps: list<array{key: string, done: bool, mode?: string|null}>, done: int, total: int, opened: bool, samples: array{installed: bool, remaining: array{listings: int, categories: int, photos: int, content: bool, legal: bool}|null}}
     */
    public function steps(): array
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw new LogicException('開設の手順は店の文脈の中でだけ読む');
        }

        $untouched = $this->samples->untouched();
        // テスト用の鍵（ADR 0096）は公開した後に使わないので、ふだんの鍵で見る
        $paymentMode = $this->gateways->primaryMode();
        $opened = $this->access->status() === ShopStatus::Open;

        $steps = [
            ['key' => 'legal', 'done' => $this->settings->legal() !== [] && ! $untouched['legal']],
            ['key' => 'shipping', 'done' => $this->shippingDecided()],
            // テストの鍵かどうかは支払いの設定の画面（閲覧の段階から読める）にも出している
            ['key' => 'payment', 'done' => $paymentMode !== null, 'mode' => $paymentMode],
            ['key' => 'listing', 'done' => Listing::query()->where('status', ListingStatus::Active)->whereNotIn('id', $untouched['listings'])->exists()],
            ['key' => 'design', 'done' => $this->designPublished($tenant, $untouched['theme_versions'])],
            ['key' => 'publish', 'done' => $opened],
            ['key' => 'customers', 'done' => $this->collectsVisitors()],
        ];

        return [
            'steps' => $steps,
            'done' => count(array_filter($steps, static fn (array $step): bool => $step['done'])),
            'total' => count($steps),
            'opened' => $opened,
            'samples' => ['installed' => $untouched['installed'], 'remaining' => $this->samples->summary()],
        ];
    }

    /**
     * お店が送料を決めたか。新しい店の送料（0 円・無料の線なし）は決めた値と区別できないので、保存の記録で見る。
     * サンプルが入れた送料は、お店が確かめて保存し直すまで「済」にしない
     */
    private function shippingDecided(): bool
    {
        // 送料の表（ADR 0064）の記録と、その前の全国一律の送料の記録
        $actor = DomainEvent::query()->whereIn('type', ['shop.shipping_methods_updated', 'shop.shipping_updated'])->orderByDesc('sequence')->value('actor');

        return $actor !== null && $actor !== ShopSamples::ACTOR;
    }

    /**
     * 使っているテーマの中身を公開したか（入れたときのままのサンプルの中身は数えない）
     *
     * @param  list<string>  $sampleVersions  手つかずのサンプルのテーマの中身の ID
     */
    private function designPublished(Tenant $tenant, array $sampleVersions): bool
    {
        $version = $this->content->current($this->themes->forTenant($tenant)->name);

        return $version !== null && $version->published_at !== null && ! in_array($version->id, $sampleVersions, true);
    }

    /**
     * タグの App を使っていて、ショップに差し込める（入れるべき設定がそろった）か。ショップが差し込むときと同じ決まり（StorefrontApps）
     */
    private function collectsVisitors(): bool
    {
        $states = $this->states->all();
        $stored = $this->pluginSettings->storedValues();

        foreach ($this->plugins->all() as $plugin) {
            if (($states[$plugin->name]['enabled'] ?? false) && $plugin->app()->scripts !== []
                && $this->pluginSettings->publicValues($plugin->name, $stored[$plugin->name] ?? []) !== null) {
                return true;
            }
        }

        return false;
    }
}
