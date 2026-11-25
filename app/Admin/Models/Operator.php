<?php

declare(strict_types=1);

namespace App\Admin\Models;

use App\Admin\Models\Casts\OperatorEmail;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use LogicException;
use RuntimeException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * 運営アカウント（中央 DB、ADR 0013）。全テナントに立ち入れるので、いちばん強い権限を持つ。
 *
 * - パスワードは持たない。ログインはパスキー（本人確認つき）だけ
 * - 消さずに止める（disabled_at）。止めたアカウントは次のリクエストでセッションごと締め出される
 * - テナント文脈の中でも中央 DB を読む（CentralConnection）
 * - メールアドレスは APP_KEY で暗号化して持つ（ADR 0112 の段階 2。読むのは暗号文と平文の両方・書くのは暗号文。Casts\OperatorEmail）。
 *   探すのと重複を防ぐのは、別の鍵の HMAC の `email_index`。メールアドレスで探す時は `whereEmail()` を使う
 *   （`where('email', …)` は暗号文と比べるので、いつも見つからない）
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $email_index 保存の前（新しい行）は空。保存すると必ず入る（DB が NOT NULL）
 * @property string $role owner | staff（ADR 0024）
 * @property CarbonImmutable|null $recovered_at 回復用のコードで戻った時（ADR 0038）
 * @property CarbonImmutable|null $disabled_at
 * @property CarbonImmutable|null $last_login_at
 */
final class Operator extends Model implements AuthenticatableContract, PasskeyUser
{
    use Authenticatable;
    use CentralConnection;
    use HasUlids;
    use PasskeyAuthenticatable;

    protected $table = 'operators';

    /** メールアドレスの HMAC の鍵を APP_KEY から導く文脈（変えると保存した email_index と合わなくなる） */
    private const string EMAIL_INDEX_CONTEXT = 'ec-sugar.operator-email-index.v1';

    protected $fillable = ['name', 'email', 'disabled_at', 'last_login_at'];

    protected static function booted(): void
    {
        self::saving(static function (self $operator): void {
            // 新しい行・アドレスを変えた時・まだ照合用の値の無い行（前のバージョンが足した行）で作る
            if (! $operator->exists || $operator->isDirty('email') || $operator->email_index === null) {
                $email = self::normalizeEmail((string) $operator->email);

                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \InvalidArgumentException('メールアドレスの形式が不正です');
                }

                $operator->email = $email;
                $operator->email_index = self::emailIndex($email);
            }
        });

        self::deleting(static fn (): never => throw new LogicException('運営アカウントは削除せず止める（disabled_at）'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email' => OperatorEmail::class,
            'disabled_at' => 'immutable_datetime',
            'recovered_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
        ];
    }

    /**
     * メールアドレスの照合用の値（小文字にそろえた形の HMAC-SHA256。鍵は APP_KEY から HKDF で導いた別の鍵）。
     * 鍵が無ければ、DB だけを見ても「どのアドレスか」を総当たりで確かめられない
     */
    public static function emailIndex(string $email): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }

        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('APP_KEY がありません（運営のメールアドレスの照合用の鍵を作れません）');
        }

        return hash_hmac('sha256', self::normalizeEmail($email), hash_hkdf('sha256', $key, 32, self::EMAIL_INDEX_CONTEXT));
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * メールアドレスで探す（照合用の値で比べる）
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWhereEmail(Builder $query, string $email): Builder
    {
        return $query->where(fn ($q) => self::matchEmail($q, $email, $this->getTable().'.'));
    }

    /**
     * メールアドレスの照合の条件（$prefix は表の名前と点。結合した問い合わせから使う）。
     * 照合用の値で比べ、まだ値の無い行（段階 1 の間に前のバージョンが足した行）だけは平文で比べる。暗号文は平文と一致しない
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder  $query
     */
    public static function matchEmail($query, string $email, string $prefix = ''): void
    {
        $query->where($prefix.'email_index', self::emailIndex($email))
            ->orWhere(static fn ($q) => $q->whereNull($prefix.'email_index')->where($prefix.'email', self::normalizeEmail($email)));
    }

    /**
     * 記録（ドメインイベント・監査ログ）に残す、操作した運営の札（`operator:<id>`）
     */
    public function actor(): string
    {
        return 'operator:'.$this->id;
    }

    /**
     * 回復用のコードで戻ってから 24 時間のうちか（ADR 0038）。その間は、回復の前からあるパスキーを消せず、回復用のコードも作り直せない
     */
    public function recentlyRecovered(): bool
    {
        $at = $this->getAttribute('recovered_at');

        return $at instanceof \DateTimeInterface && $at > now()->subDay();
    }

    public function isActive(): bool
    {
        return $this->disabled_at === null;
    }

    /**
     * 外部キーの既定名。パッケージのトレイトの passkeys() はこれで引き、パッケージの表の列は user_id 固定のため合わせる。
     * ほかの関連（enrollments など）は外部キーを明示する。
     */
    public function getForeignKey(): string
    {
        return 'user_id';
    }

    /**
     * 自分のパスキーの本数と、同期のパスキーがあるか（1 本だけなら管理画面で知らせる）。
     * 本人の分だけを数える。ほかの運営の情報は含めない
     *
     * @return array{count: int, synced: bool}
     */
    public function passkeySummary(): array
    {
        $passkeys = $this->passkeys()->get(['id', 'credential']);

        return [
            'count' => $passkeys->count(),
            'synced' => $passkeys->contains(static fn ($passkey): bool => $passkey instanceof OperatorPasskey && $passkey->isSynced()),
        ];
    }

    /**
     * @return HasMany<OperatorEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(OperatorEnrollment::class, 'operator_id');
    }

    // パスワードも remember トークンも使わない（空の名前なら Laravel は remember トークンを書かない）
    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
