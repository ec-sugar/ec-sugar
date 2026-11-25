<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Admin\Models\Operator;
use App\Core\Event\EventStore;
use App\Core\Sample\Demo\DemoShop;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 店の名前を変える。名前の正本は中央の店の行（tenants.name）だけで、店の DB に写しは無い。
 * ショップのヘッダー・メールの差出人の名前と件名・特商法の表記の見出し・管理画面の店の切り替えは、どれも要求のたびに中央の行から読むので、変えた後の次の画面・次のメールから新しい名前になる。
 *
 * 書く順番（中央と店の 2 つの DB をまたぐので、食い違いを小さくする順）:
 *   店の DB のトランザクション → 連鎖のロック（EventStore::lock）→ 中央のトランザクション → 店の行のロック（FOR UPDATE）→ 名前 → 中央の監査ログ
 *   → 店の記録（shop.renamed）→ 中央を確定 → 店を確定
 * 店の記録が書けなければ中央も戻る（名前は変わらない）。中央を確定した後に店の確定だけが落ちたときは、名前と監査ログは残り、店の記録だけが無い
 * （記録の連鎖の番号が進まないので、商品フィードの 1 時間のキャッシュが古い名前のまま残りうる。ほかに困る所は無い）。
 * 記録には名前そのものを入れない（ADR 0010。店の名前は個人の名前のこともあるので、追記だけで消せない記録には残さない。誰がいつ変えたかだけ）
 */
final class ShopNames
{
    /** 名前の文字数の上限（shop:create と同じ） */
    public const int MAX = 100;

    public function __construct(
        private readonly EventStore $events,
        private readonly AuditLog $audit,
    ) {}

    /**
     * 名前の形を確かめて、前後の空白（全角の空白も）を除いた名前を返す。1〜100 文字・制御文字と改行（Unicode の行と段落の区切りも）は断る。
     * 違えば ValidationException（キーは name）
     */
    public static function validated(mixed $name): string
    {
        $clean = is_string($name) ? preg_replace('/\A\p{Zs}+|\p{Zs}+\z/u', '', $name) : null;

        if (! is_string($clean) || $clean === '') {
            throw ValidationException::withMessages(['name' => 'ショップの名前を入力してください。']);
        }

        if (mb_strlen($clean) > self::MAX) {
            throw ValidationException::withMessages(['name' => 'ショップの名前は '.self::MAX.' 文字までにしてください。']);
        }

        if (preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $clean) !== 0) {
            throw ValidationException::withMessages(['name' => 'ショップの名前に改行や制御文字は使えません。']);
        }

        return $clean;
    }

    /**
     * 今の店（店の文脈の中）の名前を変える。同じ名前なら何も書かずに偽。デモの店は断る（毎晩の戻しで作った時の名前に戻る。ADR 0086）。
     * 渡した店の行（Tenant::current()）の名前も新しい値にそろえる（同じ要求の続きの応答に古い名前を出さない）
     */
    public function rename(Tenant $tenant, mixed $name, Operator $operator, string $actor, ?string $ip = null): bool
    {
        $name = self::validated($name);

        if (DemoShop::is($tenant)) {
            throw ValidationException::withMessages(['name' => 'デモの店の名前は変えられません（毎晩、作った時の名前に戻ります）。']);
        }

        return $this->write($tenant, $name, $operator, $actor, $ip);
    }

    /**
     * デモの店の名前を、デモのスクリプト（demo:shop。CLI だけ）が決める。今の店（店の文脈の中）で呼ぶ。
     * 管理画面からはデモの店の名前を変えられない（rename が断る）ので、デモの店の名前を変える道はこれだけ。デモの印の無い店は断る。
     * 記録と監査ログは rename と同じ（運営は無し。誰が変えたかは actor）。同じ名前なら何も書かずに偽
     */
    public function renameDemo(Tenant $tenant, mixed $name, string $actor): bool
    {
        $name = self::validated($name);

        if (! DemoShop::is($tenant)) {
            throw ValidationException::withMessages(['name' => 'デモの印の無い店の名前は、デモのスクリプトからは変えられません。']);
        }

        return $this->write($tenant, $name, null, $actor, null);
    }

    /**
     * 名前を書く（形は確かめ済み）。書く順番はクラスの説明のとおり
     */
    private function write(Tenant $tenant, string $name, ?Operator $operator, string $actor, ?string $ip): bool
    {
        $id = (string) $tenant->getTenantKey();
        $central = DB::connection((string) config('tenancy.database.central_connection'));

        $changed = DB::transaction(function () use ($central, $id, $name, $operator, $actor, $ip): bool {
            $this->events->lock();

            return $central->transaction(function () use ($id, $name, $operator, $actor, $ip): bool {
                $current = Tenant::query()->whereKey($id)->lockForUpdate()->value('name');

                if ($current === $name) {
                    return false;
                }

                // 名前の列だけを書く（data の列〔店の DB の鍵など〕を書き直さない）
                Tenant::query()->whereKey($id)->update(['name' => $name]);
                $this->audit->record('tenant.renamed', $operator, ['by' => $actor], $id, $ip);
                $this->events->append('shop.renamed', 'shop', $id, [], $actor);

                return true;
            });
        });

        $tenant->setAttribute('name', $name);
        $tenant->syncOriginalAttribute('name');

        return $changed;
    }
}
