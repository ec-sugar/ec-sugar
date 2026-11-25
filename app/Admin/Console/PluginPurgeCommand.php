<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Services\AuditLog;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginDataPurge;
use App\Core\UserFacingException;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * 店で止めた App のデータを消す（ADR 0092）。管理画面の App の画面の「この App のデータを消す」と同じ（断る決まりも同じ。PluginDataPurge）。
 *
 *   php artisan plugin:purge shop faq            確かめてから消す
 *   php artisan plugin:purge shop faq --force    確かめずに消す（スクリプトから）
 *
 * composer から App を外す前に流すと、表の行が残らない（外した後はコードが無いので消せない）。消したことは中央の監査ログに残す。
 * 終わりの番号: 0 消した / 1 断った・やめた・途中の失敗（店が無い・使っている・口が無い・デモの店・App が断った）/ 2 引数の誤り
 */
final class PluginPurgeCommand extends Command
{
    protected $signature = 'plugin:purge
        {id : 店の ID}
        {plugin : App の名前}
        {--force : 確認せずに消す}';

    protected $description = '店で止めた App のデータ（App の表の行・ファイル・設定）を消す。元に戻せない';

    public const string ACTOR = 'cli:plugin-purge';

    public function handle(PluginDataPurge $purge, PluginRegistry $registry, AuditLog $audit): int
    {
        $id = (string) $this->argument('id');
        $plugin = (string) $this->argument('plugin');

        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,62}\z/', $id) !== 1 || preg_match('/\A[a-z][a-z0-9-]{1,31}\z/', $plugin) !== 1) {
            $this->error('店の ID か App の名前の形が違います');

            return self::INVALID;
        }

        $tenant = Tenant::query()->find($id);

        if (! $tenant instanceof Tenant) {
            $this->error("店 {$id} がありません");

            return self::FAILURE;
        }

        $title = $registry->has($plugin) ? $registry->get($plugin)->title : $plugin;

        if (! $this->option('force') && ! $this->confirm("店 {$id} の App「{$title}」のデータを削除します。元に戻せません。続けますか", false)) {
            $this->line('やめました（何も変えていません）');

            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            $result = $purge->purge($plugin, self::ACTOR);
        } catch (UserFacingException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error("消し終えられませんでした: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            tenancy()->end();
        }

        $audit->record('tenant.plugin_purged', null, ['plugin' => $plugin, 'rows' => $result->total(), 'files' => $result->files, 'via' => 'cli'], $id);
        $this->info("店 {$id} の App「{$title}」のデータを削除しました（行 {$result->total()}・ファイル {$result->files}".($result->settings ? '・設定' : '').'）');

        if ($result->leftover) {
            $this->warn('消し終えられなかったファイルがあります。もう一度実行すると残りを削除します');
        }

        return self::SUCCESS;
    }
}
