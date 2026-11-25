<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use LogicException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * 中央 DB のテナント。運営主体（契約・課金・ドメイン・設定）を表す。
 *
 * テナントごとに専用 DB と専用 DB ロールを持つ（docs/CONCEPT.md 7.1、ADR 0003 / 0009）。
 * data 列（JSON）に次を保存し、いずれも encrypted キャスト（APP_KEY）で暗号化する:
 *   tenancy_db_username / tenancy_db_password … テナント DB のロール
 *   data_key … テナント DB 内の個人情報列を暗号化する鍵（32 バイト、base64）。ADR 0010
 *
 * 運用モード（4 章）は 3 つの設定の組み合わせで決まる:
 *   storefront_scope: own | shared
 *   seller_model:     single | multi
 *   settlement:       direct | platform
 */
final class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    /**
     * 店の DB を作り直している間の印（data の中。中身は作り直しの控え）。立てるのは DB を消す前、消すのは作り直しが終わった後。
     * 今はデモの店の毎晩の戻し（ADR 0086。名前はその時のまま）だけが立てる
     */
    public const string REBUILDING = 'demo_resetting';

    /**
     * 店の DB を作り直している最中か（この行を読んだ時の印）
     */
    public function isRebuilding(): bool
    {
        return is_array($this->getAttribute(self::REBUILDING));
    }

    /**
     * 中央の行を読み直して、作り直し中か（店ごとに回る見回りが、店に入る直前に。読み始めた後に作り直しを始めた店も入らない）。
     * 作り直し中ならログに 1 行。行が消えていれば真（入らない）
     */
    public function rebuildingNow(): bool
    {
        $fresh = self::query()->find($this->getTenantKey());

        if ($fresh instanceof self && $fresh->isRebuilding()) {
            Log::info('店の DB を作り直している最中なので、見回りはこの店を飛ばしました', ['tenant' => (string) $this->getTenantKey()]);
        }

        return ! $fresh instanceof self || $fresh->isRebuilding();
    }

    /**
     * 店ごとに回る見回りが入ってよい店（作り直し中の店を、入る直前に確かめて除く。ADR 0089 の作って決めたこと）。
     * DB が無い・表が無い店に入って落ち、定期の処理の失敗として運営に知らせることを防ぐ
     *
     * @param  Builder<self>|null  $query  絞った店（無ければ全部を ID の順）
     * @return LazyCollection<int, self>
     */
    public static function serving(?Builder $query = null): LazyCollection
    {
        return ($query ?? self::query()->orderBy('id'))->cursor()->reject(static fn (self $tenant): bool => $tenant->rebuildingNow());
    }

    /**
     * 今のテナント（テナントの文脈の中だけ。外で呼ぶのはコードの誤りなので例外）
     */
    public static function current(): self
    {
        $tenant = tenant();

        if (! $tenant instanceof self) {
            throw new LogicException('テナントの文脈の外で、今の店を取り出そうとしました');
        }

        return $tenant;
    }

    protected static function booted(): void
    {
        // 作成時にデータ鍵を発行する。後から鍵を失うと暗号化した列が二度と読めないので、削除・上書きはしない
        self::creating(static function (self $tenant): void {
            if (! is_string($tenant->getAttribute('data_key'))) {
                $tenant->setAttribute('data_key', base64_encode(random_bytes(32)));
            }
        });
    }

    /**
     * data(JSON) ではなく実カラムとして持つ属性。
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'storefront_scope',
            'seller_model',
            'settlement',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenancy_db_password' => 'encrypted',
            'data_key' => 'encrypted',
        ];
    }
}
