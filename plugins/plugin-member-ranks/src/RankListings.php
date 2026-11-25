<?php

declare(strict_types=1);

namespace EcSugar\PluginMemberRanks;

use App\Core\Event\EventStore;
use App\Core\Listing\Models\Listing;
use App\Core\Plugin\MemberBenefits;
use EcSugar\PluginMemberRanks\Models\RankListing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 会員だけ・ランクだけの商品（ADR 0079）。決めるのは管理画面の商品の画面の欄（RankListingPanel。運用の段階から）。
 * 書き込みは EventStore::lock() → 行の錠 → 同じトランザクションでイベント（member_rank.listing_restricted。中身はランクの下限だけ）。
 * 決めた商品は、見てよくない人には一覧・検索・商品の画面・カート・注文の確定・フィード・サイトマップから無い商品になる（核の Catalog が除く）
 */
final class RankListings
{
    /** 誰にでも見せる（決まりを外す） */
    public const string EVERYONE = 'everyone';

    /** 会員だけ */
    public const string MEMBERS = 'members';

    public function __construct(private readonly EventStore $events) {}

    /**
     * 見る人に見せない商品の ID。App を使っていない時は、決めた商品の全部を誰にも見せない（使うのをやめた時に、会員だけの商品を誰にでも見せない）
     *
     * @param  int  $viewerRank  見る人のランク（ゲスト・会員でない人は -1、ランクなしの会員は 0）
     * @return list<string>
     */
    public function hiddenFrom(int $viewerRank, bool $enabled): array
    {
        return RankListing::query()
            ->when($enabled, static fn ($q) => $q->where('min_rank', '>', $viewerRank))
            ->orderBy('listing_id')->limit(MemberBenefits::MAX_HIDDEN)->pluck('listing_id')->map(static fn (mixed $id): string => (string) $id)->all();
    }

    /**
     * 商品の見せ方の今の値（everyone・members・rank1〜rank3）
     */
    public function visibility(string $listingId): string
    {
        $min = RankListing::query()->whereKey($listingId)->value('min_rank');

        return $min === null ? self::EVERYONE : ((int) $min === 0 ? self::MEMBERS : 'rank'.(int) $min);
    }

    /**
     * 商品の見せ方を決める。形が違えば ValidationException（欄の名前で）
     */
    public function save(Listing $listing, string $visibility, string $actor): void
    {
        $min = match ($visibility) {
            self::EVERYONE => null,
            self::MEMBERS => 0,
            'rank1' => 1,
            'rank2' => 2,
            'rank3' => 3,
            default => throw ValidationException::withMessages(['visibility' => '表示する相手を選び直してください。']),
        };

        DB::transaction(function () use ($listing, $min, $actor): void {
            $this->events->lock();
            $row = RankListing::query()->whereKey($listing->id)->lockForUpdate()->first();

            if (($row === null && $min === null) || ($row !== null && $row->min_rank === $min)) {
                return;
            }

            if ($min === null) {
                RankListing::query()->whereKey($listing->id)->delete();
            } else {
                // 見せない商品の数の上限（ショップの読み出しのたびに除くので。核も同じ数で見張る）
                if ($row === null && RankListing::query()->count() >= MemberBenefits::MAX_HIDDEN) {
                    throw ValidationException::withMessages(['visibility' => '会員限定・ランク限定の商品は '.number_format(MemberBenefits::MAX_HIDDEN).' 点までです。']);
                }

                RankListing::query()->updateOrCreate(['listing_id' => $listing->id], ['min_rank' => $min]);
            }

            $this->events->append('member_rank.listing_restricted', 'listing', $listing->id, ['min_rank' => $min], $actor);
        });
    }
}
