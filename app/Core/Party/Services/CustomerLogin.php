<?php

declare(strict_types=1);

namespace App\Core\Party\Services;

use App\Core\Crypto\BlindIndex;
use App\Core\Crypto\TenantCipher;
use App\Core\Event\EventStore;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\CustomerLoginCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 会員のログインのコード（ADR 0044 の 3、ADR 0023）。メールに 6 桁のコードを送り、合えばその台帳の会員になる（台帳が無ければ作る）。
 *
 * - コードは 10 分・1 回だけ・5 回まで。新しいコードを出すと、同じメールの前のコードは使えなくなる。合ったら、同じメールのほかのコードも止める
 *   （同時に出して残ったコードが、後で繰り上がって通らないように）
 * - 確かめに使うのは店の鍵の HMAC（コードの ID と組にして）。送る間だけ宛先とコードを暗号化して行に置き、送る仕事が読んで消す
 * - メールの有る無しで何も変えない（いつも作る。誰がお客さんかを外から探れない）。送ること・回数の制限は呼ぶ側（ショップ）
 * - コードの照らし合わせは行のロックだけ（ログインの前の外からの要求に、店の書き込み全体の錠を取らせない）。
 *   合った時だけ、連鎖のロックの中で台帳を引いて会員の印を付け、イベントに残す。止めた台帳は入れない
 */
final class CustomerLogin
{
    public const int CODE_MINUTES = 10;

    public const int MAX_ATTEMPTS = 5;

    public const string MERGED = 'このメールアドレスでのご注文は、ショップでほかのメールアドレスのお客さま情報にまとめられています。そちらのメールアドレスでログインしてください。';

    public function __construct(
        private readonly EventStore $events,
        private readonly CustomerAccounts $accounts,
    ) {}

    /**
     * コードを出す。返すのはコードの行の ID（コードそのものは送る仕事だけが読む）
     */
    public function issue(string $email): string
    {
        $index = BlindIndex::of('email', $email);
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        return DB::transaction(function () use ($index, $code, $email): string {
            $now = CarbonImmutable::now();
            // 前のコードは使えなくする（届いた最後の 1 通だけが効く）
            CustomerLoginCode::query()->where('email_index', $index)->whereNull('used_at')->where('expires_at', '>', $now)->update(['expires_at' => $now]);
            $row = new CustomerLoginCode(['email_index' => $index, 'code_hash' => '', 'expires_at' => $now->addMinutes(self::CODE_MINUTES), 'email' => trim($email), 'sealed_code' => $code]);
            $row->id = $row->newUniqueId();
            $row->code_hash = self::hash($row->id, $code);
            $row->save();

            return $row->id;
        });
    }

    /**
     * 送る仕事が読む: まだ使える行の宛先とコード。読んだら消す（もう一度は読めない）
     *
     * @return array{email: string, code: string}|null
     */
    public function takeForSending(string $id): ?array
    {
        return DB::transaction(function () use ($id): ?array {
            $row = CustomerLoginCode::query()->whereKey($id)->lockForUpdate()->first();

            if ($row === null || $row->email === null || $row->sealed_code === null) {
                return null;
            }

            $out = ['email' => $row->email, 'code' => $row->sealed_code];
            $row->email = null;
            $row->sealed_code = null;
            $row->save();

            return $row->used_at === null && $row->expires_at->isFuture() ? $out : null;
        });
    }

    /**
     * コードを確かめる。合えば会員の台帳を返す。違う・期限切れ・使用済み・回数切れ・止めた台帳は null（どれかは言わない）。
     * コードは合ったが、ほかの記録にまとめられた台帳なら CustomerException（コードは使用済みになる）
     *
     * @throws CustomerException
     */
    public function verify(string $email, string $code): ?CustomerAccount
    {
        if (! $this->check($email, $code)) {
            return null;
        }

        return $this->joinVerified($email);
    }

    /**
     * 持ち主を確かめ終わったメールの台帳を会員にする（無ければ作る）。止めた台帳は null、まとめた台帳は CustomerException。
     * 呼んでよいのは、そのメールの持ち主であることを呼ぶ側が確かめた後だけ: メールのコードが合った時（verify）と、
     * デモの店の来訪者のアドレス（セッションの合い言葉から作る、その来訪者だけのアドレス。ADR 0086）
     *
     * @throws CustomerException
     */
    public function joinVerified(string $email): ?CustomerAccount
    {
        return DB::transaction(function () use ($email): ?CustomerAccount {
            // 会員の印を付けるので、連鎖のロックを先に（決まりどおり、行のロックはその後）
            $this->events->lock();
            $account = $this->accounts->forEmail($email, 'storefront');
            $account = CustomerAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($account->disabled_at !== null) {
                return null;
            }

            // 店の人がほかの記録にまとめたゲストの記録は、会員にしない（会員どうしはまとめない決まりを、後からのログインで崩さない。
            // ADR 0044 の 2、2026-11-03 の運営の判断で「ログインを断る」）。コードが合った本人にだけ、まとめた先で入るよう伝える
            if ($account->merged_into_id !== null) {
                throw new CustomerException(self::MERGED);
            }

            if ($account->member_since === null) {
                $account->member_since = CarbonImmutable::now();
                $account->save();
                $this->events->append('customer_account.joined', 'customer_account', $account->id, [], 'storefront');
            }

            return $account;
        });
    }

    /**
     * コードだけを確かめる（台帳は作らない・触らない。メールの変更で新しいアドレスの持ち主を確かめる時）。
     * 照らし合わせは行のロックだけ。合ったら使用済みにし、同じメールのほかのコードも止める
     */
    public function check(string $email, string $code): bool
    {
        if (preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            return false;
        }

        $index = BlindIndex::of('email', $email);

        return DB::transaction(function () use ($code, $index): bool {
            $now = CarbonImmutable::now();
            $row = CustomerLoginCode::query()->where('email_index', $index)->whereNull('used_at')->where('expires_at', '>', $now)
                ->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->first();

            if ($row === null || $row->attempts >= self::MAX_ATTEMPTS) {
                return false;
            }

            $row->attempts++;

            if (! hash_equals($row->code_hash, self::hash($row->id, $code))) {
                $row->save();

                return false;
            }

            $row->used_at = $now;
            $row->save();
            CustomerLoginCode::query()->where('email_index', $index)->whereKeyNot($row->id)->whereNull('used_at')->where('expires_at', '>', $now)->update(['expires_at' => $now]);

            return true;
        });
    }

    private static function hash(string $id, string $code): string
    {
        return app(TenantCipher::class)->index('customer-login-code|'.$id.'|'.$code);
    }
}
