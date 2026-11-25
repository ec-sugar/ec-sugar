<?php

declare(strict_types=1);

namespace App\Admin\Http\Requests;

use App\Core\Listing\Description\ProductDescription;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\ListingKinds;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Seo\SeoText;
use App\Core\Tax\TaxClass;
use Illuminate\Validation\Rule;

/**
 * 出品物の項目の検査（登録と編集で共通）。DB の制約（テナントの移行 2026_10_27_000100）と同じ範囲に絞る。
 * 説明のブロック・仕様の表・短い説明は、ここでは形と個数だけを見て（重い確かめの前に大きさを抑える）、中は核（ProductDescription）が道筋ごとに確かめる（ADR 0032）。
 */
final class ListingRules
{
    // JavaScript の数値で正確に扱える範囲に収める（1 兆円まで）
    public const int MAX_AMOUNT = 1_000_000_000_000;

    /**
     * この店で選べる商品の扱い（種類）。App が足した種類（チケットなど）は、その App を使う店だけ
     *
     * @return list<string>
     */
    public static function kinds(): array
    {
        $kinds = app(ListingKinds::class);
        $states = app(PluginStates::class);
        // 店の外（テナントに入っていない所。入力の検査を単独で使う時など）では、App の種類は選べないとする（店の DB の App の状態を引かない）
        $inShop = tenancy()->initialized;

        return array_values(array_filter($kinds->names(), static fn (string $kind): bool => ($plugin = $kinds->get($kind)->plugin) === null || ($inShop && $states->enabled($plugin))));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function fields(string $presence): array
    {
        return [
            'title' => [$presence, 'string', 'max:200', 'not_regex:/\A\s*\z/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'unit_amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT],
            'sku' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/'],
            'status' => ['sometimes', Rule::enum(ListingStatus::class)],
            'description_blocks' => ['sometimes', 'array', 'list', 'max:'.ProductDescription::MAX_BLOCKS],
            'specs' => ['sometimes', 'array', 'list', 'max:'.ProductDescription::MAX_SPECS],
            'summary' => ['sometimes', 'nullable', 'string', 'max:'.ProductDescription::MAX_SUMMARY],
            // 検索の結果に出す題と説明（ADR 0061）。空は自動
            ...SeoText::rules(),
            // 消費税の区分（ADR 0063）。率そのものは受け取らない（区分から核が決める）
            'tax_class' => ['sometimes', 'string', Rule::enum(TaxClass::class)],
            // 配送の方法（空ならいちばん上の方法）と送料無料の商品（ADR 0064）。方法がこの店にあるかは核が確かめる
            'shipping_method_id' => ['sometimes', 'nullable', 'string', 'regex:/\A[0-9a-z]{26}\z/'],
            'free_shipping' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * 誤りの文に出す欄の呼び名（無いと「titleを入力してください。」と欄の名前のまま出る）。
     * 登録・編集・種類の FormRequest が attributes() で返す。
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'version' => 'バージョン',
            'seller_id' => '販売者',
            'kind' => '商品の扱い',
            'title' => '商品名',
            'description' => '説明',
            'unit_amount' => '価格',
            'sku' => '品番',
            'status' => '状態',
            'description_blocks' => '説明のブロック',
            'specs' => '仕様の表',
            'summary' => '短い説明',
            'tax_class' => '税率',
            'image_id' => '写真',
            ...SeoText::attributes(),
            'shipping_method_id' => '配送の方法',
            'free_shipping' => '送料無料',
        ];
    }

    /**
     * 検査済みの値を ListingService に渡す形にする。
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function toChanges(array $validated): array
    {
        $changes = array_intersect_key($validated, array_flip(['title', 'description', 'unit_amount', 'sku', 'status', 'description_blocks', 'specs', 'summary', 'seo_title', 'seo_description', 'tax_class', 'shipping_method_id', 'free_shipping']));

        if (array_key_exists('free_shipping', $changes)) {
            $changes['free_shipping'] = (bool) $changes['free_shipping'];
        }

        if (isset($changes['title']) && is_string($changes['title'])) {
            $changes['title'] = trim($changes['title']);
        }

        if (array_key_exists('unit_amount', $changes) && $changes['unit_amount'] !== null) {
            $changes['unit_amount'] = (int) $changes['unit_amount'];
        }

        if (isset($changes['status']) && is_string($changes['status'])) {
            $changes['status'] = ListingStatus::from($changes['status']);
        }

        if (isset($changes['tax_class']) && is_string($changes['tax_class'])) {
            $changes['tax_class'] = TaxClass::from($changes['tax_class']);
        }

        foreach (['description', 'sku'] as $field) {
            if (array_key_exists($field, $changes) && $changes[$field] === '') {
                $changes[$field] = null;
            }
        }

        return $changes;
    }
}
