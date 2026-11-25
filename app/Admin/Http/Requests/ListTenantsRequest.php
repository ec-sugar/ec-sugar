<?php

declare(strict_types=1);

namespace App\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * テナントの一覧。1 ページの件数に上限を付ける（一度に全件を引かせない）。
 */
final class ListTenantsRequest extends FormRequest
{
    public const int MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        return true; // 認可は経路のミドルウェア（auth:operator ＋ EnsureOperatorIsActive）
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 50);
    }
}
