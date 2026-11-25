<?php

declare(strict_types=1);

namespace App\Admin\Http\Requests;

use App\Core\Order\Services\OrderFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 注文の項目の並べ替え（EnterTenant の後。ADR 0066）。全部の欄の ID を出したい順に。足りない・余分は錠の中で OrderFields が確かめる
 */
final class OrderFieldOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 認可は経路のミドルウェア
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'list', 'max:'.OrderFields::MAX_TOTAL],
            'ids.*' => ['string', 'distinct', 'ulid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['ids' => '並び', 'ids.*' => '並び'];
    }
}
