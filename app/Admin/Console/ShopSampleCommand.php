<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Services\AuditLog;
use App\Core\Sample\SampleSets;
use App\Core\Sample\Services\SamplesRefused;
use App\Core\Sample\Services\ShopSamples;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Storefront\Themes\ThemeSelector;
use Illuminate\Console\Command;
use Throwable;

/**
 * 入れたときのサンプルを店に入れる・まとめて消す。インストーラーが最初の店を作った後に呼ぶ。
 *
 *   php artisan shop:sample shop                    雑貨のお店（既定。写真は配る物の中の絵）
 *   php artisan shop:sample shop --set=sweets       お菓子のお店（写真はその場で Unsplash から取る）
 *   php artisan shop:sample shop --set=fashion --photos=/path/to/photos   写真を取りに行かず、手元のフォルダから（名前で合わせる）
 *   php artisan shop:sample shop --remove           まとめて消す（管理画面のホームの「サンプルをまとめて消す」と同じ）
 *
 * 本番でも動く（デモの店のシーダーと違い、本番のインストールで使う）。流し直しても増えない（入れた物には印が付く）。
 * お店の商品がもうある店には、--force が無いと入れない（動いているショップに見本を並べない）。
 * 別のセットが入っている店には入れない（--force でも。先に --remove で消してから入れる）。
 * 入れたら、店のテーマをセットのテーマに切り替える（中央の監査ログに残る）。消してもテーマは戻さない。
 * 写真が取れなかったときは、その写真なしで入れて注意を出す（終わりの番号は 0）。お客さんと注文は入れない。
 *
 * 終わりの番号: 0 入れた・もう入っていた・消した / 1 断った・入れ終えられなかった（店が無い・お店の商品がある・別のセット・途中の失敗）/ 2 引数の誤り
 */
final class ShopSampleCommand extends Command
{
    protected $signature = 'shop:sample
        {id : 店の ID}
        {--set= : サンプルのセット（zakka・sweets・fashion・botanica。既定は zakka）}
        {--force : お店の商品がもうあっても入れる}
        {--photos= : 写真を取りに行かず、このフォルダの写真を使う（<名前>.jpg などを名前で合わせる）}
        {--remove : 入れたサンプルをまとめて消す}';

    protected $description = '店に入れたときのサンプル（雑貨・お菓子・布もの・植物のお店から 1 つ）を入れる・まとめて消す';

    public function handle(ShopSamples $samples, ThemeRegistry $themes, ThemeSelector $selector, AuditLog $audit): int
    {
        $id = (string) $this->argument('id');

        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,30}[a-z0-9]\z/', $id) !== 1) {
            $this->error('店の ID は英小文字・数字・ハイフンの 2〜32 文字です');

            return self::INVALID;
        }

        $setOption = $this->option('set');
        $photosOption = $this->option('photos');

        if ($this->option('remove') && ($setOption !== null || $photosOption !== null || $this->option('force'))) {
            $this->error('--remove は --set・--photos・--force と一緒には使えません');

            return self::INVALID;
        }

        $set = is_string($setOption) ? $setOption : SampleSets::DEFAULT;

        if (! SampleSets::has($set)) {
            $this->error("サンプルのセットがありません: {$set}（".implode('・', SampleSets::names()).'）');

            return self::INVALID;
        }

        // 店の文脈に入ると storage_path() などが店ごとの場所に変わるので、写真のフォルダは先に決める
        $photos = null;

        if (is_string($photosOption)) {
            $photos = realpath($photosOption);

            if ($photos === false || ! is_dir($photos)) {
                $this->error("写真のフォルダがありません: {$photosOption}");

                return self::INVALID;
            }
        }

        $tenant = Tenant::query()->find($id);

        if (! $tenant instanceof Tenant) {
            $this->error("店 {$id} がありません（先に shop:create で作る）");

            return self::FAILURE;
        }

        return $this->option('remove')
            ? $this->remove($tenant, $samples, $audit)
            : $this->install($tenant, $set, $photos, $samples, $themes, $selector);
    }

    private function install(Tenant $tenant, string $set, ?string $photos, ShopSamples $samples, ThemeRegistry $themes, ThemeSelector $selector): int
    {
        $id = (string) $tenant->getTenantKey();
        $sample = SampleSets::get($set);

        if (! $themes->has($sample->theme())) {
            $this->error("サンプル「{$sample->label()}」のテーマ {$sample->theme()} が入っていません");

            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            $result = $samples->install($set, $photos, (bool) $this->option('force'), fn (string $message, bool $warning) => $warning ? $this->warn($message) : $this->line($message));
        } catch (SamplesRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error("サンプルを入れ終えられませんでした: {$e->getMessage()}");
            $this->line('入れ終えた物はそのまま残ります。原因を解消して再実行すると、残りだけを入れます');

            return self::FAILURE;
        } finally {
            tenancy()->end();
        }

        if ($result['added'] === 0) {
            $this->info("店 {$id} のサンプルはもう入っています（何も変えていません）");

            return self::SUCCESS;
        }

        $this->info("店 {$id} にサンプルを入れました（{$sample->label()}、{$result['added']} 件）。管理画面のホームの「サンプルをまとめて消す」で消せます");

        if ($result['missing'] !== []) {
            $this->warn('写真 '.count($result['missing']).' 枚を入れられませんでした（その写真なしで入れました）。もう一度入れるときは、先に --remove で消してから再実行します');
        }

        if (! $sample->bundled()) {
            $this->line('写真は Unsplash の写真です（撮った人は商品の説明の終わりに書いてあります。https://unsplash.com/license）');
        }

        // 店のテーマをセットのテーマへ（もうそのテーマなら何もしない）
        if ($themes->forTenant($tenant)->name !== $sample->theme()) {
            try {
                $selector->change($tenant, $sample->theme(), ShopSamples::ACTOR);
            } catch (Throwable $e) {
                report($e);
                $this->error("ショップのテーマを {$sample->theme()} に切り替えられませんでした: {$e->getMessage()}");
                $this->line("サンプルは入っています。php artisan storefront:theme {$id} {$sample->theme()} で切り替えます");

                return self::FAILURE;
            }

            $this->info("ショップのテーマを「{$themes->get($sample->theme())->label}」に切り替えました");
        }

        return self::SUCCESS;
    }

    private function remove(Tenant $tenant, ShopSamples $samples, AuditLog $audit): int
    {
        $id = (string) $tenant->getTenantKey();
        tenancy()->initialize($tenant);

        try {
            $result = $samples->remove(ShopSamples::ACTOR);
        } catch (Throwable $e) {
            report($e);
            $this->error("サンプルを消せませんでした（何も消していません）: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            tenancy()->end();
        }

        if ($result['removed'] === 0 && $result['kept'] === []) {
            $this->info("店 {$id} に消すサンプルはありません");

            return self::SUCCESS;
        }

        // 管理画面から消したときと同じく、中央の監査ログに数だけ残す（運営はいない。CLI から）
        $audit->record('tenant.samples_removed', null, ['removed' => $result['removed'], 'kept' => count($result['kept']), 'by' => ShopSamples::ACTOR], $id);
        $this->info("店 {$id} のサンプルをまとめて消しました（{$result['removed']} 件）。ショップのテーマはそのままです");

        foreach ($result['kept'] as $kept) {
            $reason = match ($kept['reason']) {
                'ordered' => '注文に使われた',
                'edited' => 'お店が書き換えた',
                default => 'お店が使っている',
            };
            $this->line('  残した物: '.($kept['title'] ?? $kept['type'])."（{$reason}ので、お店の物にしました）");
        }

        return self::SUCCESS;
    }
}
