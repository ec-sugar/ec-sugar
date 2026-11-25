<?php

declare(strict_types=1);

namespace EcSugar\PluginBundle;

use App\Core\Plugin\Contracts\StockComponentsProvider;
use EcSugar\PluginBundle\Models\BundleComponent;
use Illuminate\Validation\ValidationException;

/**
 * 商品の画面の欄の「セットの中身」の書き方。品番と 1 セットあたりの数を「品番*数」で、コンマで区切って並べる。
 * 核の商品の画面の欄は文・切り替え・選ぶの 3 つしか持たず、選ぶ欄の選択肢は 20 個までなので、
 * 商品を選ばせるのではなく**品番で書く**（店の人がふだん使う言葉で、商品が何千あっても書ける）。
 * 品番を付けていない品は、種類の ID（26 文字）でも書ける（管理画面の案内がその ID を見せる）
 */
final class BundleParts
{
    /** 欄の書き方（画面の案内に出す） */
    public const string FORMAT = '品番*数, 品番*数';

    /** 欄の見本 */
    public const string EXAMPLE = 'COFFEE-200G*2, CUP-WHITE*1';

    /** 1 つ分（品番と数）。品番は英数字と . _ - の 64 文字まで、数は 1〜999 */
    private const string ONE = '[0-9A-Za-z._\-]{1,64}\*[1-9][0-9]{0,2}';

    /** 欄の形の決まり（核が \A(?:…)\z で全体に当てる）。コンマの後の空白は 1 つまで */
    public const string PATTERN = self::ONE.'(, ?'.self::ONE.')*';

    /** 欄の長さの上限（核の上限は 200 文字） */
    public const int MAX = 200;

    /**
     * 欄の値を「品番（か種類の ID） => 1 セットあたりの数」に。空なら空の配列（セットをやめる）
     *
     * @return array<string, int>
     *
     * @throws ValidationException 形が違う・同じ品番を 2 回書いた・数が範囲の外
     */
    public static function parse(?string $value): array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        $out = [];

        foreach (explode(',', $value) as $piece) {
            $piece = trim($piece);
            $parts = explode('*', $piece);

            if (count($parts) !== 2 || preg_match('/\A[0-9A-Za-z._\-]{1,64}\z/', $parts[0]) !== 1 || preg_match('/\A[1-9][0-9]{0,2}\z/', $parts[1]) !== 1) {
                throw ValidationException::withMessages(['parts' => 'セットの中身は「'.self::FORMAT.'」の形で書いてください（例: '.self::EXAMPLE.'）。']);
            }

            if (isset($out[$parts[0]])) {
                throw ValidationException::withMessages(['parts' => "品番「{$parts[0]}」を 2 回書いています。"]);
            }

            $out[$parts[0]] = (int) $parts[1];
        }

        if (count($out) > StockComponentsProvider::MAX_COMPONENTS) {
            throw ValidationException::withMessages(['parts' => '構成の品は '.StockComponentsProvider::MAX_COMPONENTS.' つまでです。']);
        }

        return $out;
    }

    /**
     * 今の中身を欄の形に戻す（品番が無い品は種類の ID で書く）
     */
    public static function field(string $listingId): ?string
    {
        $pieces = [];

        foreach (BundleStock::parts($listingId) as $part) {
            $pieces[] = ($part['sku'] ?? $part['variant']).'*'.$part['quantity'];
        }

        return $pieces === [] ? null : implode(', ', $pieces);
    }

    /**
     * セットの中身を店の人に見せる文（商品の画面の案内と App の画面）
     */
    public static function words(string $listingId): string
    {
        $pieces = [];

        foreach (BundleStock::parts($listingId) as $part) {
            $pieces[] = $part['title'].' ×'.$part['quantity'].($part['sellable'] ? '' : '（今は販売できない品）');
        }

        return implode('、', $pieces);
    }

    /**
     * その商品がセットか（構成の品が 1 つ以上ある）
     */
    public static function isBundle(string $listingId): bool
    {
        return BundleComponent::query()->where('listing_id', $listingId)->exists();
    }
}
