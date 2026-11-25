<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Event\EventStore;
use App\Core\Plugin\Contracts\PluginDataPurger;
use App\Core\Plugin\Models\PluginSetting;
use App\Core\Plugin\Models\PluginState;
use App\Core\Plugin\PluginNotInstalled;
use App\Core\Plugin\PluginPurgeRefused;
use App\Core\Plugin\PluginRefusedInDemo;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\PurgedData;
use App\Core\Sample\Demo\DemoShop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * 店で App のデータを消す（ADR 0092）。店の文脈の中だけで使う。断る順: 入っていない（404）→ 消す口が無い → デモの店 → 使っている → App が断る。
 *
 * - 連鎖のロック → 状態の行のロックの中で「止めた」を確かめてから、App の口（purgeRows）で App の表の行を消し、核が持つ App の設定（秘密も）を消し、
 *   `plugin.purged` を同じトランザクションで残す。記録の中身は App の名前・表ごとの消した数・設定を消したかだけ（個人情報も文も入れない）
 * - 使う・使わないの切り替え（PluginStates::set）も同じ連鎖のロックを先に取るので、消している間に「使う」にはならない
 * - ファイルはコミットの後に App の口（purgeFiles）で消す。落ちたら報告して、結果に「残った」と書く（行はもう無いので、もう一度消すと拾う）
 * - 消すのは App の表の行だけ。表・状態の行（止めたまま）・記録（追記だけの鎖）は残す。一度も使っていない店では、止めた状態の行を作ってから記録する
 */
final class PluginDataPurge
{
    public function __construct(
        private readonly EventStore $events,
        private readonly PluginRegistry $registry,
    ) {}

    /**
     * この App にデータを消す口があるか（入っていない App は偽）
     */
    public function purgeable(string $plugin): bool
    {
        return $this->registry->has($plugin) && $this->registry->get($plugin)->purger !== null;
    }

    public function purge(string $plugin, string $actor): PurgedData
    {
        if (! tenancy()->initialized) {
            throw new LogicException('App のデータは店の文脈の中でだけ消す');
        }

        if (! $this->registry->has($plugin)) {
            throw new PluginNotInstalled;
        }

        $purger = $this->registry->get($plugin)->purger;

        if (! $purger instanceof PluginDataPurger) {
            throw PluginPurgeRefused::noPurger();
        }

        // デモの店は毎晩作り直す見本の店なので、来訪者にデータを消させない（ADR 0086）
        if (DemoShop::active()) {
            throw new PluginRefusedInDemo('デモのお店では、App のデータは削除できません。');
        }

        /** @var array{rows: array<string, int>, settings: bool} $done */
        $done = DB::transaction(function () use ($plugin, $purger, $actor): array {
            $this->events->lock();
            $state = PluginState::query()->lockForUpdate()->where('plugin', $plugin)->first();

            if ($state instanceof PluginState && $state->enabled) {
                throw PluginPurgeRefused::inUse();
            }

            $refusal = $purger->refusal();

            if ($refusal !== null) {
                throw new PluginPurgeRefused($refusal);
            }

            $rows = self::checkedRows($plugin, $purger->purgeRows());
            $settings = PluginSetting::query()->where('plugin', $plugin)->delete() > 0;
            $state ??= PluginState::create(['plugin' => $plugin, 'enabled' => false, 'changed_at' => CarbonImmutable::now()]);
            $this->events->append('plugin.purged', 'plugin_state', $state->id, ['plugin' => $plugin, 'rows' => $rows, 'settings' => $settings], $actor);

            return ['rows' => $rows, 'settings' => $settings];
        });

        // ファイルはコミットの後に（先に消してトランザクションが落ちると、行が残ってファイルだけ無くなる）
        $files = 0;
        $leftover = false;

        try {
            $files = $purger->purgeFiles();
        } catch (Throwable $error) {
            report($error);
            $leftover = true;
        }

        return new PurgedData($done['rows'], $done['settings'], $files, $leftover);
    }

    /**
     * App が返した消した数を確かめる（記録に入るので、形の違う名前・App の頭で始まらない表・負の数は受けない）
     *
     * @param  array<mixed>  $rows
     * @return array<string, int>
     */
    private static function checkedRows(string $plugin, array $rows): array
    {
        $prefix = str_replace('-', '_', $plugin);
        $checked = [];

        foreach ($rows as $table => $count) {
            if (! is_string($table) || preg_match('/\A'.preg_quote($prefix, '/').'(_[a-z0-9_]+)?\z/', $table) !== 1 || strlen($table) > 63 || ! is_int($count) || $count < 0) {
                throw new LogicException("App {$plugin} の消した数の形が違います（表の名前は App の頭で始め、数は 0 以上の整数）");
            }

            $checked[$table] = $count;
        }

        ksort($checked);

        return $checked;
    }
}
