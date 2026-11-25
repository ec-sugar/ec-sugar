<?php

declare(strict_types=1);

namespace App\Storefront\Access;

use App\Storefront\Http\Middleware\SecretLink;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * 秘密の道筋（URL に署名か合い言葉を持つショップの画面）の URL を作り、署名を確かめる核の部品（ADR 0081）。
 * 署名つきの URL を作るのも確かめるのもここだけ（ここの外で URL::temporarySignedRoute・hasValidRelativeSignature などを使うとテストが落ちる）。
 *
 * - 作る時: 行き先の経路が秘密の道筋の宣言（SecretLink）を持たなければ作らない（宣言を付け忘れた画面へ秘密を載せない）。
 *   署名には店の ID（shop）を必ず入れ、期限は必ず付け、MAX_DAYS 日まで（期限の無い・長すぎる署名を作らない）
 * - 確かめる時: 署名が合う（道筋と問い合わせの全部。書き換えると合わない）・期限の中・店の ID が今の店
 * - 署名はパスにだけ掛ける（キューの処理の中ではショップのドメインを Laravel が知らないため。メールには呼ぶ側がショップの URL を前に付ける）
 */
final class SecretLinks
{
    /** 署名つきの URL の期限の上限（日）。ご注文の確認のメールのリンク（30 日）が今いちばん長い */
    public const int MAX_DAYS = 31;

    /**
     * 店の ID を入れずに作っていた頃の署名を受ける経路（前に送ったメールのリンク。期限は 30 日）。
     * 2026-12-08 より後（前に送ったリンクが切れた後）は消す
     */
    private const array WITHOUT_SHOP_UNTIL = [
        'storefront.orders.show' => '2026-12-08',
        'storefront.plugin.gift.order' => '2026-12-08',
    ];

    /**
     * 署名つきの道筋（パスと問い合わせ。頭のドメインは付けない）。店の文脈の中で呼ぶ
     *
     * @param  string  $route  経路の名前（秘密の道筋の宣言を持つもの。名前は文字で書く。テストが名前を集めて宣言を確かめる）
     * @param  array<string, string|int>  $parameters
     */
    public static function signed(string $route, array $parameters, \DateTimeInterface $expires): string
    {
        self::assertDeclared($route);
        $shop = tenant()?->getTenantKey();

        if (! is_string($shop) || $shop === '') {
            throw new \LogicException('店の文脈の外で、署名つきの URL は作れません');
        }

        $now = Carbon::now();

        if ($expires <= $now || $expires > $now->copy()->addDays(self::MAX_DAYS)) {
            throw new \InvalidArgumentException('署名つきの URL の期限は、今より後で '.self::MAX_DAYS.' 日以内にします');
        }

        return URL::temporarySignedRoute($route, $expires, [...$parameters, 'shop' => $shop], absolute: false);
    }

    /**
     * 署名を受け口が自分で作る秘密の道筋（用途ごとの署名を問い合わせに載せるプレビューなど）。宣言を確かめてから道筋を返す
     *
     * @param  array<string, string|int>  $parameters
     */
    public static function path(string $route, array $parameters): string
    {
        self::assertDeclared($route);

        return route($route, $parameters, false);
    }

    /**
     * 署名つきの道筋で来て、署名が合い、期限の中で、この店のものか
     */
    public static function valid(Request $request): bool
    {
        if (! is_string($request->query('signature')) || ! $request->hasValidRelativeSignature()) {
            return false;
        }

        $shop = $request->query('shop');

        if ($shop === null) {
            $until = self::WITHOUT_SHOP_UNTIL[(string) $request->route()?->getName()] ?? null;

            return $until !== null && Carbon::now()->lt(Carbon::parse($until));
        }

        return is_string($shop) && hash_equals((string) tenant()?->getTenantKey(), $shop);
    }

    /**
     * 経路が秘密の道筋の宣言を持つか（無い名前も偽）
     */
    public static function declared(string $route): bool
    {
        $routes = app(Router::class)->getRoutes();

        if ($routes->getByName($route) === null) {
            // 起動の後に足した経路（名前は足した後に付く）も探せるように、名前の索引を作り直す
            $routes->refreshNameLookups();
        }

        return SecretLink::declaredOn($routes->getByName($route));
    }

    private static function assertDeclared(string $route): void
    {
        if (! self::declared($route)) {
            throw new \LogicException("経路 {$route} に秘密のリンクの宣言（->middleware('secret-link')）がありません。署名やトークンを載せる前に宣言してください");
        }
    }
}
