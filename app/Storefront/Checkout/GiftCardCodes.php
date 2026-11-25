<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use App\Core\GiftCard\Models\GiftCard;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\RateLimit\Throttle;
use App\Storefront\Access\ClientSource;
use App\Storefront\Cart\CartGiftCards;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * 注文の入力で入れたギフトカードのコードを探す（ADR 0076）。総当たりの守り:
 * - 接続元ごとに 10 分に 10 回まで（見つかった・見つからないを問わず数える。先に足してから比べるので、同時に送っても上限を超えない）
 * - 店全体の見つからない回数を数え、10 分に決めた数を超えたらログで知らせる（監視。店全体では断らない: 断ると、ほかのお客さんがカードを使えなくする嫌がらせになる。
 *   コードは 31 文字 × 16 で約 79 ビットあり、接続元ごとの上限の下では当てられない）
 * コードそのものはログ・セッション・記録に残さない（探すのはハッシュで）
 */
final class GiftCardCodes
{
    public const int PER_SOURCE = 10;

    public const int WINDOW_SECONDS = 600;

    /** 店全体の見つからない回数の知らせの線（10 分あたり） */
    public const int SHOP_FAILURE_ALERT = 50;

    public const string TOO_MANY = 'ギフトカードのコードの入力が続いたため、受け付けを一時的に止めています。しばらく時間をおいてから、もう一度お試しください。';

    public function __construct(
        private readonly GiftCardService $giftCards,
        private readonly CartGiftCards $cartGiftCards,
    ) {}

    /**
     * 使えるカード。見つからない・使えない・回数を超えたら ValidationException（欄 gift_card.code に理由）
     */
    public function lookup(?string $ip, string $input): GiftCard
    {
        if (! Throttle::allow('gift-card-code:ip:'.ClientSource::of($ip), self::PER_SOURCE, self::WINDOW_SECONDS)) {
            throw ValidationException::withMessages(['gift_card.code' => self::TOO_MANY]);
        }

        $card = $this->giftCards->find($input);

        if ($card === null) {
            $failures = Throttle::hit('gift-card-code:failures', self::WINDOW_SECONDS);

            if ($failures === self::SHOP_FAILURE_ALERT + 1) {
                Log::warning('ギフトカードのコードの見つからない入力が店全体で続いている（総当たりの疑い）', ['tenant' => tenant()?->getTenantKey(), 'failures' => $failures, 'window_seconds' => self::WINDOW_SECONDS]);
            }

            throw ValidationException::withMessages(['gift_card.code' => GiftCardService::NOT_FOUND]);
        }

        // テストの支払いで売ったカードは、本番の鍵の店では使えない（ADR 0076）
        $problem = $this->giftCards->problem($card, null, $this->cartGiftCards->liveShop());

        if ($problem !== null) {
            throw ValidationException::withMessages(['gift_card.code' => $problem]);
        }

        return $card;
    }

    /**
     * 注文の入力の「ギフトカード」の欄で、今使っているカード（核の部品 <x-storefront::gift-card-fields>。無ければ null）
     *
     * @param  array<string, mixed>  $saved  セッションに置いた入力
     * @return array{last4: string, balance: int, expiresAt: string}|null
     */
    public function inUse(array $saved): ?array
    {
        $id = $saved['gift_card_id'] ?? null;
        $card = is_string($id) ? GiftCard::query()->find($id) : null;

        return $card === null ? null : ['last4' => $card->last4, 'balance' => $this->giftCards->balance($card->id), 'expiresAt' => $card->expires_at->timezone('Asia/Tokyo')->format('Y/m/d')];
    }
}
