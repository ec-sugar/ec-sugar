<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\Plugin\Contracts\MemberBenefitsHandler;
use App\Core\Plugin\Services\PluginStates;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * 会員の特典（ADR 0079）の核の側。会員の特典の口（MemberBenefitsHandler）を持つ App に聞く窓口。
 *
 * - 会員価格（discountFor）: 値引きの候補に 1 つ足す（AutomaticDiscounts が自動の割引・クーポンと比べ、いちばん得な 1 つを効かせる）
 * - ポイントの率の上乗せ（pointBonusBp）: 店がポイントを使っている時だけ、店の率に足す（PointSettings::rateBpFor）
 * - 見せない商品（hiddenListings）: ショップの窓口（Catalog::published）が一覧・検索・商品の画面・カート・注文の確定から除く
 *
 * 会員価格と率は、App が落ちたら無し（定価・店の率で売る。注文の確定は確かめの画面の指紋が違って止まる）。
 * 見せない商品は、App が落ちたら誤りをそのまま上げる（会員だけの商品を誰にでも見せるより、画面の誤りの方がよい）
 */
final class MemberBenefits
{
    /** 上乗せの率の上限（万分率。10%） */
    public const int MAX_BONUS_BP = 1000;

    /** 見せない商品の数の上限（ショップの読み出しのたびに除くので、数を抑える。App も同じ数で断る） */
    public const int MAX_HIDDEN = 1000;

    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    /**
     * 会員の特典の口を持つ App（入っていなければ null）と、この店で使っているか
     *
     * @return array{0: MemberBenefitsHandler, 1: bool}|null
     */
    private function handler(): ?array
    {
        $plugin = $this->plugins->memberBenefitsPlugin();

        return $plugin?->memberBenefits === null || tenant() === null ? null : [$plugin->memberBenefits, $this->states->enabled($plugin->name)];
    }

    /**
     * ログインした会員の会員価格（ゲスト・App を使っていない・特典が無ければ null）
     */
    public function discountFor(?string $customerAccountId): ?MemberDiscount
    {
        [$handler, $enabled] = $this->handler() ?? [null, false];

        if ($customerAccountId === null || $handler === null || ! $enabled) {
            return null;
        }

        try {
            return $handler->discountFor($customerAccountId);
        } catch (Throwable $e) {
            Log::error('会員価格を読めなかった', ['error' => $e::class]);

            return null;
        }
    }

    /**
     * ログインした会員のポイントの率の上乗せ（万分率。0〜MAX_BONUS_BP）
     */
    public function pointBonusBp(?string $customerAccountId): int
    {
        [$handler, $enabled] = $this->handler() ?? [null, false];

        if ($customerAccountId === null || $handler === null || ! $enabled) {
            return 0;
        }

        try {
            return max(0, min(self::MAX_BONUS_BP, $handler->pointBonusBp($customerAccountId)));
        } catch (Throwable $e) {
            Log::error('ポイントの率の上乗せを読めなかった', ['error' => $e::class]);

            return 0;
        }
    }

    /**
     * 見る人に見せない商品の ID（App が入っていなければ空）。$customerAccountId はログインした会員の台帳（ゲストは null）
     *
     * @return list<string>
     */
    public function hiddenListings(?string $customerAccountId): array
    {
        [$handler, $enabled] = $this->handler() ?? [null, false];

        if ($handler === null) {
            return [];
        }

        $ids = $handler->hiddenListings($customerAccountId, $enabled);

        if (count($ids) > self::MAX_HIDDEN) {
            throw new InvalidArgumentException('見せない商品が多すぎる');
        }

        return array_values(array_filter($ids, static fn (string $id): bool => preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) === 1));
    }

    /**
     * その商品を、その人に見せないか
     */
    public function hides(string $listingId, ?string $customerAccountId): bool
    {
        return in_array($listingId, $this->hiddenListings($customerAccountId), true);
    }

    /**
     * 管理画面のお客さんの画面に出す一言（ランクの名前など）。App を使っていない・無ければ null
     */
    public function badgeFor(string $customerAccountId): ?string
    {
        [$handler, $enabled] = $this->handler() ?? [null, false];

        if ($handler === null || ! $enabled) {
            return null;
        }

        try {
            $badge = $handler->badgeFor($customerAccountId);
        } catch (Throwable $e) {
            Log::error('お客さんの画面の一言を読めなかった', ['error' => $e::class]);

            return null;
        }

        return $badge === null || $badge === '' || preg_match('/[\p{Cc}\p{Cf}]/u', $badge) === 1 ? null : mb_substr($badge, 0, 20);
    }
}
