<?php

declare(strict_types=1);

namespace App\Admin\Http\Requests;

use App\Admin\Services\SalesPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * 売上の集計の期間（EnterTenant の後。お金の段階から）。決まった期間か、好きな期間（YYYY-MM-DD の 2 つ。366 日まで・今日まで）
 */
final class SalesReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 認可は経路のミドルウェア（auth:operator ＋ EnsureOperatorIsActive ＋ EnterTenant の tenant-level:money）
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'preset' => ['sometimes', 'string', Rule::in(SalesPeriod::PRESETS)],
            'from' => ['sometimes', 'nullable', 'string', 'max:10'],
            'to' => ['sometimes', 'nullable', 'string', 'max:10'],
            // CSV の中身（日ごと・商品ごと）。JSON の時は使わない
            'kind' => ['sometimes', 'string', Rule::in(['daily', 'products'])],
        ];
    }

    /**
     * @throws ValidationException 好きな期間の日付が違う・長すぎる・先の日
     */
    public function period(): SalesPeriod
    {
        try {
            return SalesPeriod::of((string) $this->validated('preset', 'month'), $this->validated('from'), $this->validated('to'));
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['from' => $error->getMessage()]);
        }
    }

    public function kind(): string
    {
        return (string) $this->validated('kind', 'daily');
    }
}
