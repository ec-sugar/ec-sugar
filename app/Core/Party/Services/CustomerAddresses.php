<?php

declare(strict_types=1);

namespace App\Core\Party\Services;

use App\Core\Event\EventStore;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 会員のお届け先（ADR 0044 の 3）。いくつも持て、先頭（いつものお届け先）を注文の入力に最初から入れる。
 *
 * - 連鎖のロック → 台帳の行のロックの順で書き、イベントには ID だけを残す（個人情報は入れない）
 * - 持ち主の確かめはここで（ほかの会員のお届け先の ID を渡されても、見つからないのと同じ）
 * - 形の確かめ（名前・電話・郵便番号・都道府県…）は呼ぶ側（ショップの注文の入力と同じ決まり）。ここでは項目と長さだけを見る
 */
final class CustomerAddresses
{
    public const int LIMIT = 20;

    public function __construct(private readonly EventStore $events) {}

    /**
     * いつものお届け先を先頭に、入れた順
     *
     * @return Collection<int, CustomerAddress>
     */
    public function list(CustomerAccount $account): Collection
    {
        return CustomerAddress::query()->where('customer_account_id', $account->id)
            ->orderByDesc('is_default')->orderBy('created_at')->orderBy('id')->get();
    }

    public function find(CustomerAccount $account, string $id): ?CustomerAddress
    {
        return preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) === 1
            ? CustomerAddress::query()->where('customer_account_id', $account->id)->whereKey($id)->first()
            : null;
    }

    /**
     * @param  array<string, mixed>  $address
     */
    public function add(CustomerAccount $account, array $address, string $actor): CustomerAddress
    {
        $address = self::shape($address);

        return $this->write($account, function () use ($account, $address, $actor): CustomerAddress {
            $count = CustomerAddress::query()->where('customer_account_id', $account->id)->count();

            if ($count >= self::LIMIT) {
                throw new CustomerException('お届け先は '.self::LIMIT.' 件まで登録できます。使わないお届け先を削除してから追加してください。');
            }

            $row = CustomerAddress::create(['customer_account_id' => $account->id, 'address' => $address, 'is_default' => $count === 0]);
            $this->events->append('customer_address.added', 'customer_account', $account->id, ['address_id' => $row->id], $actor);

            return $row;
        });
    }

    /**
     * @param  array<string, mixed>  $address
     */
    public function update(CustomerAccount $account, string $id, array $address, string $actor): CustomerAddress
    {
        $address = self::shape($address);

        return $this->write($account, function () use ($account, $id, $address, $actor): CustomerAddress {
            $row = $this->owned($account, $id);
            $row->address = $address;
            $row->save();
            $this->events->append('customer_address.updated', 'customer_account', $account->id, ['address_id' => $row->id], $actor);

            return $row;
        });
    }

    public function remove(CustomerAccount $account, string $id, string $actor): void
    {
        $this->write($account, function () use ($account, $id, $actor): void {
            $row = $this->owned($account, $id);
            $wasDefault = $row->is_default;
            $row->delete();

            // いつものお届け先を消したら、残りのいちばん古いものを次のいつものお届け先に
            if ($wasDefault) {
                CustomerAddress::query()->where('customer_account_id', $account->id)->orderBy('created_at')->orderBy('id')->limit(1)->update(['is_default' => true]);
            }

            $this->events->append('customer_address.removed', 'customer_account', $account->id, ['address_id' => $row->id], $actor);
        });
    }

    public function makeDefault(CustomerAccount $account, string $id, string $actor): void
    {
        $this->write($account, function () use ($account, $id, $actor): void {
            $row = $this->owned($account, $id);
            CustomerAddress::query()->where('customer_account_id', $account->id)->where('is_default', true)->update(['is_default' => false]);
            $row->is_default = true;
            $row->save();
            $this->events->append('customer_address.defaulted', 'customer_account', $account->id, ['address_id' => $row->id], $actor);
        });
    }

    /**
     * 退会の時: 会員のお届け先を全部消す（CustomerAccounts::leave の中、ロックの中から呼ぶ）
     */
    public function forgetAll(CustomerAccount $account): int
    {
        return CustomerAddress::query()->where('customer_account_id', $account->id)->delete();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function write(CustomerAccount $account, callable $work): mixed
    {
        return DB::transaction(function () use ($account, $work): mixed {
            $this->events->lock();
            $locked = CustomerAccount::query()->whereKey($account->id)->lockForUpdate()->first();

            if ($locked === null || $locked->member_since === null || $locked->disabled_at !== null) {
                throw new CustomerException('会員としてログインし直してください。');
            }

            return $work();
        });
    }

    private function owned(CustomerAccount $account, string $id): CustomerAddress
    {
        $row = $this->find($account, $id);

        if ($row === null) {
            throw new CustomerException('そのお届け先は見つかりません。');
        }

        return CustomerAddress::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array{name: string, phone: string, postal: string, prefecture: string, city: string, line1: string, line2: string|null}
     */
    private static function shape(array $address): array
    {
        $text = static function (string $field, bool $required) use ($address): ?string {
            $value = $address[$field] ?? null;

            if ($value === null || $value === '') {
                if ($required) {
                    throw new \InvalidArgumentException("お届け先の {$field} が必要です");
                }

                return null;
            }

            if (! is_string($value) || mb_strlen($value) > 100) {
                throw new \InvalidArgumentException("お届け先の {$field} の形が違います");
            }

            return $value;
        };

        return [
            'name' => (string) $text('name', true),
            'phone' => (string) $text('phone', true),
            'postal' => (string) $text('postal', true),
            'prefecture' => (string) $text('prefecture', true),
            'city' => (string) $text('city', true),
            'line1' => (string) $text('line1', true),
            'line2' => $text('line2', false),
        ];
    }
}
