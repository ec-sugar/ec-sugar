<?php

declare(strict_types=1);

namespace App\Admin\Demo;

use App\Admin\Demo\Shops\CoffeeDemo;
use App\Admin\Demo\Shops\LivehouseDemo;
use App\Admin\Demo\Shops\SakeDemo;
use App\Admin\Demo\Shops\WagashiDemo;
use App\Admin\Services\AuditLog;
use App\Admin\Services\Incidents;
use App\Admin\Services\ShopDigests;
use App\Admin\Services\ShopNames;
use App\Core\Media\DeleteTenantMedia;
use App\Core\Payment\Models\PaymentGateway;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Sample\Demo\DemoShop;
use App\Core\Sample\Models\DemoStep;
use App\Core\Sample\SamplePhotos;
use App\Models\Tenant;
use App\Storefront\Themes\ThemeSelector;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;
use Throwable;

/**
 * デモの店を作る・毎晩戻す（ADR 0086）。どちらも CLI と定期の処理からだけ（管理画面の経路は無い）。
 *
 * 守り（デモでない店には絶対に触れない）:
 * - 作るのも戻すのも、印のある店（DemoShop::is。中央の印 ＋ ID が demo で始まる）だけ
 * - 戻すのは、さらに店の DB にスクリプトを流し終えた印（demo_steps の built:…）がある店だけ（印を付けただけの店の DB は消さない）
 * - 戻す直前に、中央の行を読み直して印をもう一度確かめる
 * 戻し方: 店の DB を消して作り直し（マイグレーションと最初の行）、写真の置き場も消してから、覚えておいた扱い・名前・写真のフォルダで作り直す。
 * Stripe のテストの鍵だけは持ち越す（本物の鍵は持ち越さない）。1 時間ごとに売れる数も作った時の数へ戻す（restock。DemoRestock）
 * 同じ店の作る・戻す・売れる数の戻しは、店ごとの錠で重ねない
 */
final class DemoShops
{
    /** @var array<string, class-string<DemoDefinition>> */
    private const array DEFINITIONS = [
        'sake' => SakeDemo::class,
        'coffee' => CoffeeDemo::class,
        'wagashi' => WagashiDemo::class,
        'livehouse' => LivehouseDemo::class,
    ];

    /** 店ごとの錠の名前の頭と、持つ長さ（写真を取りに行く作り直しでも収まる長さ） */
    private const string LOCK = 'demo-shop:';

    private const int LOCK_SECONDS = 3600;

    /** 戻しの途中の印（中央の店の行の data。持ち越す鍵の控えを、アプリの鍵で暗号化して置く）。店ごとに回る見回りは、印のある店に入らない（Tenant::serving） */
    private const string RESETTING = Tenant::REBUILDING;

    public function __construct(
        private readonly DemoShopBuilder $builder,
        private readonly DemoRestock $restock,
        private readonly SamplePhotos $photos,
        private readonly ThemeSelector $selector,
        private readonly AuditLog $audit,
        private readonly ShopNames $names,
    ) {}

    /**
     * @return list<string>
     */
    public static function kinds(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function definition(string $kind): DemoDefinition
    {
        $class = self::DEFINITIONS[$kind] ?? throw new InvalidArgumentException("デモの店の扱いがありません: {$kind}（".implode('・', self::kinds()).'）');

        return new $class;
    }

    /**
     * 店の覚え（扱い・名前・写真のフォルダ）。無い・形が違えば null
     *
     * @return array{kind: string, name: string, photos: string|null}|null
     */
    public static function buildOf(Tenant $tenant): ?array
    {
        $build = $tenant->getAttribute(DemoShop::BUILD);

        if (! is_array($build) || ! is_string($build['kind'] ?? null) || ! isset(self::DEFINITIONS[$build['kind']]) || ! is_string($build['name'] ?? null)) {
            return null;
        }

        return ['kind' => $build['kind'], 'name' => $build['name'], 'photos' => is_string($build['photos'] ?? null) ? $build['photos'] : null];
    }

    /**
     * デモの店の中身を入れる（足りない物だけ）。印の無い店・別の扱いで作り終えた店は断る。同じ店の戻し・入れが動いている間は断る。
     * 名前（$name）が null なら中央の店の今の名前のまま（shop:create・前の回で付けた名前を上書きしない）。中央の店に名前が無いときだけ扱いの名前の候補のはじめ
     *
     * @param  (Closure(string): void)|null  $say
     * @return array{added: int, missing: array<string, string>, name: string, renamed: bool}
     *
     * @throws DemoRefused
     */
    public function install(Tenant $tenant, string $kind, ?string $name, ?string $photos, ?Closure $say = null): array
    {
        return $this->locked($tenant, fn (): array => $this->installUnlocked($tenant, $kind, $name, $photos, $say));
    }

    /**
     * 作った時の状態に戻す（店の DB と写真を消して作り直す）。
     *
     * - 同じ店の戻し・入れ・売れる数の戻しと重ならない（店ごとの錠。手の demo:reset と毎晩の処理が重なったら、後の方を断る）
     * - 店の DB を消す前に、持ち越す Stripe のテストの鍵を中央の店の行に控える（アプリの鍵で暗号化）。作り直した DB にはまず鍵を戻してから
     *   中身を入れるので、途中で落ちても鍵は失わない
     * - 途中で落ちたら、控え（途中の印）を残したまま止める。次の回は印を見て、店の DB の印（built:…）を確かめずに作り直しから続ける
     *   （印は built:… を確かめてから付けるので、デモでない店の DB を消すことは無い）。終わったら控えを消す
     * - 落ちたことは report() と運営の知らせ（壊れたら知らせる仕組み。ADR 0043）に送る
     *
     * @param  (Closure(string): void)|null  $say
     *
     * @throws DemoRefused デモの店でない・覚えが無い・スクリプトを流し終えていない・ほかの処理の最中
     */
    public function reset(Tenant $tenant, ?Closure $say = null): void
    {
        $this->locked($tenant, function () use ($tenant, $say): void {
            try {
                $this->resetUnlocked($tenant, $say);
            } catch (DemoRefused $e) {
                throw $e;
            } catch (Throwable $e) {
                Incidents::record(Incidents::COMMAND_FAILED, $e, location: 'demo:reset '.$tenant->getTenantKey());

                throw $e;
            }
        });
    }

    /**
     * 売れる数（在庫・予約の枠・予約販売の数・席）を作った時の数へ戻す（1 時間ごと。DemoRestock）。
     * 戻しと同じ守り（中央の印・ID・店の DB の built:…）がそろう店だけ。戻し・入れの最中なら何もしない
     *
     * @return int|null 直した数（ほかの処理の最中で飛ばしたら null）
     *
     * @throws DemoRefused デモの店でない・覚えが無い・スクリプトを流し終えていない
     */
    public function restock(Tenant $tenant): ?int
    {
        $lock = Cache::lock(self::LOCK.$tenant->getTenantKey(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            [$tenant, $build] = $this->guard($tenant);

            if (self::pendingOf($tenant) !== null) {
                throw new DemoRefused("店 {$tenant->getTenantKey()} は戻しが途中で止まっています（先に demo:reset で作り直します）");
            }

            tenancy()->initialize($tenant);

            try {
                if (! DemoStep::built()) {
                    throw new DemoRefused("店 {$tenant->getTenantKey()} はデモのスクリプトを実行し終えていないので戻しません");
                }

                return $this->restock->run(self::definition($build['kind']));
            } finally {
                tenancy()->end();
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  (Closure(string): void)|null  $say
     * @return array{added: int, missing: array<string, string>, name: string, renamed: bool}
     */
    private function installUnlocked(Tenant $tenant, string $kind, ?string $name, ?string $photos, ?Closure $say): array
    {
        if (! DemoShop::is($tenant)) {
            throw new DemoRefused('デモの印の無い店には入れません（先に php artisan shop:demo '.$tenant->getTenantKey().' --on）');
        }

        $def = self::definition($kind);
        $before = self::buildOf($tenant);

        if ($before !== null && $before['kind'] !== $kind && $tenant->run(static fn (): bool => DemoStep::built())) {
            throw new DemoRefused("この店には別のデモ（{$before['kind']}）が入っています。扱いを替えるときは、記録を書き換えてから demo:reset で作り直します");
        }

        // 名前: 渡されなければ中央の店の今の名前のまま。変えるときは店の名前の道（ShopNames。店の記録と監査ログを残す）を通す
        $current = self::nameOf($tenant);
        $name ??= $current ?? $def->names()[0];

        $renamed = $name !== $current && $tenant->run(fn (): bool => $this->names->renameDemo($tenant, $name, DemoShopBuilder::ACTOR));

        // 中央の覚えとテーマ（店の文脈の外で）
        $tenant->setAttribute(DemoShop::BUILD, ['kind' => $kind, 'name' => $name, 'photos' => $photos]);
        $tenant->save();
        $this->selector->change($tenant, $def->theme(), DemoShopBuilder::ACTOR);

        $temporary = null;
        $missing = [];

        if ($photos === null) {
            $say?->__invoke('写真を Unsplash から取っています（数十秒かかります）…');
            $temporary = sys_get_temp_dir().'/ec-sugar-demo-'.bin2hex(random_bytes(8));

            if (! mkdir($temporary, 0700)) {
                throw new RuntimeException('写真の一時フォルダを作れませんでした');
            }

            $photos = $temporary;
            $missing = $this->photos->fetch($def->photos(), $temporary);
        }

        tenancy()->initialize($tenant);

        try {
            return [...$this->builder->build($def, $photos, $missing, $say), 'name' => $name, 'renamed' => $renamed];
        } finally {
            tenancy()->end();

            if ($temporary !== null) {
                foreach (glob("{$temporary}/*") ?: [] as $file) {
                    @unlink($file);
                }

                @rmdir($temporary);
            }
        }
    }

    /**
     * @param  (Closure(string): void)|null  $say
     */
    private function resetUnlocked(Tenant $tenant, ?Closure $say): void
    {
        [$tenant, $build] = $this->guard($tenant);
        $pending = self::pendingOf($tenant);

        if ($pending === null) {
            // 店の DB の側の印（スクリプトを流し終えた店だけ）と、持ち越すテストの鍵
            $kept = $tenant->run(static function (): array {
                if (! DemoStep::built()) {
                    return ['built' => false, 'stripe' => null];
                }

                $credentials = PaymentGateway::query()->where('provider', 'stripe')->where('mode', 'test')->first()?->credentials;

                return ['built' => true, 'stripe' => is_array($credentials) ? $credentials : null];
            });

            if (! $kept['built']) {
                throw new DemoRefused("店 {$tenant->getTenantKey()} はデモのスクリプトを実行し終えていないので戻しません");
            }

            $stripe = self::stripeKeys($kept['stripe']);
            // 消す前に控える（途中で落ちても、次の回がここから続け、鍵も戻せる）
            $tenant->setAttribute(self::RESETTING, ['at' => CarbonImmutable::now()->toIso8601String(), 'stripe' => $stripe === null ? null : Crypt::encryptString((string) json_encode($stripe))]);
            $tenant->save();
        } else {
            $say?->__invoke('前の回の戻しが途中で止まっていたので、作り直しから続けます');
            $stripe = self::decryptStripe($pending['stripe']);
        }

        $say?->__invoke('店の DB と写真を消して作り直しています');
        DB::purge('tenant');
        $database = $tenant->database();
        $manager = $database->manager();

        if ($manager->databaseExists($database->getName())) {
            $manager->deleteDatabase($tenant);
        }

        $manager->createDatabase($tenant);
        // 記録が空から始まり直すので、前に控えた連鎖の先頭を忘れる（忘れないと次の検証が「巻き戻された」と知らせる）
        ShopDigests::forgetChainHead((string) $tenant->getTenantKey());
        (new MigrateDatabase($tenant))->handle();
        (new SeedDatabase($tenant))->handle();
        (new DeleteTenantMedia($tenant))->handle();

        // テストの鍵を先に戻す（中身を入れるのに失敗しても鍵は残る）
        if ($stripe !== null) {
            $tenant->run(static fn () => app(PaymentGatewayService::class)->configureStripe($stripe, DemoShopBuilder::ACTOR));
        }

        $this->installUnlocked($tenant, $build['kind'], $build['name'], $build['photos'] !== null && is_dir($build['photos']) ? $build['photos'] : null, $say);

        $tenant->setAttribute(self::RESETTING, null);
        $tenant->save();
        $this->audit->record('tenant.demo_reset', null, ['by' => DemoShopBuilder::ACTOR, 'kind' => $build['kind']], (string) $tenant->getTenantKey());
    }

    /**
     * 中央の行を読み直して、印をもう一度確かめる（呼ぶ側が持っていた古い行で決めない）
     *
     * @return array{0: Tenant, 1: array{kind: string, name: string, photos: string|null}}
     *
     * @throws DemoRefused
     */
    private function guard(Tenant $tenant): array
    {
        $fresh = Tenant::query()->find($tenant->getTenantKey());

        if (! $fresh instanceof Tenant) {
            throw new DemoRefused('店がありません');
        }

        $build = self::buildOf($fresh);

        if (! DemoShop::is($fresh) || $build === null) {
            throw new DemoRefused("店 {$fresh->getTenantKey()} はデモの店ではないので戻しません");
        }

        return [$fresh, $build];
    }

    /**
     * 店ごとの錠の中で（戻し・入れ・売れる数の戻しを重ねない）。取れなければ断る
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws DemoRefused
     */
    private function locked(Tenant $tenant, Closure $work): mixed
    {
        $lock = Cache::lock(self::LOCK.$tenant->getTenantKey(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new DemoRefused("店 {$tenant->getTenantKey()} はほかのデモの処理（戻し・入れ）の最中です。終わってからもう一度実行してください");
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    /**
     * 中央の店の今の名前（前後の空白を除く。無ければ null）
     */
    private static function nameOf(Tenant $tenant): ?string
    {
        $name = $tenant->getAttribute('name');
        $name = is_string($name) ? preg_replace('/\A\p{Zs}+|\p{Zs}+\z/u', '', $name) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * 前の回の戻しの途中の印（無ければ null）
     *
     * @return array{stripe: string|null}|null
     */
    private static function pendingOf(Tenant $tenant): ?array
    {
        $pending = $tenant->getAttribute(self::RESETTING);

        return is_array($pending) ? ['stripe' => is_string($pending['stripe'] ?? null) ? $pending['stripe'] : null] : null;
    }

    /**
     * 持ち越すテストの鍵（形がそろっていて、テストの鍵のときだけ）
     *
     * @param  array<mixed>|null  $credentials
     * @return array{secret_key: string, webhook_secret: string}|null
     */
    private static function stripeKeys(?array $credentials): ?array
    {
        $secret = $credentials['secret_key'] ?? null;
        $webhook = $credentials['webhook_secret'] ?? null;

        return is_string($secret) && str_starts_with($secret, 'sk_test_') && is_string($webhook) ? ['secret_key' => $secret, 'webhook_secret' => $webhook] : null;
    }

    /**
     * @return array{secret_key: string, webhook_secret: string}|null
     */
    private static function decryptStripe(?string $sealed): ?array
    {
        if ($sealed === null) {
            return null;
        }

        try {
            $keys = json_decode(Crypt::decryptString($sealed), true);
        } catch (Throwable $e) {
            // アプリの鍵が替わった・壊れた: 鍵は戻さずに作り直す（店の人が入れ直す）
            report($e);

            return null;
        }

        return is_array($keys) ? self::stripeKeys($keys) : null;
    }
}
