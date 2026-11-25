<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Demo\DemoRefused;
use App\Admin\Demo\DemoShops;
use App\Core\Sample\Demo\DemoShop;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * デモの店を作った時の状態に戻す（ADR 0086）。毎晩の定期の処理（routes/console.php）と、手で流すとき。
 *
 *   php artisan demo:reset                    デモの印のある店を全部
 *   php artisan demo:reset --tenant=demosake 1 つだけ
 *
 * 来訪者が作った注文・会員・申し込み・レビュー・止めたメールなどを、店の DB と写真ごと消して、覚えておいた扱い・名前・写真で作り直す。
 * デモの印の無い店・スクリプトを流し終えていない店には触れない（--tenant で名指ししても断る）。
 *
 * 終わりの番号: 0 戻した（戻す店が無かった） / 1 1 つでも戻せなかった・断った
 */
final class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset
        {--tenant=* : 戻す店の ID（無ければデモの印のある店を全部）}';

    protected $description = 'デモの店を作った時の状態に戻す（来訪者の注文・会員・申し込みを消す）';

    public function handle(DemoShops $shops): int
    {
        /** @var list<string> $ids */
        $ids = array_values(array_filter((array) $this->option('tenant'), 'is_string'));
        $tenants = [];

        foreach (($ids === [] ? Tenant::query()->where('id', 'like', DemoShop::ID_PREFIX.'%') : Tenant::query()->whereIn('id', $ids))->orderBy('id')->get() as $tenant) {
            // 名指しの無いときは印のある店だけ。名指しした店は戻す側（DemoShops::reset）が印を確かめて断る
            if ($tenant instanceof Tenant && ($ids !== [] || DemoShop::is($tenant))) {
                $tenants[] = $tenant;
            }
        }

        if ($ids !== [] && count($tenants) !== count(array_unique($ids))) {
            $this->error('ない店の ID があります');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($tenants as $tenant) {
            $id = (string) $tenant->getTenantKey();

            try {
                $shops->reset($tenant, fn (string $message) => $this->line("{$id}: {$message}"));
                $this->info("店 {$id} を作った時の状態に戻しました");
            } catch (DemoRefused $e) {
                $this->error($e->getMessage());
                $failed = true;
            } catch (Throwable $e) {
                report($e);
                $this->error("店 {$id} を戻せませんでした: {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
