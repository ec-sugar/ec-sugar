<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Plugin\Contracts\ListingVisibilityProvider;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use WeakMap;

/**
 * App が「今はショップに出さない」「今は売らない」とした商品（ListingVisibilityProvider）。
 * この店で使っている App のうち、口を持つものだけに聞く。ショップの窓口（Catalog）が、一覧・商品の画面・札の見せ方に使う。
 *
 * - 口を持つ App が 1 つも無い・使っていない店では、何も聞かずに空を返す（DB も引かない）
 * - 同じ要求の中では聞き直さない（一覧は札ごとに札の見せ方を聞くので、要求ごとに覚える）
 * - App が落ちたら「無い」として扱い（ショップを止めない）、壊れたことを知らせる（ADR 0043）
 *
 * カートと注文の確定を断るのはこの口ではない（売り方を押さえる口 SaleHoldProvider。断りの文をお客さんに見せる）
 */
final class ListingVisibility
{
    /**
     * 要求 => [どの店の答えか, 今は出さない商品]（null は、まだ聞いていない）。
     * **店の鍵も覚える**: 1 つの処理の中でテナントを切り替えると request() は同じ実体のままなので、
     * 要求だけを鍵にすると店 A の答えが店 B に出てしまう（必ず守ること 3）
     *
     * @var WeakMap<Request, array{tenant: string|null, ids: list<string>}>|null
     */
    private ?WeakMap $hidden = null;

    /**
     * 要求 => [どの店の答えか, 商品の ID => 今は売らないか]（聞いた商品だけ）
     *
     * @var WeakMap<Request, array{tenant: string|null, known: array<string, bool>}>|null
     */
    private ?WeakMap $unsellable = null;

    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    /**
     * 今はショップに出さない商品の ID（App ごとの答えを足し合わせ、App ごとに MAX_HIDDEN 件まで）
     *
     * @return list<string>
     */
    public function hidden(): array
    {
        $request = request();
        $tenant = self::tenantKey();
        $this->hidden ??= new WeakMap;
        $memo = $this->hidden[$request] ?? null;

        if ($memo !== null && $memo['tenant'] === $tenant) {
            return $memo['ids'];
        }

        $ids = [];

        foreach ($this->providers() as $plugin => $provider) {
            try {
                $answer = $provider->hiddenListings();
            } catch (Throwable $e) {
                report($e);
                Log::error('App の「今は出さない商品」を読めなかった', ['plugin' => $plugin, 'error' => $e::class]);

                continue;
            }

            array_push($ids, ...array_slice(self::ulids($answer), 0, ListingVisibilityProvider::MAX_HIDDEN));
        }

        $ids = array_values(array_unique($ids));
        $this->hidden[$request] = ['tenant' => $tenant, 'ids' => $ids];

        return $ids;
    }

    /**
     * 頼んだ商品のうち、今は売らない商品の ID（売り切れとして見せる）
     *
     * @param  list<string>  $listingIds
     * @return list<string>
     */
    public function unsellable(array $listingIds): array
    {
        $listingIds = self::ulids($listingIds);

        if ($listingIds === []) {
            return [];
        }

        $request = request();
        $tenant = self::tenantKey();
        $this->unsellable ??= new WeakMap;
        $memo = $this->unsellable[$request] ?? null;
        // 店が変わったら覚えを捨てる（店 A の答えを店 B に出さない）
        $known = $memo !== null && $memo['tenant'] === $tenant ? $memo['known'] : [];
        $asking = array_values(array_diff($listingIds, array_keys($known)));

        if ($asking !== []) {
            foreach ($asking as $id) {
                $known[$id] = false;
            }

            foreach ($this->providers() as $plugin => $provider) {
                try {
                    $answer = $provider->unsellableListings($asking);
                } catch (Throwable $e) {
                    report($e);
                    Log::error('App の「今は売らない商品」を読めなかった', ['plugin' => $plugin, 'error' => $e::class]);

                    continue;
                }

                // 頼んだ商品だけ（App の誤りでほかの商品を売り切れにしない）
                foreach (array_intersect(self::ulids($answer), $asking) as $id) {
                    $known[$id] = true;
                }
            }

            $this->unsellable[$request] = ['tenant' => $tenant, 'known' => $known];
        }

        return array_values(array_filter($listingIds, static fn (string $id): bool => $known[$id] ?? false));
    }

    /**
     * この店で使っている、口を持つ App（名前 => 口）
     *
     * @return array<string, ListingVisibilityProvider>
     */
    private function providers(): array
    {
        if (tenant() === null) {
            return [];
        }

        $providers = [];

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->listingVisibility !== null && $this->states->enabled($plugin->name)) {
                $providers[$plugin->name] = $plugin->listingVisibility;
            }
        }

        return $providers;
    }

    /**
     * 今の店の鍵（店の外なら null）。覚えをこの鍵で分ける
     */
    private static function tenantKey(): ?string
    {
        $key = tenant()?->getTenantKey();

        return is_scalar($key) ? (string) $key : null;
    }

    /**
     * ULID の形の ID だけ（App の誤った答えで SQL を膨らませない）
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function ulids(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) === 1)));
    }
}
