<?php

declare(strict_types=1);

namespace App\Core\Party\Services;

use App\Core\Crypto\BlindIndex;
use App\Core\Event\EventStore;
use App\Core\Party\Events\CustomerLeft;
use App\Core\Party\Models\CustomerAccount;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * お客さんの台帳を引く・作る（ADR 0044 の 1・2）。自動でまとめるのはメールの完全一致だけ（空白と大文字小文字はそろえる）。
 *
 * 連鎖のロックの中で引き、無ければ作る（同時の 2 つの注文で 2 行にしない。email_index の一意の制約が DB の 2 枚目の守り）。
 * 作った記録はイベントに残す（個人情報は入れない）。
 */
final class CustomerAccounts
{
    public function __construct(
        private readonly EventStore $events,
        private readonly CustomerAddresses $addresses,
        private readonly CustomerSessions $sessions,
        private readonly CustomerPasskeys $passkeys,
    ) {}

    /**
     * 買い手のメールの台帳。無ければ作る
     */
    public function forEmail(string $email, string $actor): CustomerAccount
    {
        if (trim($email) === '' || mb_strlen($email) > 254) {
            throw new InvalidArgumentException('お客さんのメールアドレスが要ります');
        }

        return DB::transaction(function () use ($email, $actor): CustomerAccount {
            $this->events->lock();

            $account = CustomerAccount::query()->where('email_index', BlindIndex::of('email', $email))->lockForUpdate()->first();

            if ($account !== null) {
                return $account;
            }

            $account = CustomerAccount::create(['email' => trim($email)]);
            $this->events->append('customer_account.opened', 'customer_account', $account->id, [], $actor);

            return $account;
        });
    }

    /**
     * 買い手のメールの台帳を引くだけ（作らない）。注文の確かめの画面と確定で、値引きの 1 人の回数を見る（ADR 0065）
     */
    public function findByEmail(string $email): ?CustomerAccount
    {
        if (trim($email) === '' || mb_strlen($email) > 254) {
            return null;
        }

        return CustomerAccount::query()->where('email_index', BlindIndex::of('email', $email))->first();
    }

    /**
     * 退会（ADR 0044 の 3、2026-11-01 に運営が決めた形）: 会員の印をすべて取り消し、会員として入れた届け先とパスキーを消し、台帳をゲストの形に戻す。
     * App の会員だけの申し込みは CustomerLeft を聞いて同じトランザクションで解約する。注文の記録（取引・注文ごとの Party）は帳簿として残す。本人の再確認は呼ぶ側
     */
    public function leave(CustomerAccount $account, string $actor): void
    {
        DB::transaction(function () use ($account, $actor): void {
            $this->events->lock();
            $locked = CustomerAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($locked->member_since === null) {
                return;
            }

            $removed = $this->addresses->forgetAll($locked);
            $this->passkeys->forgetAll($locked);
            $this->sessions->endAll($locked);
            $locked->member_since = null;
            $locked->save();
            $this->events->append('customer_account.left', 'customer_account', $locked->id, ['addresses_removed' => $removed], $actor);
            // App の会員だけの申し込み（定期便など）を同じトランザクションで解約する（退会した人はマイページに入れず、自分で解約できない）
            event(new CustomerLeft($locked->id, $actor));
        });
    }

    /**
     * メールの変更（ADR 0044 の 3）。新しいアドレスの持ち主はコードで確かめてから呼ぶ。本人の再確認も呼ぶ側。
     * 新しいアドレスの台帳がほかにあれば変えない（ゲストの注文の台帳でも。まとめるのは店の人が管理画面で。2）。
     * 変えたら、この印のほかの会員の印を取り消す（前のアドレスへの知らせは呼ぶ側）
     *
     * @return bool 変えたか（新しいアドレスの台帳がほかにあれば false）
     */
    public function changeEmail(CustomerAccount $account, string $email, ?string $keepToken, string $actor): bool
    {
        $email = trim($email);

        if ($email === '' || mb_strlen($email) > 254) {
            throw new InvalidArgumentException('新しいメールアドレスが要ります');
        }

        return DB::transaction(function () use ($account, $email, $keepToken, $actor): bool {
            $this->events->lock();
            $locked = CustomerAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($locked->member_since === null || $locked->disabled_at !== null) {
                throw new CustomerException('会員としてログインし直してください。');
            }

            $index = BlindIndex::of('email', $email);

            if ($index === $locked->email_index) {
                return true;
            }

            if (CustomerAccount::query()->where('email_index', $index)->whereKeyNot($locked->id)->exists()) {
                return false;
            }

            $locked->email = $email;
            $locked->save();
            $this->sessions->endAllBut($locked, $keepToken);
            $this->events->append('customer_account.email_changed', 'customer_account', $locked->id, [], $actor);

            return true;
        });
    }
}
