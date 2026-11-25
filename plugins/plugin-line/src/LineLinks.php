<?php

declare(strict_types=1);

namespace EcSugar\PluginLine;

use App\Core\Crypto\TenantCipher;
use App\Core\Event\EventStore;
use App\Core\Party\Models\CustomerAccount;
use App\Storefront\Members\AccountNotice;
use Carbon\CarbonImmutable;
use EcSugar\PluginLine\Models\LineLink;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 会員と LINE のつなぎ（ADR 0070）。1 つの LINE は 1 人の会員だけ、1 人の会員に 1 つの LINE だけ（DB の一意でも守る）。
 *
 * - つなぐ・外すは、ログインの手段を足す・消す操作なので、パスキーと同じく連鎖のロックとイベント（line.linked・line.unlinked。
 *   中身は無し。LINE の利用者の ID は入れない）。台帳の行のロックも取り、会員で・止めていない・まとめられていない台帳にだけつなぐ
 * - 「友だちか」の印は、Webhook の follow・unfollow とログインの時の問い合わせで変わる、送ってよい相手かの印（状態でもお金でもなく、
 *   監査の源泉でもない）。連鎖のロックとイベントの外で、1 文の条件つきの更新（前の知らせより新しい時だけ）で変える（conventions.md の 3 の例外）
 * - 退会（CustomerLeft）では、退会の同じトランザクションで行を消す
 * - つないだ・外した時は、会員のメールへ事後に知らせる（核の AccountNotice。何をしたかと日時だけ）
 */
final class LineLinks
{
    public function __construct(private readonly EventStore $events) {}

    /**
     * LINE の利用者の ID の引き手（店の鍵の HMAC。ほかの店では同じ ID でも別の値）
     */
    public static function index(string $sub): string
    {
        return app(TenantCipher::class)->index('line-user|'.$sub);
    }

    public function forLine(string $sub): ?LineLink
    {
        return LineLink::query()->where('line_user_index', self::index($sub))->first();
    }

    public function forAccount(string $accountId): ?LineLink
    {
        return LineLink::query()->where('customer_account_id', $accountId)->first();
    }

    public function link(CustomerAccount $account, string $sub, bool $friend, string $actor): LinkOutcome
    {
        $outcome = $this->linkInside($account, $sub, $friend, $actor);

        // つないだら、会員のメールへ事後に知らせる（コード 1 つで消えない裏口を、本当の持ち主が気づけるように。見直し 中 2）
        if ($outcome === LinkOutcome::Linked) {
            AccountNotice::send($account->id, 'LINE と連携しました（次から LINE でログインできます）');
        }

        return $outcome;
    }

    private function linkInside(CustomerAccount $account, string $sub, bool $friend, string $actor): LinkOutcome
    {
        try {
            return DB::transaction(function () use ($account, $sub, $friend, $actor): LinkOutcome {
                $this->events->lock();
                $locked = CustomerAccount::query()->whereKey($account->id)->lockForUpdate()->first();

                if ($locked === null || $locked->member_since === null || $locked->disabled_at !== null || $locked->merged_into_id !== null) {
                    return LinkOutcome::NotMember;
                }

                $index = self::index($sub);
                $line = LineLink::query()->where('line_user_index', $index)->lockForUpdate()->first();

                if ($line !== null) {
                    return $line->customer_account_id === $locked->id ? LinkOutcome::Already : LinkOutcome::LineTaken;
                }

                if (LineLink::query()->where('customer_account_id', $locked->id)->exists()) {
                    return LinkOutcome::AccountTaken;
                }

                $now = CarbonImmutable::now();
                LineLink::query()->create([
                    'customer_account_id' => $locked->id,
                    'line_user' => $sub,
                    'line_user_index' => $index,
                    'friend' => $friend,
                    'friend_at_ms' => $now->getTimestampMs(),
                    'linked_at' => $now,
                ]);
                $this->events->append('line.linked', 'customer_account', $locked->id, [], $actor);

                return LinkOutcome::Linked;
            });
        } catch (UniqueConstraintViolationException) {
            // 連鎖のロックで順に並ぶので来ないが、来ても 2 人目にはつながない（DB の一意が最後の守り）
            return LinkOutcome::LineTaken;
        }
    }

    /**
     * 外す。外したら true（つないでいなければ false）
     */
    public function unlink(CustomerAccount $account, string $actor): bool
    {
        $removed = DB::transaction(function () use ($account, $actor): bool {
            $this->events->lock();

            if (LineLink::query()->where('customer_account_id', $account->id)->delete() === 0) {
                return false;
            }

            $this->events->append('line.unlinked', 'customer_account', $account->id, ['reason' => 'member'], $actor);

            return true;
        });

        if ($removed) {
            AccountNotice::send($account->id, 'LINE 連携を解除しました（LINE でのログインと、LINE でのお知らせは使えなくなります）');
        }

        return $removed;
    }

    /**
     * 退会した会員のつなぎを消す（退会のトランザクションと連鎖のロックの中で呼ばれる）
     */
    public function forget(string $accountId, string $actor): void
    {
        DB::transaction(function () use ($accountId, $actor): void {
            $this->events->lock();

            if (LineLink::query()->where('customer_account_id', $accountId)->delete() > 0) {
                $this->events->append('line.unlinked', 'customer_account', $accountId, ['reason' => 'left'], $actor);
            }
        });
    }

    /**
     * 友だちの印を変える（Webhook の follow・unfollow、ログインの時の問い合わせ）。前の知らせより新しい時だけ（届く順が前後しても古い知らせで戻さない）。
     * つないでいない LINE の知らせは何もしない（誰の知らせも残さない）
     */
    public function setFriend(string $sub, bool $friend, CarbonImmutable $at): void
    {
        $ms = $at->getTimestampMs();
        // 時刻はミリ秒の整数で比べる（同じ秒の中の前後も見分ける）。同じ時刻の知らせは先に届いた方のまま
        LineLink::query()->where('line_user_index', self::index($sub))
            ->where(static fn ($q) => $q->whereNull('friend_at_ms')->orWhere('friend_at_ms', '<', $ms))
            ->update(['friend' => $friend, 'friend_at_ms' => $ms, 'updated_at' => CarbonImmutable::now()]);
    }
}
