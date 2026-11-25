<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Plugin\Contracts\PurchaseLimitProvider;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 1 回のご注文の決まり（ADR 0109。PurchaseLimitProvider）。この店で使っている App のうち、口を持つものだけに聞く。
 * カートの組み立てが使い、注文の確定はカートの読み出しを通して同じ答えで確かめる（1 品の注文も同じ答えを見る）。
 *
 * **読めなかったら null を返し、呼ぶ側はご注文を受けない**（「1 人 1 点まで」の決まりが分からないまま、いくつでも売らない）。
 * 売り方を押さえる口（SaleHolds）が「落ちたら押さえていない扱い」なのとは逆に倒す: あちらは落ちるとほかの App の商品まで
 * 止まるが、こちらは決まりを付けた店だけが呼ばれ、落ちた時に売ってしまうと決まりそのものが破られるため
 */
final class PurchaseLimits
{
    /** App の答えを信じず、ここで絞る上限（カートの 1 行の上限より十分大きい） */
    public const int MAX_PER_ORDER = 999;

    /** 下限の上限（1 回のご注文の額の桁を越える値を App が返しても、ここで止める） */
    public const int MAX_MINIMUM = 100_000_000;

    /** @var array<string, PurchaseLimitProvider>|null この問い合わせの間の控え（上限と下限で App の使う・使わないを 2 度引かない） */
    private ?array $providers = null;

    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    /**
     * 商品ごとの 1 回のご注文の上限（上限の無い商品は入らない）。App が 2 つ答えたら小さい方。読めなければ null
     *
     * @param  list<string>  $listingIds
     * @return array<string, int>|null
     */
    public function perOrder(array $listingIds): ?array
    {
        $listingIds = array_values(array_unique(array_filter($listingIds, static fn (string $id): bool => preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) === 1)));
        $limits = [];

        foreach ($this->providers() as $name => $provider) {
            try {
                $answer = $listingIds === [] ? [] : $provider->perOrder($listingIds);
            } catch (Throwable $e) {
                return $this->unreadable($name, $e);
            }

            foreach (self::untrusted($answer) as $listingId => $limit) {
                // 頼んだ商品だけ。数は 1 以上だけ（0 以下の答えは、決まりが読めなかった扱い＝売らない側）
                if (! in_array($listingId, $listingIds, true)) {
                    continue;
                }

                // 整数でない答え（文字列・真偽・小数）も読めない扱い（比べ方しだいで「上限なし」に化けるので、信じない）
                if (! is_int($limit) || $limit < 1) {
                    return $this->unreadable($name, null);
                }

                $limits[$listingId] = min($limits[$listingId] ?? self::MAX_PER_ORDER, $limit, self::MAX_PER_ORDER);
            }
        }

        return $limits;
    }

    /**
     * カートの読み出しのための 1 回の問い合わせ: 商品ごとの上限と下限をまとめて。どちらかが読めなければ null。
     * 商品が無ければ App に聞かない（カートの無い・空の来訪者の画面でクエリを増やさない）
     *
     * @param  list<string>  $listingIds
     * @return array{perOrder: array<string, int>, minimum: int}|null
     */
    public function forCart(array $listingIds): ?array
    {
        if ($listingIds === []) {
            return ['perOrder' => [], 'minimum' => 0];
        }

        $perOrder = $this->perOrder($listingIds);
        $minimum = $perOrder === null ? null : $this->minimumSubtotal();

        return $perOrder === null || $minimum === null ? null : ['perOrder' => $perOrder, 'minimum' => $minimum];
    }

    /**
     * 1 回のご注文の商品の合計の下限（無ければ 0）。App が 2 つ答えたら大きい方。読めなければ null
     */
    public function minimumSubtotal(): ?int
    {
        $minimum = 0;

        foreach ($this->providers() as $name => $provider) {
            try {
                $answer = $provider->minimumSubtotal();
            } catch (Throwable $e) {
                return $this->unreadable($name, $e);
            }

            if ($answer < 0) {
                return $this->unreadable($name, null);
            }

            $minimum = max($minimum, min($answer, self::MAX_MINIMUM));
        }

        return $minimum;
    }

    /**
     * App の答えは、口の型の書き方（商品の ID => 整数）どおりとは限らない（PHP は配列の中身を確かめない）。
     * ここから先は中身を知らないものとして扱い、1 つずつ確かめる
     *
     * @param  array<array-key, mixed>  $answer
     * @return array<array-key, mixed>
     */
    private static function untrusted(array $answer): array
    {
        return $answer;
    }

    /**
     * @return array<string, PurchaseLimitProvider>
     */
    private function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [];

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->purchaseLimits !== null && $this->states->enabled($plugin->name)) {
                $providers[$plugin->name] = $plugin->purchaseLimits;
            }
        }

        return $this->providers = $providers;
    }

    /**
     * 読めなかった（壊れたら知らせる。ADR 0043）。値や商品の ID は記録に入れない
     */
    private function unreadable(string $plugin, ?Throwable $e): null
    {
        if ($e !== null) {
            report($e);
        }

        Log::error('App のご注文の決まりを読めなかった（ご注文を受けない側に倒す）', ['plugin' => $plugin, 'error' => $e === null ? 'bad_answer' : $e::class]);

        return null;
    }
}
