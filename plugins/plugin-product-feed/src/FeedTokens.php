<?php

declare(strict_types=1);

namespace EcSugar\PluginProductFeed;

use App\Core\Event\EventStore;
use App\Core\Plugin\AdminPage\AdminActionRefused;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use Carbon\CarbonImmutable;
use EcSugar\PluginProductFeed\Models\Feed;
use Illuminate\Support\Facades\DB;

/**
 * フィードの URL の秘密のトークンを作る・作り直す・止める。書くのはここだけ。
 * どれも連鎖のロック（EventStore::lock()）の後にその行のロックを取り、同じトランザクションでイベントを残す
 * （product_feed.token_issued・product_feed.token_rotated・product_feed.token_stopped。中身は行き先の名前だけで、トークンは入れない）。
 * トークンは推測されない 160 ビットの乱数（16 進の 40 文字）。照らすのは SHA-256 の値どうしを長さの変わらない比べ方で
 */
final class FeedTokens
{
    public const string PATTERN = '[0-9a-f]{40}';

    public function __construct(private readonly EventStore $events) {}

    /**
     * まだ URL の無い行き先に作る（ある時は断る。作り直しは rotate）
     */
    public function issue(string $feedId, string $actor): void
    {
        $this->change($feedId, $actor, static function (Feed $feed): string {
            if ($feed->token_hash !== null) {
                throw new AdminActionRefused('URL はすでに作ってあります。新しくするときは「URL を作り直す」を選んでください。');
            }

            return 'product_feed.token_issued';
        }, issue: true);
    }

    /**
     * 新しい URL にする（前の URL はすぐ使えなくなる）
     */
    public function rotate(string $feedId, string $actor): void
    {
        $this->change($feedId, $actor, static function (Feed $feed): string {
            if ($feed->token_hash === null) {
                throw new AdminActionRefused('まだ URL がありません。「URL を作る」を選んでください。');
            }

            return 'product_feed.token_rotated';
        }, issue: true);
    }

    /**
     * URL を止める（読みに来ても 404。作るまでフィードは出ない）
     */
    public function stop(string $feedId, string $actor): void
    {
        $this->change($feedId, $actor, static function (Feed $feed): string {
            if ($feed->token_hash === null) {
                throw new AdminActionRefused('URL はすでに止めてあります。');
            }

            return 'product_feed.token_stopped';
        }, issue: false);
    }

    /**
     * 要求のトークンが、この行き先の今のトークンか（形の違うもの・止めた行き先は false）
     */
    public function matches(FeedFormat $format, string $token): bool
    {
        if (preg_match('/\A'.self::PATTERN.'\z/', $token) !== 1) {
            return false;
        }

        $hash = Feed::query()->where('format', $format->value)->value('token_hash');

        return is_string($hash) && hash_equals($hash, hash('sha256', $token));
    }

    /**
     * @param  callable(Feed): string  $check  行えるかを確かめ、残すイベントの名前を返す（行えなければ AdminActionRefused）
     */
    private function change(string $feedId, string $actor, callable $check, bool $issue): void
    {
        DB::transaction(function () use ($feedId, $actor, $check, $issue): void {
            $this->events->lock();
            $feed = Feed::query()->whereKey(strtolower($feedId))->lockForUpdate()->first() ?? throw new AdminRecordNotFound;
            $type = $check($feed);

            if ($issue) {
                $token = bin2hex(random_bytes(20));
                $feed->fill(['token' => $token, 'token_hash' => hash('sha256', $token), 'issued_at' => CarbonImmutable::now()]);
            } else {
                $feed->fill(['token' => null, 'token_hash' => null, 'issued_at' => null]);
            }

            $feed->save();
            $this->events->append($type, 'product_feed', $feed->id, ['format' => $feed->format->value], $actor);
        });
    }
}
