<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Demo\DemoShopBuilder;
use App\Admin\Services\AuditLog;
use App\Core\Listing\Models\Listing;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Payment\Models\PaymentGateway;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Sample\Demo\DemoShop;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * デモの店の印を付ける・外す・見る（ADR 0086）。印を変えられるのはこの CLI だけ（管理画面の経路は印に触れない）。
 *
 *   php artisan shop:demo demosake --on     印を付ける（ID が demo で始まる店だけ。本物の鍵・Stripe Connect・取引・お客さんの台帳・台本の外の商品・外へ送る App のある店は断る）
 *   php artisan shop:demo demosake --off    印を外す（毎晩の戻しも止まる。帯・メールを送らない守りも外れる）
 *   php artisan shop:demo demosake          今の印を見る
 *
 * 付け外しは中央の監査ログに残す。終わりの番号: 0 済んだ / 1 断った（店が無い・ID の形・本物の鍵） / 2 引数の誤り
 */
final class ShopDemoCommand extends Command
{
    protected $signature = 'shop:demo
        {id : 店の ID（デモの印を付けられるのは demo で始まる店だけ）}
        {--on : デモの印を付ける}
        {--off : デモの印を外す}';

    protected $description = 'デモの店の印を付ける・外す（印のある店はお金とメールを動かさず、毎晩作った時の状態に戻る）';

    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {
        parent::__construct();
    }

    public function handle(AuditLog $audit): int
    {
        $id = (string) $this->argument('id');
        $on = (bool) $this->option('on');
        $off = (bool) $this->option('off');

        if ($on && $off) {
            $this->error('--on と --off は一緒に使えません');

            return self::INVALID;
        }

        $tenant = Tenant::query()->find($id);

        if (! $tenant instanceof Tenant) {
            $this->error("店 {$id} がありません");

            return self::FAILURE;
        }

        if (! $on && ! $off) {
            $this->line(DemoShop::is($tenant) ? "店 {$id} はデモの店です" : "店 {$id} はデモの店ではありません");

            return self::SUCCESS;
        }

        if ($on) {
            if (! DemoShop::eligibleId($id)) {
                $this->error('デモの印を付けられるのは、ID が '.DemoShop::ID_PREFIX.' で始まる店だけです（毎晩の戻しで店の記録を消すので、動いている店に付けないため）');

                return self::FAILURE;
            }

            // もう印のある店はそのまま（来訪者の注文があっても断らない）。新しく付ける時だけ確かめる
            $refusal = $tenant->getAttribute(DemoShop::FLAG) === true ? null : $this->refusal($tenant);

            if ($refusal !== null) {
                $this->error($refusal);

                return self::FAILURE;
            }
        }

        if (($tenant->getAttribute(DemoShop::FLAG) === true) === $on) {
            $this->info($on ? "店 {$id} はもうデモの店です（何も変えていません）" : "店 {$id} はデモの店ではありません（何も変えていません）");

            return self::SUCCESS;
        }

        $tenant->setAttribute(DemoShop::FLAG, $on);
        $tenant->save();
        $audit->record($on ? 'tenant.demo_on' : 'tenant.demo_off', null, ['by' => 'cli:'.(get_current_user() ?: 'unknown')], $id);

        $this->info($on
            ? "店 {$id} をデモの店にしました（バナーと noindex・テスト用の鍵だけ・メールを送らない・毎晩の戻し）"
            : "店 {$id} のデモの印を外しました（毎晩の戻しも止まります）");

        return self::SUCCESS;
    }

    /**
     * デモにできない店なら理由（本物の鍵・Stripe Connect がある店。デモの店は誰でも注文できるので、お金の動く口を残さない。
     * 取引・お客さんの台帳・台本の外の商品がある店〔動いている店かもしれない〕と、外へ送る App を使っている店も）
     */
    private function refusal(Tenant $tenant): ?string
    {
        tenancy()->initialize($tenant);

        try {
            if (PaymentGateway::query()->where('provider', 'stripe_connect')->exists()) {
                return 'Stripe と接続した店はデモにできません（先に管理画面で接続を解除してください）';
            }

            if (PaymentGateway::query()->where('provider', 'stripe')->where('mode', 'live')->exists()) {
                return '本物の Stripe の鍵（live）が入っている店はデモにできません（先に鍵を削除するか、テスト用の鍵に替えてください）';
            }

            // 動いている店の記録を毎晩の戻しで消さない: 取引・お客さんの台帳・デモの台本の外で作った商品がある店は断る
            // （印を外した後に付け直す時も同じ。外していた間の記録は本物かもしれないので、店を作り直してから付ける）
            if (Transaction::query()->exists()) {
                return '取引（注文）のある店はデモにできません（毎晩の戻しで消えるため。新しい店を作ってから印を付けてください）';
            }

            if (CustomerAccount::query()->exists()) {
                return 'お客さんのデータがある店はデモにできません（毎晩の戻しで消えるため。新しい店を作ってから印を付けてください）';
            }

            $foreign = Listing::query()->whereNotExists(static function ($q): void {
                $q->selectRaw('1')->from('domain_events')->where('domain_events.type', 'listing.created')
                    ->whereColumn('domain_events.aggregate_id', 'listings.id')->where('domain_events.actor', DemoShopBuilder::ACTOR);
            })->exists();

            if ($foreign) {
                return 'デモの台本（demo:shop）の外で作った商品がある店はデモにできません（毎晩の戻しで消えるため。新しい店を作ってから印を付けてください）';
            }

            foreach ($this->plugins->all() as $plugin) {
                if (PluginStates::sendsOut($plugin) && $this->states->enabled($plugin->name)) {
                    return "外へ送る App（{$plugin->title}）を使っている店はデモにできません（先に App を止めてください）";
                }
            }

            return null;
        } finally {
            tenancy()->end();
        }
    }
}
