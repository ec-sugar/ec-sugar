<?php

declare(strict_types=1);

namespace App\Core\Shop\Services;

use App\Core\Event\EventStore;
use App\Core\Shop\Models\ShopSettings;
use App\Core\Shop\ShopStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * ショップの公開の状態とパスワードを読む・変える。変えるのはここだけ。
 * 書き込みは EventStore::lock() → 設定の行のロックの順（ADR 0012）。パスワードの値（平文もハッシュも）はイベントに入れない。
 * 設定の行が無い・読めない状態は準備中として扱う（閉じる側に倒す）
 */
final class StorefrontAccessService
{
    public const int PASSWORD_MIN = 8;

    public const int PASSWORD_MAX = 64;

    /** bcrypt が読むのは先頭の 72 バイトだけ。それより長いと後ろが無視されるので断る */
    private const int PASSWORD_MAX_BYTES = 72;

    public function __construct(private readonly EventStore $events) {}

    /**
     * 今の状態と、入れた人の Cookie と照らす値（パスワードの世代と設定の行の ID）。ショップの画面のたびに 1 回だけ読む。
     * 行の ID は店ごとにばらばらな値で、作り直した店（同じ店の ID でも）・マイグレーションを戻した店では変わる（世代が同じでも前の Cookie が効かない）。
     * 行が無ければ key は null（Cookie はどれも効かない）
     *
     * @return array{status: ShopStatus, generation: int, key: string|null, has_password: bool}
     */
    public function current(): array
    {
        $row = ShopSettings::query()->first(['id', 'storefront_status', 'storefront_password_generation', 'storefront_password_hash']);
        $status = ShopStatus::tryFrom((string) ($row?->getAttribute('storefront_status') ?? '')) ?? ShopStatus::Preparing;

        return [
            'status' => $status,
            'generation' => (int) ($row?->getAttribute('storefront_password_generation') ?? 0),
            'key' => $row?->id,
            'has_password' => is_string($row?->getAttribute('storefront_password_hash')),
        ];
    }

    public function status(): ShopStatus
    {
        return $this->current()['status'];
    }

    /**
     * 入れたパスワードが合えば、Cookie に入れる値（今の世代と設定の行の ID）を返す。合わない・パスワードが無いなら null
     *
     * @return array{generation: int, key: string}|null
     */
    public function verify(string $password): ?array
    {
        $row = ShopSettings::query()->first(['id', 'storefront_password_hash', 'storefront_password_generation']);
        $hash = $row?->getAttribute('storefront_password_hash');

        // 置けるパスワードは 72 バイトまで。長い入力は、先頭の 72 バイトだけで合ってしまわないように比べずに断る
        if (! is_string($hash) || strlen($password) > self::PASSWORD_MAX_BYTES) {
            return null;
        }

        return Hash::check($password, $hash) ? ['generation' => (int) $row->getAttribute('storefront_password_generation'), 'key' => $row->id] : null;
    }

    public function setStatus(ShopStatus $status, ?string $actor = null): void
    {
        DB::transaction(function () use ($status, $actor): void {
            $this->events->lock();
            $settings = $this->lockedRow();
            $from = ShopStatus::tryFrom((string) $settings->getAttribute('storefront_status')) ?? ShopStatus::Preparing;

            if ($from === $status) {
                return;
            }

            $settings->setAttribute('storefront_status', $status->value);
            $settings->save();
            $this->events->append('shop.storefront_status_changed', 'shop_settings', $settings->id, ['from' => $from->value, 'to' => $status->value], $actor);
        });
    }

    /**
     * パスワードを置く（null は外す。準備中のショップには誰も入れなくなる）。変えるたびに世代を進め、前に入れた人の Cookie をすべて無効にする。
     * 形が違えば ValidationException。値は記録に入れず、置いたか外したかだけ
     */
    public function setPassword(?string $password, ?string $actor = null): void
    {
        if ($password !== null) {
            Validator::make(['password' => $password], [
                'password' => ['string', 'min:'.self::PASSWORD_MIN, 'max:'.self::PASSWORD_MAX, 'not_regex:/[\x00-\x1F\x7F]/'],
            ], attributes: ['password' => 'パスワード'])->validate();

            if (strlen($password) > self::PASSWORD_MAX_BYTES) {
                throw ValidationException::withMessages(['password' => 'パスワードが長すぎます。英数字なら 64 文字まで、日本語の文字はもっと短くしてください。']);
            }
        }

        $hash = $password === null ? null : Hash::make($password);

        DB::transaction(function () use ($hash, $actor): void {
            $this->events->lock();
            $settings = $this->lockedRow();

            if ($hash === null && $settings->getAttribute('storefront_password_hash') === null) {
                return;
            }

            $settings->setAttribute('storefront_password_hash', $hash);
            $settings->setAttribute('storefront_password_generation', (int) $settings->getAttribute('storefront_password_generation') + 1);
            $settings->save();
            $this->events->append('shop.storefront_password_changed', 'shop_settings', $settings->id, ['set' => $hash !== null], $actor);
        });
    }

    /**
     * 設定の行をロックして読む。無ければ作る（列の既定の準備中・世代 0 を読み直す）
     */
    private function lockedRow(): ShopSettings
    {
        return ShopSettings::query()->lockForUpdate()->first() ?? ShopSettings::create([])->refresh();
    }
}
