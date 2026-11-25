<?php

declare(strict_types=1);

namespace EcSugar\PluginCartReminder;

use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartException;
use App\Storefront\Cart\CartLine;
use App\Storefront\Cart\CartService;
use App\Storefront\Catalog\Catalog;
use Carbon\CarbonImmutable;
use EcSugar\PluginCartReminder\Models\CartReminder;

/**
 * 「カートに戻す」のリンク（ADR 0067）。合い言葉の合うお知らせの注文の商品を、今の値段と在庫でカートに入れ直す。
 * カートを変えるのは核のカートの窓口（CartService）だけで、入れられるかの決まり（公開中・販売中・在庫・1 種類 10 個）も核が決める。
 *
 * - 合い言葉は sha256 で引く（この店の DB の中だけ。ほかの店のリンクは見つからない）。送った（送っている）お知らせで、期限（7 日）のうちだけ
 * - 今買えない種類（売り切れ・非公開・販売を終えた）は外して名前を返す。数は在庫と 1 種類の上限まで
 * - ブラウザにカートがあれば、そのカートに足す（もう入っている種類は足さない。2 回開いても数が増えない）。無ければ作る
 * - オプション（名入れ・ギフト包装など）は戻さない（入れた文字は店の鍵で暗号化した注文の値で、メールのリンクから戻さない）。付けていたら一言
 * - 初めて開いた日時を残す（管理画面の「リンクから戻った数」）
 */
final class CartRestore
{
    public function __construct(
        private readonly CartService $carts,
        private readonly Catalog $catalog,
        private readonly ReminderItems $items,
    ) {}

    /**
     * @return array{state: 'restored'|'empty'|'invalid'|'expired', cart: Cart|null, token: string|null, removed: list<string>, addons: bool}
     */
    public function restore(string $token, ?Cart $cart): array
    {
        $none = ['cart' => $cart, 'token' => null, 'removed' => [], 'addons' => false];
        $row = CartReminder::query()->where('token_hash', CartReminders::hash($token))->first();

        if ($row === null || ! in_array($row->status, [CartReminder::SENDING, CartReminder::SENT], true)) {
            return ['state' => 'invalid'] + $none;
        }

        $now = CarbonImmutable::now();

        if ($row->expires_at === null || ! $row->expires_at->isAfter($now)) {
            return ['state' => 'expired'] + $none;
        }

        CartReminder::query()->whereKey($row->id)->whereNull('returned_at')->update(['returned_at' => $now, 'updated_at' => $now]);

        $ordered = $this->items->ordered($row->transaction_id);
        $buyable = $this->catalog->purchasableVariants(array_keys($ordered));
        $removed = [];
        $wanted = [];
        $addons = false;

        foreach ($ordered as $variantId => $line) {
            $item = $buyable[$variantId] ?? null;
            $addons = $addons || $line['addons'];

            if ($item === null || $item['available'] < 1) {
                $removed[] = $line['title'];

                continue;
            }

            $wanted[$variantId] = ['quantity' => max(1, min($line['quantity'], $item['available'], CartService::MAX_QUANTITY)), 'title' => $item['card']->title];
        }

        if ($wanted === []) {
            return ['state' => 'empty', 'cart' => $cart, 'token' => null, 'removed' => $removed, 'addons' => $addons];
        }

        $newToken = null;

        if ($cart === null) {
            [$cart, $newToken] = $this->carts->create();
        }

        $inCart = CartLine::query()->where('cart_id', $cart->id)->pluck('variant_id')->all();
        $added = 0;

        foreach ($wanted as $variantId => $line) {
            if (in_array($variantId, $inCart, true)) {
                $added++;

                continue;
            }

            try {
                $this->carts->add($cart, $variantId, $line['quantity']);
                $added++;
            } catch (CartException) {
                // カートの決まり（20 種類まで・0 円の品は 1 点まで・在庫の取り合い）で入らなかった
                $removed[] = $line['title'];
            }
        }

        return ['state' => $added > 0 ? 'restored' : 'empty', 'cart' => $cart, 'token' => $newToken, 'removed' => $removed, 'addons' => $addons];
    }
}
