<?php

declare(strict_types=1);

namespace App\Admin\Http\Requests;

use App\Core\Inventory\Enums\AdjustmentReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 在庫の入庫（quantity）と調整（delta ＋ reason）。どちらかは経路で決まる。
 */
final class StockOperationRequest extends FormRequest
{
    public const int MAX_QUANTITY = 1_000_000;

    public function authorize(): bool
    {
        return true; // 認可は経路のミドルウェア
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        // 種類（ADR 0036）。空なら軸の無い商品のただ 1 つの種類
        $rules = ['location_id' => ['required', 'ulid'], 'variant_id' => ['sometimes', 'nullable', 'ulid']];

        return $this->routeIs('admin.stock.adjust')
            ? $rules + [
                'delta' => ['required', 'integer', 'not_in:0', 'min:-'.self::MAX_QUANTITY, 'max:'.self::MAX_QUANTITY],
                'reason' => ['required', Rule::enum(AdjustmentReason::class)],
            ]
            : $rules + ['quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY]];
    }

    /**
     * 誤りの文に出す欄の呼び名（画面の「拠点」「増減」「理由」「入庫する数」に合わせる）。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['location_id' => '拠点', 'variant_id' => '種類', 'delta' => '増減', 'reason' => '理由', 'quantity' => '入庫する数'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['delta.not_in' => '増減は 0 以外の数を入力してください。'];
    }
}
