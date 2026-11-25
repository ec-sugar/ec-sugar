<?php

declare(strict_types=1);

namespace EcSugar\PluginWrapping;

use App\Core\Event\EventStore;
use App\Core\Listing\Models\Listing;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use EcSugar\PluginWrapping\Models\WrappingListing;
use EcSugar\PluginWrapping\Models\WrappingType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ラッピングの種類と、商品ごとの「ラッピングできる」「のしを付けられる」を書く（ADR 0078）。書くのはここだけ。
 * どれも連鎖のロック（EventStore::lock()）の後にその行のロックを取り、同じトランザクションでイベントを残す
 * （wrapping.type_created・type_updated・type_deleted・listing_updated）。中身は ID・料金・真偽・変えた欄の名前だけ。
 * 種類を直す・消すのは、もう受けた注文には響かない（注文の時の名前と料金を注文と App の表に写してある）
 */
final class WrappingTypes
{
    /** 1 つの店の種類の数（注文の入力の選択肢に収まるように） */
    public const int MAX_TYPES = 20;

    public const int NAME_MAX = 30;

    public const int PRICE_MAX = 100000;

    public function __construct(private readonly EventStore $events) {}

    /**
     * @param  array<string, bool|string|null>  $values  name・price・active（核が形を確かめた後）
     */
    public function create(array $values, string $actor): string
    {
        return DB::transaction(function () use ($values, $actor): string {
            $this->events->lock();

            if (WrappingType::query()->count() >= self::MAX_TYPES) {
                throw ValidationException::withMessages(['values' => 'ラッピングの種類は '.self::MAX_TYPES.' 個までです。使わない種類を削除してから追加してください。']);
            }

            $type = WrappingType::query()->create(self::row($values) + ['position' => (int) WrappingType::query()->max('position') + 1]);
            $this->events->append('wrapping.type_created', 'wrapping_type', $type->id, ['price' => $type->price, 'active' => $type->active], $actor);

            return $type->id;
        });
    }

    /**
     * @param  array<string, bool|string|null>  $values
     */
    public function update(string $id, array $values, string $actor): void
    {
        DB::transaction(function () use ($id, $values, $actor): void {
            $this->events->lock();
            $type = WrappingType::query()->whereKey($id)->lockForUpdate()->first() ?? throw new AdminRecordNotFound;
            $row = self::row($values);
            $changed = array_keys(array_filter($row, static fn (mixed $value, string $key): bool => $type->getAttribute($key) !== $value, ARRAY_FILTER_USE_BOTH));

            if ($changed === []) {
                return;
            }

            $type->fill($row)->save();
            $this->events->append('wrapping.type_updated', 'wrapping_type', $type->id, ['changed' => $changed, 'price' => $type->price, 'active' => $type->active], $actor);
        });
    }

    public function delete(string $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $this->events->lock();
            $type = WrappingType::query()->whereKey($id)->lockForUpdate()->first() ?? throw new AdminRecordNotFound;
            $type->delete();
            $this->events->append('wrapping.type_deleted', 'wrapping_type', $type->id, [], $actor);
        });
    }

    /**
     * 商品の「ラッピングできる」「のしを付けられる」を保存する。どちらも外したら行を消す（無い行はどちらもできない）
     */
    public function saveListing(Listing $listing, bool $wrappable, bool $noshi, string $actor): void
    {
        DB::transaction(function () use ($listing, $wrappable, $noshi, $actor): void {
            $this->events->lock();
            $row = WrappingListing::query()->whereKey($listing->id)->lockForUpdate()->first();

            if (($row->wrappable ?? false) === $wrappable && ($row->noshi ?? false) === $noshi) {
                return;
            }

            if (! $wrappable && ! $noshi) {
                $row?->delete();
            } elseif ($row === null) {
                WrappingListing::query()->create(['listing_id' => $listing->id, 'wrappable' => $wrappable, 'noshi' => $noshi]);
            } else {
                $row->fill(['wrappable' => $wrappable, 'noshi' => $noshi])->save();
            }

            $this->events->append('wrapping.listing_updated', 'listing', $listing->id, ['wrappable' => $wrappable, 'noshi' => $noshi], $actor);
        });
    }

    /**
     * 欄の値を表の行にする。料金は 0〜100,000 の整数（円・税込み）
     *
     * @param  array<string, bool|string|null>  $values
     * @return array{name: string, price: int, active: bool}
     */
    private static function row(array $values): array
    {
        $name = trim((string) ($values['name'] ?? ''));
        $price = trim((string) ($values['price'] ?? ''));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX || preg_match('/[<>]|\p{C}/u', $name) === 1) {
            throw ValidationException::withMessages(['name' => '名前は、改行なしの '.self::NAME_MAX.' 文字以内で入れてください（< と > は使えません）。']);
        }

        if (preg_match('/\A(0|[1-9][0-9]{0,5})\z/', $price) !== 1 || (int) $price > self::PRICE_MAX) {
            throw ValidationException::withMessages(['price' => '料金は 0 から '.number_format(self::PRICE_MAX).' までの数字（円・税込み）で入れてください。']);
        }

        return ['name' => $name, 'price' => (int) $price, 'active' => ($values['active'] ?? false) === true];
    }
}
