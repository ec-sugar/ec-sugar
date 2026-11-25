<?php

declare(strict_types=1);

namespace App\Admin\Http\Requests;

use App\Core\Listing\Enums\ListingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * テナントの中の出品物の一覧（EnterTenant の後）。件数に上限、検索語に長さの上限。
 */
final class ListListingsRequest extends FormRequest
{
    public const int MAX_PER_PAGE = 100;

    // 並べ替えに使える列（- は降順）。列の名前は利用者の入力から組み立てない
    public const array SORTS = [
        'updated_at' => 'updated_at',
        'title' => 'title',
        'unit_amount' => 'unit_amount',
        'stock' => 'stock_quantity',
    ];

    public function authorize(): bool
    {
        return true; // 認可は経路のミドルウェア（auth:operator ＋ EnsureOperatorIsActive ＋ EnterTenant）
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(ListingStatus::class)],
            'seller_id' => ['sometimes', 'nullable', 'ulid'],
            'sort' => ['sometimes', 'nullable', Rule::in([...array_keys(self::SORTS), ...array_map(static fn (string $key): string => "-{$key}", array_keys(self::SORTS))])],
        ];
    }

    /**
     * @return array{0: string, 1: 'asc'|'desc'} 並べ替える列（SORTS の値）と向き。既定は更新の新しい順
     */
    public function sort(): array
    {
        $sort = (string) ($this->validated('sort') ?? '-updated_at');
        $descending = str_starts_with($sort, '-');

        return [self::SORTS[ltrim($sort, '-')], $descending ? 'desc' : 'asc'];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 50);
    }
}
