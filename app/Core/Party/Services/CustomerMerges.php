<?php

declare(strict_types=1);

namespace App\Core\Party\Services;

use App\Core\Event\EventStore;
use App\Core\Party\Models\CustomerAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 同じお客さんを手でまとめる・分け直す（ADR 0044 の 2）。自動でまとめるのはメールの完全一致だけ（CustomerAccounts）で、
 * ここは店の人が「同じ人」と決めたときだけ使う。
 *
 * - 注文（取引）は書き換えない。まとめられた台帳に「まとめた先」の印を付け、お客さんの画面と会員の履歴で、親と子の注文をいっしょに並べる
 * - まとめられる側はゲストだけ（会員どうしはまとめない。ログインの持ち主が変わるため）。入れ子にしない（まとめた先は、まとめられていない台帳だけ。
 *   子を持つ台帳は、まとめられる側にしない）
 * - 連鎖のロック → 2 つの台帳の行のロック（ID の順）で書き、イベントに残す（個人情報は入れない）
 */
final class CustomerMerges
{
    public function __construct(private readonly EventStore $events) {}

    public function merge(CustomerAccount $kept, string $mergedId, string $actor): void
    {
        DB::transaction(function () use ($kept, $mergedId, $actor): void {
            $this->events->lock();
            [$parent, $child] = $this->pair($kept->id, $mergedId);

            if ($parent->merged_into_id !== null) {
                throw new CustomerException('この記録は、ほかの記録にまとめられています。まとめた先の記録で操作してください。');
            }

            if ($child->merged_into_id !== null) {
                throw new CustomerException('その記録は、もうほかの記録にまとめられています。');
            }

            if ($child->member_since !== null) {
                throw new CustomerException('会員の記録は、まとめられる側にできません（ログインの持ち主が変わるため）。会員の記録の方を開いて、そちらにまとめてください。');
            }

            if (CustomerAccount::query()->where('merged_into_id', $child->id)->exists()) {
                throw new CustomerException('その記録には、まとめた記録があります。先にそちらを分け直してください。');
            }

            $child->merged_into_id = $parent->id;
            $child->merged_at = CarbonImmutable::now();
            $child->save();
            $this->events->append('customer_account.merged', 'customer_account', $parent->id, ['merged_id' => $child->id], $actor);
        });
    }

    public function unmerge(CustomerAccount $kept, string $mergedId, string $actor): void
    {
        DB::transaction(function () use ($kept, $mergedId, $actor): void {
            $this->events->lock();
            [$parent, $child] = $this->pair($kept->id, $mergedId);

            if ($child->merged_into_id !== $parent->id) {
                throw new CustomerException('その記録は、この記録にまとめられていません。');
            }

            $child->merged_into_id = null;
            $child->merged_at = null;
            $child->save();
            $this->events->append('customer_account.unmerged', 'customer_account', $parent->id, ['merged_id' => $child->id], $actor);
        });
    }

    /**
     * その台帳と、まとめられた台帳の ID（注文を並べるとき）
     *
     * @return list<string>
     */
    public static function family(CustomerAccount $account): array
    {
        return [$account->id, ...CustomerAccount::query()->where('merged_into_id', $account->id)->orderBy('id')->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all()];
    }

    /**
     * @return array{0: CustomerAccount, 1: CustomerAccount}
     */
    private function pair(string $parentId, string $childId): array
    {
        if (preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $childId) !== 1 || $childId === $parentId) {
            throw new CustomerException('同じ記録どうしはまとめられません。');
        }

        // 2 つの行は ID の順にロックする（同時に逆向きでまとめても行き詰まらない）
        $rows = CustomerAccount::query()->whereKey([$parentId, $childId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $parent = $rows->get($parentId);
        $child = $rows->get($childId);

        if (! $parent instanceof CustomerAccount || ! $child instanceof CustomerAccount) {
            throw new CustomerException('その記録は見つかりません。');
        }

        return [$parent, $child];
    }
}
