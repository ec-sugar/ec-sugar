<?php

declare(strict_types=1);

namespace App\Admin\Http\Requests;

use App\Core\Order\Services\OrderFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 注文の項目を足す・直す（EnterTenant の後。ADR 0066）。形と長さはここで（規則は核の OrderFields::rules と同じ）、
 * DB の制約（order_fields_* の CHECK とトリガー）が 2 枚目。前後の空白を取る・上限の数（使う欄 10 個・全部で 50 個）は、錠の中で OrderFields が確かめる
 */
final class OrderFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 認可は経路のミドルウェア（auth:operator ＋ EnsureOperatorIsActive ＋ EnterTenant の段階）
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return OrderFields::rules($this->input('type'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return OrderFields::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return OrderFields::attributes();
    }

    /**
     * 確かめた値（核へ渡す形）
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->safe()->only(['name', 'description', 'type', 'options', 'required', 'enabled']);
    }
}
