<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Event\EventStore;
use App\Core\Plugin\Models\PluginState;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginNotInstalled;
use App\Core\Plugin\PluginRefusedInDemo;
use App\Core\Plugin\PluginRegistry;
use App\Core\Sample\Demo\DemoShop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 店のプラグインの使う・使わない（ADR 0025）。店の文脈の中だけで使う。
 * 入っていないプラグイン（外したもの）は、行が残っていても使わない扱い。
 * デモの店（ADR 0086）では、外へ送る App（店への知らせ・LINE・計測のタグ）を使うにできない（管理画面からも CLI からも、ここを通る）
 */
final class PluginStates
{
    public function __construct(
        private readonly EventStore $events,
        private readonly PluginRegistry $registry,
    ) {}

    public function enabled(string $plugin): bool
    {
        return $this->registry->has($plugin) && PluginState::query()->where('plugin', $plugin)->where('enabled', true)->exists();
    }

    /**
     * @return array<string, array{enabled: bool, changed_at: CarbonImmutable|null}> 入っているプラグインの名前ごと
     */
    public function all(): array
    {
        $rows = PluginState::query()->get()->keyBy('plugin');
        $states = [];

        foreach ($this->registry->all() as $plugin) {
            $row = $rows->get($plugin->name);
            $states[$plugin->name] = ['enabled' => $row instanceof PluginState && $row->enabled, 'changed_at' => $row instanceof PluginState ? $row->changed_at : null];
        }

        return $states;
    }

    /**
     * 使う・使わないを変える。同じなら何もしない（記録も残さない）
     */
    public function set(string $plugin, bool $enabled, ?string $actor = null): void
    {
        if (! $this->registry->has($plugin)) {
            throw new PluginNotInstalled;
        }

        // デモの店は誰でも注文できるので、来訪者の操作で外（Slack・LINE・広告と解析のサービス）へ送らせない。止めるのは断らない
        if ($enabled && DemoShop::active() && self::sendsOut($this->registry->get($plugin))) {
            throw new PluginRefusedInDemo;
        }

        DB::transaction(function () use ($plugin, $enabled, $actor): void {
            $this->events->lock();
            $row = PluginState::query()->lockForUpdate()->where('plugin', $plugin)->first();

            if (($row instanceof PluginState && $row->enabled) === $enabled) {
                return;
            }

            $row ??= new PluginState(['plugin' => $plugin]);
            $row->fill(['enabled' => $enabled, 'changed_at' => CarbonImmutable::now()])->save();
            $this->events->append($enabled ? 'plugin.enabled' : 'plugin.disabled', 'plugin_state', $row->id, ['plugin' => $plugin], $actor);
        });
    }

    /**
     * 店の外へ送る App か（デモの店で使わせない物。ADR 0086）: 店への知らせ（Slack・店のメール）・LINE・ショップの画面にスクリプトを差し込むタグの App（ADR 0034）
     */
    public static function sendsOut(PluginDefinition $plugin): bool
    {
        return in_array($plugin->name, ['shop-notify', 'line'], true) || $plugin->app()->scripts !== [];
    }
}
