<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Discount\Services\AutomaticDiscounts;
use App\Core\Discount\Services\CouponService;
use App\Core\Listing\Addons\AddonSelection;
use App\Core\Listing\Models\Listing;
use App\Storefront\Cart\AddonInputFlash;
use App\Storefront\Cart\Cart;
use App\Storefront\Cart\CartCookie;
use App\Storefront\Cart\CartException;
use App\Storefront\Cart\CartService;
use App\Storefront\Catalog\Catalog;
use App\Storefront\View\StorefrontView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 店頭のカート（ADR 0021）。フォームでも JavaScript（JSON）でも同じ経路で動く。
 * カートを作るのは、初めて商品を入れたときだけ（見るだけでは DB に書かない）。書き込みは接続元ごとの回数制限つき。
 */
final class CartController
{
    public function __construct(
        private readonly CartService $carts,
        private readonly StorefrontView $view,
        private readonly Catalog $catalog,
    ) {}

    public function show(Request $request): Response|JsonResponse
    {
        $cart = $this->carts->view($this->carts->find(CartCookie::token($request)));

        return $request->expectsJson()
            ? response()->json(['cart' => $cart->toArray()])
            : $this->view->render('cart', ['cart' => $cart]);
    }

    public function add(Request $request): RedirectResponse|JsonResponse
    {
        $listingId = $request->input('listing_id');
        $variantId = $request->input('variant_id');
        $quantity = $this->parseQuantity($request);
        $cart = $this->carts->find(CartCookie::token($request));
        $token = null;

        if (! is_string($listingId) || $quantity === null) {
            return $this->fail($request, '数量は 1〜'.CartService::MAX_QUANTITY.' の範囲で指定してください。');
        }

        try {
            // 種類を選んでいなければ、軸の無い商品のただ 1 つの種類（ADR 0036）。選んだ種類はその商品のものだけ
            $variantId = is_string($variantId) && $variantId !== '' ? $variantId : $this->catalog->soleVariantId($listingId);

            if ($variantId === null) {
                throw new CartException('種類を選んでください。');
            }

            $this->assertAddable($listingId, $variantId);
            // オプション（名入れ・ギフト包装など）は、その商品の今の定義と照らす。額はサーバーで決める（ADR 0036）
            $input = $request->input('addons', []);

            try {
                $addons = AddonSelection::resolve(Listing::query()->findOrFail($listingId), is_array($input) ? $input : []);
            } catch (\InvalidArgumentException $e) {
                throw new CartException($e->getMessage());
            }

            if ($cart === null) {
                // 入れられる商品か確かめてから作る（入れられない商品で空のカートを増やさない）
                [$cart, $token] = $this->carts->create();
            }

            $this->carts->add($cart, $variantId, $quantity, $addons);
        } catch (CartException $e) {
            // 今作ったカートで断られたら、空のカートを残さない
            if ($token !== null) {
                $cart->delete();

                return $this->fail($request, $e->getMessage());
            }

            return $this->fail($request, $e->getMessage(), $cart);
        }

        return $this->done($request, 'カートに入れました。', $cart, $token);
    }

    public function quantity(Request $request, string $lineId): RedirectResponse|JsonResponse
    {
        $cart = $this->carts->find(CartCookie::token($request));
        $quantity = $this->parseQuantity($request, allowZero: true);

        if ($cart === null) {
            return $this->fail($request, 'カートが見つかりません。');
        }

        if ($quantity === null) {
            return $this->fail($request, '数量は 0〜'.CartService::MAX_QUANTITY.' の範囲で指定してください。', $cart);
        }

        try {
            $this->carts->setQuantity($cart, $lineId, $quantity);
        } catch (CartException $e) {
            return $this->fail($request, $e->getMessage(), $cart);
        }

        return $this->done($request, $quantity === 0 ? 'カートから削除しました。' : '数量を変更しました。', $cart);
    }

    public function remove(Request $request, string $lineId): RedirectResponse|JsonResponse
    {
        $cart = $this->carts->find(CartCookie::token($request));

        if ($cart !== null) {
            $this->carts->remove($cart, $lineId);
        }

        return $this->done($request, 'カートから削除しました。', $cart);
    }

    /**
     * クーポンのコードを入れる（ADR 0054）。回数の上限は経路の throttle:storefront-coupon（当てずっぽうを防ぐ）
     */
    public function coupon(Request $request): RedirectResponse|JsonResponse
    {
        $cart = $this->carts->find(CartCookie::token($request));
        $code = $request->input('code');

        if ($cart === null) {
            return $this->fail($request, 'カートが見つかりません。');
        }

        if (! is_string($code) || mb_strlen($code) > 64) {
            return $this->fail($request, CouponService::UNKNOWN, $cart);
        }

        try {
            $this->carts->applyCoupon($cart, $code);
        } catch (CartException $e) {
            return $this->fail($request, $e->getMessage(), $cart);
        }

        $view = $this->carts->view($cart);

        // 自動の割引が効いている・使っても合計が下がらない時は、コードを入れたまま知らせる（誤りではない。カートが変われば入れ替わる。ADR 0065）
        return match (true) {
            $view->couponNotice === null => $this->done($request, 'クーポンを適用しました。', $cart),
            AutomaticDiscounts::isSkipNotice($view->couponNotice) => $this->done($request, $view->couponNotice, $cart),
            default => $this->fail($request, $view->couponNotice, $cart),
        };
    }

    public function removeCoupon(Request $request): RedirectResponse|JsonResponse
    {
        $cart = $this->carts->find(CartCookie::token($request));

        if ($cart !== null) {
            $this->carts->removeCoupon($cart);
        }

        return $this->done($request, 'クーポンを解除しました。', $cart);
    }

    private function parseQuantity(Request $request, bool $allowZero = false): ?int
    {
        $value = $request->input('quantity', '1');

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = (string) $value;

        return ctype_digit($value) && (int) $value >= ($allowZero ? 0 : 1) && (int) $value <= CartService::MAX_QUANTITY ? (int) $value : null;
    }

    private function assertAddable(string $listingId, string $variantId): void
    {
        if (($this->catalog->purchasableVariants([$variantId])[$variantId]['listingId'] ?? null) !== $listingId) {
            throw new CartException('この商品は現在カートに入れられません。');
        }
    }

    private function done(Request $request, string $message, ?Cart $cart, ?string $token = null): RedirectResponse|JsonResponse
    {
        $response = $request->expectsJson()
            ? response()->json(['message' => $message, 'cart' => $this->carts->view($cart)->toArray()])
            : redirect()->route('storefront.cart')->with('cart_message', $message);

        return $token === null ? $response : $response->withCookie(CartCookie::make($request, $token));
    }

    private function fail(Request $request, string $error, ?Cart $cart = null, ?string $token = null): RedirectResponse|JsonResponse
    {
        $response = $request->expectsJson()
            ? response()->json(['message' => $error, 'cart' => $this->carts->view($cart)->toArray()], 422)
            : redirect()->back(fallback: route('storefront.cart'))->with('cart_error', $error);

        // 商品の画面に戻るときは、入れていたオプションの値を次の 1 回だけ残す（名入れは個人情報になりうるので暗号化して。withInput は平文なので使わない）
        $addons = $request->expectsJson() ? null : AddonInputFlash::wrap($request->input('listing_id'), $request->input('addons'));

        if ($addons !== null && $response instanceof RedirectResponse) {
            $response->with(AddonInputFlash::KEY, $addons);
        }

        return $token === null ? $response : $response->withCookie(CartCookie::make($request, $token));
    }
}
