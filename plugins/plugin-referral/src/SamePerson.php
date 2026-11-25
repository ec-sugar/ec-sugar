<?php

declare(strict_types=1);

namespace EcSugar\PluginReferral;

use App\Core\Crypto\BlindIndex;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\CustomerAddress;
use App\Core\Party\Models\Party;
use App\Core\Party\Services\CustomerMerges;
use App\Core\Transaction\Models\Transaction;

/**
 * 自分を紹介していないかの確かめ（ADR 0075 の 3）。紹介した人と紹介された人で、次のどれかが重なれば同じ人と見る:
 * メール（ブラインドインデックス）・電話（ブラインドインデックス）・届け先（郵便番号から番地まで。住所のブラインドインデックスは核に無いので、
 * 店の鍵で復号した値をこの処理の中だけで同じ形にそろえて比べる。どこにも書かない）。
 * 紹介した人の側は、台帳（まとめた記録も）のメール・会員の届け先・直近の注文の買う人と届け先。紹介された人の側は、台帳のメール・会員の届け先・最初の注文の買う人と届け先
 */
final class SamePerson
{
    /** 紹介した人の注文を見る数（新しい順） */
    private const int RECENT_ORDERS = 50;

    public static function matches(string $referrerId, string $friendId, ?string $transactionId): bool
    {
        $referrer = self::facts($referrerId, null);
        $friend = self::facts($friendId, $transactionId);

        foreach (['email', 'phone', 'address'] as $kind) {
            if (array_intersect($referrer[$kind], $friend[$kind]) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * 台帳から見えるメール・電話・届け先（どれもそろえた形の索引か HMAC）。$transactionId が有ればその注文だけ、無ければ直近の注文
     *
     * @return array{email: list<string>, phone: list<string>, address: list<string>}
     */
    private static function facts(string $accountId, ?string $transactionId): array
    {
        $account = CustomerAccount::query()->find($accountId);

        if ($account === null) {
            return ['email' => [], 'phone' => [], 'address' => []];
        }

        $family = CustomerMerges::family($account);
        $emails = CustomerAccount::query()->whereKey($family)->pluck('email_index')->filter()->map(static fn (mixed $v): string => (string) $v)->all();
        $phones = [];
        $addresses = [];

        foreach (CustomerAddress::query()->whereIn('customer_account_id', $family)->limit(50)->get() as $saved) {
            $phones[] = self::phone($saved->address['phone'] ?? null);
            $addresses[] = self::address($saved->address);
        }

        $orders = Transaction::query()->where('kind', 'sale')->whereIn('customer_account_id', $family)
            ->when($transactionId !== null, static fn ($q) => $q->whereKey($transactionId))
            ->orderByDesc('created_at')->limit(self::RECENT_ORDERS)->get(['id', 'customer_id']);

        foreach (Party::query()->whereKey($orders->pluck('customer_id')->filter()->all())->get() as $buyer) {
            $emails[] = $buyer->email_index;
            $phones[] = $buyer->phone_index;
            $addresses[] = is_array($buyer->address) ? self::address($buyer->address) : null;
        }

        foreach (Fulfillment::query()->whereIn('transaction_id', $orders->pluck('id')->all())->whereNotNull('address')->limit(self::RECENT_ORDERS)->get() as $fulfillment) {
            $phones[] = self::phone($fulfillment->address['phone'] ?? null);
            $addresses[] = is_array($fulfillment->address) ? self::address($fulfillment->address) : null;
        }

        return [
            'email' => self::clean($emails),
            'phone' => self::clean($phones),
            'address' => self::clean($addresses),
        ];
    }

    private static function phone(mixed $phone): ?string
    {
        return is_string($phone) && BlindIndex::normalize('phone', $phone) !== '' ? BlindIndex::of('phone', $phone) : null;
    }

    /**
     * 届け先をそろえた形（郵便番号は数字だけ、ほかは全角半角・大文字小文字・空白をそろえる）の HMAC。番地まで無ければ null
     *
     * @param  array<array-key, mixed>  $address
     */
    private static function address(array $address): ?string
    {
        $zip = preg_replace('/\D+/', '', (string) ($address['zip'] ?? $address['postal'] ?? '')) ?? '';
        $parts = array_map(static fn (mixed $v): string => is_string($v) ? $v : '', [$address['prefecture'] ?? null, $address['city'] ?? null, $address['line1'] ?? null, $address['line2'] ?? null]);
        $rest = preg_replace('/\s+/u', '', BlindIndex::normalize('address', implode('', $parts))) ?? '';

        if ($zip === '' || ($parts[2] === '')) {
            return null;
        }

        return BlindIndex::of('address', $zip.'|'.$rest);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private static function clean(array $values): array
    {
        return array_values(array_unique(array_filter($values, static fn (mixed $v): bool => is_string($v) && $v !== '')));
    }
}
