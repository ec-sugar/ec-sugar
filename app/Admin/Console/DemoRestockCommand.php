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
 * デモの店の売れる数（在庫・予約の枠・予約販売の数・席）を作った時の数へ戻す（ADR 0086）。1 時間ごとの定期の処理（routes/console.php）と、手で流すとき。
 *
 *   php artisan demo:restock                    デモの印のある店を全部
 *   php artisan demo:restock --tenant=demosake 1 つだけ
 *
 * デモの店は誰でも払わずに注文でき、払う前の注文が在庫を押さえる。売り切れのまま翌朝の戻し（demo:reset）まで残さないために、押さえた分だけ数を足す。
 * 注文はそのまま（取り消さない）。デモの印の無い店・スクリプトを流し終えていない店には触れない（--tenant で名指ししても断る）。
 * 同じ店の戻し・入れの最中なら、その店は飛ばす（次の回に）。
 *
 * 終わりの番号: 0 戻した（直す物・店が無かった） / 1 1 つでも戻せなかった・断った
 */
final class DemoRestockCommand extends Command
{
    protected $signature = 'demo:restock
        {--tenant=* : 戻す店の ID（無ければデモの印のある店を全部）}';

    protected $description = 'デモの店の在庫・予約の枠・予約販売の数・席を作った時の数へ戻す';

    public function handle(DemoShops $shops): int
    {
        /** @var list<string> $ids */
        $ids = array_values(array_filter((array) $this->option('tenant'), 'is_string'));
        $tenants = [];

        foreach (($ids === [] ? Tenant::query()->where('id', 'like', DemoShop::ID_PREFIX.'%') : Tenant::query()->whereIn('id', $ids))->orderBy('id')->get() as $tenant) {
            // 名指しの無いときは印のある店だけ。名指しした店は DemoShops::restock が印を確かめて断る
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
                $changed = $shops->restock($tenant);
                $this->line($changed === null ? "店 {$id} は戻し・入れの最中なので飛ばしました" : "店 {$id}: {$changed} 件を作った時の数へ戻しました");
            } catch (DemoRefused $e) {
                $this->error($e->getMessage());
                $failed = true;
            } catch (Throwable $e) {
                report($e);
                $this->error("店 {$id} の売れる数を戻せませんでした: {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
