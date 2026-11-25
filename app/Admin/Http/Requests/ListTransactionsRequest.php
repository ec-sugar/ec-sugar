<?php

declare(strict_types=1);

namespace App\Admin\Http\Requests;

use App\Admin\Http\Controllers\Api\TransactionController;
use App\Core\Transaction\Enums\OrderStatus;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Enums\TransactionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * テナントの中の取引の一覧（EnterTenant の後）。件数に上限、並べ替えは決まった候補だけ。
 */
final class ListTransactionsRequest extends FormRequest
{
    public const int MAX_PER_PAGE = 100;

    // 並べ替えに使える列（- は降順）。列の名前は利用者の入力から組み立てない
    public const array SORTS = [
        'created_at' => 'created_at',
        'total_amount' => 'total_amount',
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
            'status' => ['sometimes', 'nullable', Rule::enum(TransactionStatus::class)],
            // 物販の対応の状況（入金待ち・入金済み…）。注文の一覧の「状態で絞る」
            'order_status' => ['sometimes', 'nullable', Rule::enum(OrderStatus::class)],
            'phase' => ['sometimes', 'nullable', Rule::enum(Phase::class)],
            // 銀行振込の入金待ち・代引きで入金を確かめていない（ADR 0074）
            'payment' => ['sometimes', 'nullable', Rule::in(TransactionController::PAYMENT_FILTERS)],
            'seller_id' => ['sometimes', 'nullable', 'ulid'],
            // 言葉で探す: 注文番号（取引の ID の先頭）か、明細の商品名の一部。商品名は個人情報でない
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'nullable', Rule::in([...array_keys(self::SORTS), ...array_map(static fn (string $key): string => "-{$key}", array_keys(self::SORTS))])],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 50);
    }

    /**
     * @return array{0: string, 1: 'asc'|'desc'} 並べ替える列と向き。既定は新しい順
     */
    public function sort(): array
    {
        $sort = (string) ($this->validated('sort') ?? '-created_at');

        return [self::SORTS[ltrim($sort, '-')], str_starts_with($sort, '-') ? 'desc' : 'asc'];
    }
}
