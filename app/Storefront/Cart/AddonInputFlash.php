<?php

declare(strict_types=1);

namespace App\Storefront\Cart;

use App\Core\Listing\Models\ListingAddon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * カートに入れるのを断られて商品の画面に戻ったとき、入れていたオプションの値（名入れの文字など）を残す（ADR 0036）。
 *
 * - 次の 1 回の画面にだけ渡す（セッションの flash＝次のリクエストで消える値）。2 回目の画面には出ない
 * - 名入れの文字は個人情報になりうる。セッションの中では暗号化して置き（Crypt。セッションの保存先に平文で残さない）、記録（ログ）には出さない
 * - 商品の ID と一緒に包み、同じ商品の画面でだけ戻す（別の商品の画面に値を持ち込まない）
 * - 戻すのは欄の初めの値だけ。額と中身の確かめは、次に入れるときにサーバーがまたする
 */
final class AddonInputFlash
{
    public const string KEY = 'cart_addons';

    /**
     * 画面から来た値（addons[オプションの id]）を、大きさを抑えて暗号化した flash の値にする。残すものが無ければ null
     */
    public static function wrap(mixed $listingId, mixed $input): ?string
    {
        if (! is_string($listingId) || ! is_array($input)) {
            return null;
        }

        $values = self::clean($input);

        return $values === [] ? null : Crypt::encryptString(json_encode(['listing_id' => $listingId, 'values' => $values], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * flash の値を解いて、その商品の値（オプションの id → 値）を返す。別の商品のもの・壊れたものは空
     *
     * @return array<string, string>
     */
    public static function unwrap(mixed $flashed, string $listingId): array
    {
        if (! is_string($flashed) || $flashed === '') {
            return [];
        }

        try {
            $data = json_decode(Crypt::decryptString($flashed), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return [];
        }

        return is_array($data) && ($data['listing_id'] ?? null) === $listingId && is_array($data['values'] ?? null) ? self::clean($data['values']) : [];
    }

    /**
     * 文字の値だけを、オプションの上限の数と長さまで残す
     *
     * @param  array<mixed>  $input
     * @return array<string, string>
     */
    private static function clean(array $input): array
    {
        $values = [];

        foreach ($input as $id => $value) {
            if (count($values) >= ListingAddon::MAX_PER_LISTING) {
                break;
            }

            if (is_string($id) && strlen($id) <= 26 && is_string($value) && $value !== '' && mb_check_encoding($value, 'UTF-8')) {
                // 選ぶの値（「label:」＋ 40 文字）も入れる文字（100 文字まで）も収まる長さで切る
                $values[$id] = mb_substr($value, 0, ListingAddon::MAX_TEXT_LENGTH + 10);
            }
        }

        return $values;
    }
}
