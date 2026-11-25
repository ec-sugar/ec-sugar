<?php

declare(strict_types=1);

namespace App\Admin\Http\Requests;

use App\Core\Listing\Enums\VariantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 種類ごとの品番・価格・販売中かどうか・写真（ADR 0036）。画面が読んだ商品のバージョンを必ず送らせる（楽観ロック）。
 * 品番と価格の形は商品の項目（ListingRules）と同じ決まり。写真がその商品のものかは核が確かめる。
 */
final class UpdateVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 認可は経路のミドルウェア
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $listing = ListingRules::fields('sometimes');

        return [
            'version' => ['required', 'integer', 'min:1'],
            'sku' => $listing['sku'],
            'unit_amount' => $listing['unit_amount'],
            'status' => ['sometimes', Rule::enum(VariantStatus::class)],
            'image_id' => ['sometimes', 'nullable', 'ulid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ListingRules::attributes();
    }

    /**
     * 検査済みの値を VariantService に渡す形にする（送られた項目だけ。空の品番は「無し」）。
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = array_intersect_key($this->validated(), array_flip(['sku', 'unit_amount', 'status', 'image_id']));

        if (array_key_exists('sku', $changes) && $changes['sku'] === '') {
            $changes['sku'] = null;
        }

        if (array_key_exists('unit_amount', $changes) && $changes['unit_amount'] !== null) {
            $changes['unit_amount'] = (int) $changes['unit_amount'];
        }

        if (isset($changes['image_id']) && is_string($changes['image_id'])) {
            $changes['image_id'] = strtolower($changes['image_id']);
        }

        return $changes;
    }
}
