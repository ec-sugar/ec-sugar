<?php

declare(strict_types=1);

namespace App\Core\Listing\Services;

use App\Core\Event\EventStore;
use App\Core\Listing\Enums\AddonKind;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingAddon;
use App\Core\Tax\TaxClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 商品のオプション（名入れ・ギフト包装など。ADR 0036）の編集。変更はここを通し、ドメインイベントを残す。
 *
 * - 書き込みは EventStore::lock() → 商品の行ロック。商品のバージョン（楽観ロック）を進める
 * - 並び全体を決め直す。今のオプションは消し（取引の行には名前と額が写っているので記録は残る）、新しく作る
 * - カートに入っている選び方は、注文のときに今の定義で照らし直す（無くなった・変わったものは注文の前に知らせる）
 */
final class AddonService
{
    public function __construct(private readonly EventStore $events) {}

    /**
     * @param  list<array<string, mixed>>  $addons  [{kind, name, required?, amount?, choices?: [{label, amount}], max_length?, tax_class?}]
     *
     * @throws StaleListingException
     * @throws ValidationException
     */
    public function define(Listing $listing, int $expectedVersion, array $addons, ?string $actor = null): Listing
    {
        $rows = self::normalize($addons);

        return DB::transaction(function () use ($listing, $expectedVersion, $rows, $actor): Listing {
            $this->events->lock();
            $locked = Listing::query()->whereKey($listing->id)->lockForUpdate()->firstOrFail();

            if ($locked->version !== $expectedVersion) {
                throw new StaleListingException($locked->version);
            }

            ListingAddon::query()->where('listing_id', $locked->id)->delete();

            foreach ($rows as $position => $row) {
                ListingAddon::create(['listing_id' => $locked->id, 'position' => $position + 1] + $row);
            }

            $locked->version++;
            $locked->save();

            $this->events->append('listing.addons_defined', 'listing', $locked->id, [
                'version' => $locked->version,
                'addons' => array_map(static fn (array $row): array => ['kind' => $row['kind'], 'name' => $row['name']], $rows),
            ], $actor);

            return $locked;
        });
    }

    /**
     * @param  array<mixed>  $addons
     * @return list<array{kind: string, name: string, required: bool, amount: int, choices: list<array{label: string, amount: int}>|null, max_length: int|null, tax_class: string|null}>
     */
    private static function normalize(array $addons): array
    {
        if (count($addons) > ListingAddon::MAX_PER_LISTING) {
            throw ValidationException::withMessages(['addons' => 'オプションは '.ListingAddon::MAX_PER_LISTING.' 個までです。']);
        }

        $rows = [];
        $names = [];

        foreach (array_values($addons) as $i => $addon) {
            $addon = is_array($addon) ? $addon : [];
            $kind = AddonKind::tryFrom(is_string($addon['kind'] ?? null) ? $addon['kind'] : '');
            $name = self::label($addon['name'] ?? null);

            if ($kind === null) {
                throw ValidationException::withMessages(["addons.{$i}.kind" => 'オプションの種類は「選択式」「文字入力」「あり・なし」のどれかです。']);
            }

            if ($name === null || in_array($name, $names, true)) {
                throw ValidationException::withMessages(["addons.{$i}.name" => 'オプションの名前は 1〜40 文字で、重複しないものにしてください。']);
            }

            $names[] = $name;
            $required = ($addon['required'] ?? false) === true;

            // 付けるは「付けない」も選び方の 1 つなので、必須にしない（必須にすると、付けないとカートに入れられない）
            if ($kind === AddonKind::Toggle && $required) {
                throw ValidationException::withMessages(["addons.{$i}.required" => '「あり・なし」のオプションは必須にできません。']);
            }

            $taxClass = $addon['tax_class'] ?? null;

            // 税区分は空（商品と同じ）か、核の区分のどれか（軽減の商品に付ける別料金の包装は標準。国税庁の案内）
            if ($taxClass !== null && (! is_string($taxClass) || TaxClass::tryFrom($taxClass) === null)) {
                throw ValidationException::withMessages(["addons.{$i}.tax_class" => '税区分は「商品と同じ」「標準 10%」「軽減 8%」のどれかです。']);
            }

            $row = ['kind' => $kind->value, 'name' => $name, 'required' => $required, 'amount' => 0, 'choices' => null, 'max_length' => null, 'tax_class' => $taxClass];

            if ($kind === AddonKind::Choice) {
                $choices = is_array($addon['choices'] ?? null) ? array_values($addon['choices']) : [];
                $row['choices'] = [];

                foreach ($choices as $j => $choice) {
                    $label = self::label(is_array($choice) ? ($choice['label'] ?? null) : null);
                    $amount = is_array($choice) ? ($choice['amount'] ?? 0) : null;

                    if ($label === null || ! self::amountOk($amount) || in_array($label, array_column($row['choices'], 'label'), true)) {
                        throw ValidationException::withMessages(["addons.{$i}.choices.{$j}" => '選択肢は、1〜40 文字の重複しない名前と、0 以上の額にしてください。']);
                    }

                    /** @var int $amount */
                    $row['choices'][] = ['label' => $label, 'amount' => $amount];
                }

                if ($row['choices'] === [] || count($row['choices']) > ListingAddon::MAX_CHOICES) {
                    throw ValidationException::withMessages(["addons.{$i}.choices" => '選択肢は 1〜'.ListingAddon::MAX_CHOICES.' 個です。']);
                }
            } else {
                $amount = $addon['amount'] ?? 0;

                if (! self::amountOk($amount)) {
                    throw ValidationException::withMessages(["addons.{$i}.amount" => '追加料金は 0 以上の整数で入力してください。']);
                }

                /** @var int $amount */
                $row['amount'] = $amount;
            }

            if ($kind === AddonKind::Text) {
                $max = $addon['max_length'] ?? null;

                if (! is_int($max) || $max < 1 || $max > ListingAddon::MAX_TEXT_LENGTH) {
                    throw ValidationException::withMessages(["addons.{$i}.max_length" => '入力できる文字数の上限は 1〜'.ListingAddon::MAX_TEXT_LENGTH.' 文字です。']);
                }

                $row['max_length'] = $max;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private static function amountOk(mixed $amount): bool
    {
        return is_int($amount) && $amount >= 0 && $amount <= 10_000_000;
    }

    private static function label(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        // 制御文字と文字の向きを変える印は断る（ショップと管理画面で見た目をすり替えられないように）
        return $value === '' || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 40 || preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $value) !== 0 ? null : $value;
    }
}
