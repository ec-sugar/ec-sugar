<?php

declare(strict_types=1);

namespace App\Core\Order;

use App\Core\Event\EventStore;
use App\Core\Plugin\Contracts\PreorderHandler;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Preorder\PreorderTerms;
use App\Core\Plugin\Preorder\PreorderUnavailable;
use App\Core\Plugin\Services\PluginStates;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 予約販売（ADR 0073）の核の側。予約販売の口（PreorderHandler）を持つ App をこの店で使っている時だけ、発売日の前の商品を在庫によらず予約で受ける。
 *
 * - 商品の決まり（発売日・残り）は App に聞く。ショップの窓口（Catalog）が在庫の代わりに使い、注文の確定もカートの読み出しを通して同じ答えで確かめる
 * - 予約の品のある注文の印は、注文の確定と同じトランザクションのイベント order.preorder_placed（発送を始める日と商品ごとの数。個人情報は無い）。
 *   受渡は発送を始める日（fulfillments.ships_on）を持ち、その日まで発送できない（FulfillmentService が断る）。App を使うのをやめても、印と日は残る
 */
final class Preorders
{
    public const string PLACED = 'order.preorder_placed';

    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
        private readonly EventStore $events,
    ) {}

    /**
     * この店で使っている予約販売の App の口（無ければ null）
     */
    public function handler(): ?PreorderHandler
    {
        $plugin = $this->plugins->preorderPlugin();

        return $plugin !== null && $this->states->enabled($plugin->name) ? $plugin->preorder : null;
    }

    /**
     * 発売日の前の予約の商品の決まり（App を使っていない店は空）。App が落ちたら空（予約でない商品として在庫で売る。ショップは止めない）
     *
     * @param  list<string>  $listingIds
     * @return array<string, PreorderTerms>
     */
    public function terms(array $listingIds): array
    {
        $listingIds = array_values(array_unique(array_filter($listingIds, static fn (string $id): bool => preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) === 1)));
        $handler = $listingIds === [] ? null : $this->handler();

        if ($handler === null) {
            return [];
        }

        try {
            $terms = [];
            $today = PreorderTerms::today();

            foreach ($handler->terms($listingIds) as $listingId => $term) {
                // 頼んだ商品で、発売日が今日より後のものだけ（App の誤りで発売日の後の商品を予約にしない）
                if ($term->listingId === $listingId && in_array($listingId, $listingIds, true) && $term->releaseDate > $today) {
                    $terms[$listingId] = $term;
                }
            }

            return $terms;
        } catch (Throwable $e) {
            Log::error('予約販売の決まりを読めなかった', ['error' => $e::class]);

            return [];
        }
    }

    /**
     * 注文の確定の中で呼ぶ（連鎖のロックの後）。印を残し、App に予約の数を数え直させる。App が無い・受けられなければ PreorderUnavailable
     *
     * @param  array<string, int>  $quantities  商品の ID => 数
     */
    public function place(string $transactionId, array $quantities, string $shipsOn, string $actor): void
    {
        $handler = $this->handler() ?? throw new PreorderUnavailable;
        ksort($quantities);
        $this->events->append(self::PLACED, 'transaction', $transactionId, ['ships_on' => $shipsOn, 'listings' => $quantities], $actor);
        $handler->reserve($transactionId, $quantities, $actor);
    }
}
