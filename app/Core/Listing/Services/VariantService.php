<?php

declare(strict_types=1);

namespace App\Core\Listing\Services;

use App\Core\Event\EventStore;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Listing\Enums\VariantStatus;
use App\Core\Listing\Events\ListingPriceChanged;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Models\ListingOption;
use App\Core\Listing\Models\ListingOptionValue;
use App\Core\Listing\Models\ListingVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 商品の種類の編集（ADR 0036）。変更はここを通し、ドメインイベントを残す。
 *
 * - 書き込みは EventStore::lock() → 商品の行ロック。商品のバージョン（楽観ロック）を進める（種類の変更も商品の編集のひとつ）
 * - 軸と値を決め直すと、値の組み合わせごとに種類を作る。名前の同じ組み合わせの種類は残し（価格・品番・在庫を保つ）、無くなった組み合わせの種類は消す（取引の記録は残る）
 * - 確保中の在庫がある種類は消せない（注文の途中の品が宙に浮く）
 * - 軸の無い商品に初めて軸を足すときは、今の 1 つの種類を選んだ組み合わせへ渡せる（$carryTo）。種類の行そのものをその組み合わせに付け替えるので、
 *   品番・価格・写真・在庫（在庫の行は種類を指す）がそのまま移る。渡さなければ今までどおり消える（運営の判断、2026-10-30。選んで渡す）
 */
final class VariantService
{
    public const int MAX_VALUES_PER_OPTION = 30;

    public const int MAX_VARIANTS = 100;

    public const array EDITABLE = ['sku', 'unit_amount', 'status', 'image_id'];

    public function __construct(private readonly EventStore $events) {}

    /**
     * 軸と値を決め直す。空の並びなら軸の無い商品（種類は 1 つ）に戻す。
     *
     * @param  list<array{name: string, values: list<string>}>  $options  軸の順（3 つまで）
     * @param  list<string>|null  $carryTo  軸の無い商品の今の種類を渡す組み合わせ（値の名前を軸の順に）。null なら渡さない
     *
     * @throws StaleListingException
     * @throws ValidationException 軸や値の形が違う・確保中の在庫がある種類を消そうとした
     */
    public function define(Listing $listing, int $expectedVersion, array $options, ?string $actor = null, ?array $carryTo = null): Listing
    {
        $options = self::normalize($options);

        return DB::transaction(function () use ($listing, $expectedVersion, $options, $actor, $carryTo): Listing {
            $this->events->lock();
            $locked = $this->lockListing($listing, $expectedVersion);

            // 今の種類を、値の名前の組み合わせで引けるように
            $current = [];

            foreach ($locked->variants()->get() as $variant) {
                $current[self::key(self::labelsOf($variant))] = $variant;
            }

            $wanted = [];

            foreach (self::combinations($options) as $labels) {
                $wanted[self::key($labels)] = $labels;
            }

            // 軸の無い商品の今の種類を、選んだ組み合わせとして残す（行を付け替える）
            if ($carryTo !== null) {
                $carryKey = self::key(array_map(static fn (mixed $label): string => trim((string) $label), $carryTo));

                if (count($current) !== 1 || ! isset($current[self::key([])])) {
                    throw ValidationException::withMessages(['carry_to' => '今の品番と在庫を引き継げるのは、選択項目の無い商品に初めて項目を追加するときだけです。']);
                }

                if ($options === [] || ! isset($wanted[$carryKey])) {
                    throw ValidationException::withMessages(['carry_to' => '引き継ぐ先の組み合わせが、新しい選択項目の中にありません。']);
                }

                $current = [$carryKey => $current[self::key([])]];
            }

            // 無くなる組み合わせに、注文の途中の品があれば何も変えずに断る
            $removed = array_values(array_filter($current, static fn (ListingVariant $v, string $key): bool => ! isset($wanted[$key]), ARRAY_FILTER_USE_BOTH));

            foreach ($removed as $variant) {
                if (InventoryStock::query()->where('variant_id', $variant->id)->where('reserved', '>', 0)->exists()) {
                    throw ValidationException::withMessages(['options' => '処理中の注文がある種類（'.$this->labelFor($current, $variant).'）は削除できません。注文が完了してから変更してください。']);
                }
            }

            // 作り直す間は、すべての種類をいったん消した扱いにして値を外す（同じ組み合わせは 1 つだけ、の決まりは消したものを数えない）。
            // 残る種類は後で戻す。消えた種類は消したまま（取引の行と在庫の記録は残る）
            ListingVariant::withTrashed()->where('listing_id', $locked->id)
                ->update(['value_1' => null, 'value_2' => null, 'value_3' => null, 'deleted_at' => DB::raw('COALESCE(deleted_at, now())')]);
            ListingOption::query()->where('listing_id', $locked->id)->delete();

            $valueIds = [];

            foreach ($options as $index => $option) {
                $row = ListingOption::create(['listing_id' => $locked->id, 'position' => $index + 1, 'name' => $option['name']]);

                foreach ($option['values'] as $position => $label) {
                    $valueIds[$index][$label] = ListingOptionValue::create(['option_id' => $row->id, 'position' => $position + 1, 'label' => $label])->id;
                }
            }

            $kept = 0;

            foreach (array_values($wanted) as $position => $labels) {
                $values = ['position' => $position + 1];

                foreach ([1, 2, 3] as $n) {
                    $values["value_{$n}"] = isset($labels[$n - 1]) ? $valueIds[$n - 1][$labels[$n - 1]] : null;
                }

                $variant = $current[self::key($labels)] ?? null;

                // 残る種類は、上でいったん消した扱いにしたので、値を入れて戻す（手元のモデルは消す前の形なので、DB へ直に書く）
                $variant === null
                    ? ListingVariant::create(['listing_id' => $locked->id] + $values)
                    : ListingVariant::withTrashed()->whereKey($variant->id)->update($values + ['deleted_at' => null, 'updated_at' => now()]);
                $kept++;
            }

            $locked->version++;
            $locked->save();

            $this->events->append('listing.variants_defined', 'listing', $locked->id, [
                'version' => $locked->version,
                'options' => array_map(static fn (array $o): array => ['name' => $o['name'], 'values' => count($o['values'])], $options),
                'variants' => $kept,
                'removed' => count($removed),
                'carried' => $carryTo !== null,
            ], $actor);

            return $locked;
        });
    }

    /**
     * 種類ごとの価格・品番・販売中かどうか・写真を変える。
     *
     * @param  array<string, mixed>  $changes  EDITABLE の項目だけ
     *
     * @throws StaleListingException
     * @throws ValidationException
     */
    public function update(ListingVariant $variant, int $expectedVersion, array $changes, ?string $actor = null): ListingVariant
    {
        if (array_diff(array_keys($changes), self::EDITABLE) !== []) {
            throw new \InvalidArgumentException('変えられない項目があります: '.implode(', ', array_diff(array_keys($changes), self::EDITABLE)));
        }

        return DB::transaction(function () use ($variant, $expectedVersion, $changes, $actor): ListingVariant {
            $this->events->lock();
            $listing = $this->lockListing($variant->listing()->firstOrFail(), $expectedVersion);
            $locked = ListingVariant::query()->whereKey($variant->id)->where('listing_id', $listing->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('unit_amount', $changes) && $changes['unit_amount'] !== null && (! is_int($changes['unit_amount']) || $changes['unit_amount'] < 0)) {
                throw ValidationException::withMessages(['unit_amount' => '価格は 0 以上の整数です。']);
            }

            if (array_key_exists('status', $changes)) {
                $changes['status'] = $changes['status'] instanceof VariantStatus ? $changes['status'] : VariantStatus::from((string) $changes['status']);
            }

            // 写真はその商品のものだけ
            if (($changes['image_id'] ?? null) !== null
                && ! ListingImage::query()->whereKey($changes['image_id'])->where('listing_id', $listing->id)->exists()) {
                throw ValidationException::withMessages(['image_id' => 'この商品の写真ではありません。']);
            }

            $locked->fill($changes);
            $dirty = array_keys($locked->getDirty());

            if ($dirty === []) {
                return $locked;
            }

            $diff = [];

            foreach ($dirty as $field) {
                $from = $locked->getOriginal($field);
                $to = $locked->getAttribute($field);
                $diff[$field] = ['from' => $from instanceof \BackedEnum ? $from->value : $from, 'to' => $to instanceof \BackedEnum ? $to->value : $to];
            }

            $locked->save();
            $listing->version++;
            $listing->save();

            $this->events->append('listing.variant_updated', 'listing', $listing->id, ['version' => $listing->version, 'variant_id' => $locked->id, 'changes' => $diff], $actor);

            if (in_array('unit_amount', $dirty, true)) {
                event(new ListingPriceChanged($listing->id, $actor ?? 'system'));
            }

            return $locked;
        });
    }

    private function lockListing(Listing $listing, int $expectedVersion): Listing
    {
        $locked = Listing::query()->whereKey($listing->id)->lockForUpdate()->firstOrFail();

        if ($locked->version !== $expectedVersion) {
            throw new StaleListingException($locked->version);
        }

        return $locked;
    }

    /**
     * 軸と値の形を確かめる（名前は 1〜40 文字、前後の空白を落とす、重なりなし、軸は 3 つまで、組み合わせは 100 まで）
     *
     * @param  array<mixed>  $options
     * @return list<array{name: string, values: list<string>}>
     */
    private static function normalize(array $options): array
    {
        if (count($options) > ListingOption::MAX_PER_LISTING) {
            throw ValidationException::withMessages(['options' => '選択項目は '.ListingOption::MAX_PER_LISTING.' つまでです。']);
        }

        $result = [];
        $names = [];
        $total = 1;

        foreach (array_values($options) as $i => $option) {
            $name = is_array($option) ? self::label($option['name'] ?? null) : null;
            $values = is_array($option) && is_array($option['values'] ?? null) ? array_map(self::label(...), array_values($option['values'])) : [];

            if ($name === null || in_array($name, $names, true)) {
                throw ValidationException::withMessages(["options.{$i}.name" => '選択項目の名前は 1〜40 文字で、重複しないものにしてください。']);
            }

            if ($values === [] || in_array(null, $values, true) || count(array_unique($values)) !== count($values) || count($values) > self::MAX_VALUES_PER_OPTION) {
                throw ValidationException::withMessages(["options.{$i}.values" => '値は 1〜40 文字で、重複しないものを 1〜'.self::MAX_VALUES_PER_OPTION.' 個にしてください。']);
            }

            $total *= count($values);
            $names[] = $name;
            /** @var list<string> $values */
            $result[] = ['name' => $name, 'values' => $values];
        }

        if ($total > self::MAX_VARIANTS) {
            throw ValidationException::withMessages(['options' => '種類（組み合わせ）は '.self::MAX_VARIANTS.' までです。']);
        }

        return $result;
    }

    private static function label(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        // 制御文字と文字の向きを変える印は断る（ショップと管理画面で見た目をすり替えられないように）
        if ($value === '' || mb_strlen($value) > 40 || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
            return null;
        }

        return $value;
    }

    /**
     * 値の組み合わせ（最初の軸が外側）。軸が無ければ空の組み合わせ 1 つ
     *
     * @param  list<array{name: string, values: list<string>}>  $options
     * @return list<list<string>>
     */
    private static function combinations(array $options): array
    {
        $result = [[]];

        foreach ($options as $option) {
            $next = [];

            foreach ($result as $prefix) {
                foreach ($option['values'] as $value) {
                    $next[] = [...$prefix, $value];
                }
            }

            $result = $next;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function labelsOf(ListingVariant $variant): array
    {
        $ids = $variant->valueIds();
        $labels = ListingOptionValue::query()->whereIn('id', $ids)->pluck('label', 'id');

        return array_map(static fn (string $id): string => (string) ($labels[$id] ?? ''), $ids);
    }

    /**
     * @param  list<string>  $labels
     */
    private static function key(array $labels): string
    {
        return json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, ListingVariant>  $current
     */
    private function labelFor(array $current, ListingVariant $variant): string
    {
        $key = array_search($variant, $current, true);

        return is_string($key) ? implode(' / ', json_decode($key, true, flags: JSON_THROW_ON_ERROR)) ?: '選択項目の無い種類' : '';
    }
}
