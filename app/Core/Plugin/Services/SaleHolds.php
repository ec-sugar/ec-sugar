<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * App が売り方を押さえた商品（ADR 0080。SaleHoldProvider）。この店で使っている App のうち、口を持つものだけに聞く。
 * カート（入れる時と、入った後の行の見せ方。注文の確定もカートの読み出しを通して同じ答えで確かめる）・1 品の注文・商品の画面のほかの App の買い方が使う。
 * App が落ちたら押さえていないとして扱い（ショップを止めない）、壊れたら知らせる（ADR 0043）。押さえる App の数え直しは App の側（抽選の当選の数は在庫まで）
 */
final class SaleHolds
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    /**
     * @param  list<string>  $listingIds
     * @return array<string, array{plugin: string, message: string}> 商品の ID => 押さえた App の名前と断りの文
     */
    public function of(array $listingIds): array
    {
        $listingIds = array_values(array_unique(array_filter($listingIds, static fn (string $id): bool => preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) === 1)));

        if ($listingIds === []) {
            return [];
        }

        $held = [];

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->saleHold === null || ! $this->states->enabled($plugin->name)) {
                continue;
            }

            try {
                $answer = $plugin->saleHold->holds($listingIds);
            } catch (Throwable $e) {
                report($e);
                Log::error('App の売り方の押さえを読めなかった', ['plugin' => $plugin->name, 'error' => $e::class]);

                continue;
            }

            foreach ($answer as $listingId => $message) {
                // 頼んだ商品だけ（App の誤りでほかの商品を止めない）。先に押さえた App を残す
                if (in_array($listingId, $listingIds, true) && ! isset($held[$listingId]) && $message !== '') {
                    $held[$listingId] = ['plugin' => $plugin->name, 'message' => mb_substr($message, 0, 120)];
                }
            }
        }

        return $held;
    }

    /**
     * 1 つの商品の押さえ（押さえていなければ null）
     *
     * @return array{plugin: string, message: string}|null
     */
    public function for(string $listingId): ?array
    {
        return $this->of([$listingId])[$listingId] ?? null;
    }
}
