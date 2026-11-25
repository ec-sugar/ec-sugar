<?php

declare(strict_types=1);

namespace EcSugar\PluginAgeCheck;

use App\Core\Event\EventStore;
use App\Core\Listing\Models\Listing;
use App\Core\Plugin\AgeCheck\AgeRule;
use EcSugar\PluginAgeCheck\Models\AgeCheckListing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 商品ごとの年齢の確かめの決まりを変える（ADR 0077）。変えるのはここだけ: 連鎖のロック → 商品の決まりの行のロック → イベント（同じトランザクション）。
 * 要るにする（年齢を選ぶ。既定は 20 歳）・年齢を変える・要らなくする（行を消す）。何も変わらなければ記録に残さない
 */
final class AgeCheckListings
{
    public function __construct(private readonly EventStore $events) {}

    /**
     * @throws ValidationException
     */
    public function save(Listing $listing, bool $required, ?int $age, string $actor): void
    {
        $age ??= AgeRule::DEFAULT_AGE;

        if ($required && ! AgeRule::isAge($age)) {
            throw ValidationException::withMessages(['age' => '年齢は 20 歳・18 歳・15 歳から選んでください。']);
        }

        DB::transaction(function () use ($listing, $required, $age, $actor): void {
            $this->events->lock();
            $row = AgeCheckListing::query()->whereKey($listing->id)->lockForUpdate()->first();

            if (! $required) {
                if ($row !== null) {
                    $row->delete();
                    $this->events->append('age_check.listing_cleared', 'listing', $listing->id, [], $actor);
                }

                return;
            }

            if ($row !== null && $row->age === $age) {
                return;
            }

            AgeCheckListing::query()->updateOrCreate(['listing_id' => $listing->id], ['age' => $age]);
            $this->events->append('age_check.listing_set', 'listing', $listing->id, ['age' => $age], $actor);
        });
    }
}
