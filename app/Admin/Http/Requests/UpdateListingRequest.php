<?php

declare(strict_types=1);

namespace App\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 出品物の編集。画面が読んだバージョン（version）を必ず送らせる（楽観ロック）。
 */
final class UpdateListingRequest extends FormRequest
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
            'version' => ['required', 'integer', 'min:1'],
            ...ListingRules::fields('sometimes'),
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
