<?php

declare(strict_types=1);

namespace App\Storefront\Catalog;

use App\Core\Plugin\Contracts\CardOptionProvider;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\PurchaseOption;
use App\Core\Plugin\Services\PluginStates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use WeakMap;

/**
 * ショップの一覧の札に置く、App の「押す・戻す」のボタン（ADR 0071。App「お気に入り」のハートなど）。核の部品 <x-storefront::card-options> が札ごとに呼ぶ。
 * この店で使っている App のうち、札の口（CardOptionProvider）を持つものだけに聞く。どの App を聞くかは 1 回の要求で 1 度だけ決める
 * （札の枚数だけ「使っているか」を引かない。要求ごとに覚え、要求が終われば消える）。App が落ちても札は描く（そのボタンを出さないだけ）
 */
final class CardOptions
{
    /** @var WeakMap<Request, list<array{0: string, 1: CardOptionProvider}>>|null */
    private static ?WeakMap $providers = null;

    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    /**
     * @return list<PurchaseOption>
     */
    public function for(string $listingId): array
    {
        $options = [];

        foreach ($this->providers() as [$name, $provider]) {
            try {
                $option = $provider->forCard($listingId);
            } catch (Throwable $e) {
                Log::error('App の札のボタンを出せなかった', ['plugin' => $name, 'error' => $e::class]);

                continue;
            }

            // 札に出すのは、その App の名前のトグルだけ
            if ($option !== null && $option->plugin === $name && $option->isToggle()) {
                $options[] = $option;
            }
        }

        return $options;
    }

    /**
     * この店で使っている、札の口を持つ App（要求ごとに 1 度だけ決める）
     *
     * @return list<array{0: string, 1: CardOptionProvider}>
     */
    private function providers(): array
    {
        $request = request();
        self::$providers ??= new WeakMap;

        if (isset(self::$providers[$request])) {
            return self::$providers[$request];
        }

        $found = [];

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->purchaseOptions instanceof CardOptionProvider && $this->states->enabled($plugin->name)) {
                $found[] = [$plugin->name, $plugin->purchaseOptions];
            }
        }

        return self::$providers[$request] = $found;
    }
}
