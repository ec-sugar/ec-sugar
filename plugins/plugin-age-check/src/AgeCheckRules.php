<?php

declare(strict_types=1);

namespace EcSugar\PluginAgeCheck;

use App\Core\Plugin\AgeCheck\AgeRule;
use App\Core\Plugin\Contracts\AgeCheckHandler;
use App\Core\Plugin\Services\PluginSettings;
use EcSugar\PluginAgeCheck\Models\AgeCheckListing;
use EcSugar\PluginAgeCheck\Models\AgeCheckMember;
use Illuminate\Http\Request;

/**
 * 年齢の確かめの口（ADR 0077）の App の側: 商品ごとの年齢・会員が確かめた年齢・年齢の門。
 * 会員の年齢は「何歳以上か」だけで、上げる時だけ書く（核が注文の確定の錠の中で呼ぶ。記録は核の order.age_verified）
 */
final class AgeCheckRules implements AgeCheckHandler
{
    /** 年齢の門の答えを置く Cookie（答えた年齢。記録は残さない） */
    public const string GATE_COOKIE = 'sugar_age_gate';

    /**
     * @param  list<string>  $listingIds
     * @return array<string, int>
     */
    public function requirements(array $listingIds): array
    {
        return AgeCheckListing::query()->whereIn('listing_id', $listingIds)->pluck('age', 'listing_id')
            ->map(static fn (mixed $age): int => (int) $age)->all();
    }

    public function remembered(string $customerAccountId): int
    {
        return (int) (AgeCheckMember::query()->whereKey($customerAccountId)->value('age') ?? 0);
    }

    public function remember(string $customerAccountId, int $age, string $actor): void
    {
        if (! AgeRule::isAge($age)) {
            return;
        }

        // 行のロックの中で、今より上の時だけ上げる（同じ会員の同時の注文でも下げない。核の連鎖のロックの中でも呼ばれる）
        $row = AgeCheckMember::query()->whereKey($customerAccountId)->lockForUpdate()->first();

        if ($row === null) {
            AgeCheckMember::create(['customer_account_id' => $customerAccountId, 'age' => $age, 'verified_at' => now()]);
        } elseif ($row->age < $age) {
            $row->age = $age;
            $row->verified_at = now();
            $row->save();
        }
    }

    public function gate(int $age, string $returnPath, Request $request): ?string
    {
        if ((app(PluginSettings::class)->publicValues('age-check')['gate'] ?? false) !== true) {
            return null;
        }

        // もうこの年齢以上と答えた人には出さない
        $answered = $request->cookie(self::GATE_COOKIE);
        if (is_string($answered) && ctype_digit($answered) && (int) $answered >= $age) {
            return null;
        }

        return route('storefront.plugin.age-check.gate', ['age' => $age, 'return' => $returnPath], false);
    }

    /**
     * 退会したら、覚えた年齢を消す（退会と同じトランザクション）
     */
    public static function leave(string $customerAccountId): void
    {
        AgeCheckMember::query()->whereKey($customerAccountId)->delete();
    }
}
