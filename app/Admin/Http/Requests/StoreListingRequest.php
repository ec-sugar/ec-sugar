<?php

declare(strict_types=1);

namespace App\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreListingRequest extends FormRequest
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
        return [
            'seller_id' => ['required', 'ulid'],
            'kind' => ['required', 'string', Rule::in(ListingRules::kinds())],
            ...ListingRules::fields('required'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ListingRules::attributes();
    }
}
