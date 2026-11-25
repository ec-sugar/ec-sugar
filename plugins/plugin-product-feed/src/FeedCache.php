<?php

declare(strict_types=1);

namespace EcSugar\PluginProductFeed;

use App\Core\Event\Models\DomainEvent;
use Illuminate\Support\Facades\Cache;

/**
 * 作ったフィードを 1 時間持つ（店の文脈の中で使う。キャッシュは店ごとに分かれる。stancl/tenancy のキャッシュの印）。
 * 鍵に店の記録の連鎖のいちばん新しい番号（sequence）を入れるので、商品・種類・在庫・写真・設定・App の欄を直すと
 * （どれもイベントを残す）次の読み込みから新しい鍵になり、作り直す。古い鍵は 1 時間で消える。
 * 置くのは文字と配列だけ（キャッシュは PHP のクラスを戻さない設定。config/cache.php の serializable_classes）
 */
final class FeedCache
{
    public const int TTL = 3600;

    public function __construct(
        private readonly FeedBuilder $builder,
        private readonly FeedWriter $writer,
    ) {}

    /**
     * フィードの XML（ショップのホストの URL で作る。鍵にホストを入れ、別のドメインの URL を混ぜない）
     */
    public function xml(FeedFormat $format, string $shopName, string $shopUrl): string
    {
        $key = 'product-feed:xml:'.$format->value.':'.hash('sha256', $shopUrl).':'.self::version();

        return (string) Cache::remember($key, self::TTL, fn (): string => $this->writer->write($format, $this->builder->build(), $shopName, $shopUrl));
    }

    /**
     * 管理画面に出す数と出さなかった商品（URL は入れない）
     *
     * @return array{rows: int, listings: int, excluded: array<string, string>, skipped: array<string, array{title: string, reason: string}>, over: int}
     */
    public function summary(): array
    {
        /** @var array{rows: int, listings: int, excluded: array<string, string>, skipped: array<string, array{title: string, reason: string}>, over: int} */
        return Cache::remember('product-feed:summary:'.self::version(), self::TTL, function (): array {
            $result = $this->builder->build();

            return ['rows' => count($result->items), 'listings' => $result->listings, 'excluded' => $result->excluded, 'skipped' => $result->skipped, 'over' => $result->over];
        });
    }

    /**
     * 店の記録の連鎖のいちばん新しい番号（何かを直すと増える）
     */
    public static function version(): int
    {
        return (int) DomainEvent::query()->max('sequence');
    }
}
