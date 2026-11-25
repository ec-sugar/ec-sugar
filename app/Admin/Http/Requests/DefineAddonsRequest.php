<?php

declare(strict_types=1);

namespace App\Admin\Http\Requests;

use App\Core\Listing\Models\ListingAddon;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 商品のオプション（名入れ・ギフト包装など。ADR 0036）を決め直す。空の並びならオプションを外す。
 * ここでは形と個数だけを見て大きさを抑え、中身（形の名前・文字の種類・重なり・額の範囲・最大の文字数）は核（AddonService）が確かめる。
 */
final class DefineAddonsRequest extends FormRequest
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
            'addons' => ['present', 'array', 'list', 'max:'.ListingAddon::MAX_PER_LISTING],
            'addons.*' => ['required', 'array:kind,name,required,amount,choices,max_length,tax_class'],
            'addons.*.kind' => ['required', 'string', 'max:16'],
            'addons.*.name' => ['required', 'string', 'max:40'],
            'addons.*.required' => ['sometimes', 'boolean'],
            'addons.*.amount' => ['sometimes', 'nullable', 'integer'],
            'addons.*.choices' => ['sometimes', 'nullable', 'array', 'list', 'max:'.ListingAddon::MAX_CHOICES],
            'addons.*.choices.*' => ['required', 'array:label,amount'],
            'addons.*.choices.*.label' => ['required', 'string', 'max:40'],
            'addons.*.choices.*.amount' => ['sometimes', 'nullable', 'integer'],
            'addons.*.max_length' => ['sometimes', 'nullable', 'integer'],
            'addons.*.tax_class' => ['sometimes', 'nullable', 'string', 'in:standard,reduced'],
        ];
    }

    /**
     * 誤りの文に出す欄の呼び名（無いと「addons.0.nameを入力してください。」と欄の名前のまま出る）。
     * :position は何番目か（1 から）、:first-position・:second-position は外側と内側の並びの何番目か。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'version' => 'バージョン',
            'addons' => 'オプション',
            'addons.*' => ':position 番目のオプション',
            'addons.*.kind' => 'オプション :position の種類',
            'addons.*.name' => 'オプション :position の名前',
            'addons.*.required' => 'オプション :position の必須',
            'addons.*.amount' => 'オプション :position の追加料金',
            'addons.*.choices' => 'オプション :position の選択肢',
            'addons.*.choices.*' => 'オプション :first-position の :second-position 番目の選択肢',
            'addons.*.choices.*.label' => 'オプション :first-position の選択肢 :second-position の名前',
            'addons.*.choices.*.amount' => 'オプション :first-position の選択肢 :second-position の追加料金',
            'addons.*.max_length' => 'オプション :position の最大の文字数',
            'addons.*.tax_class' => 'オプション :position の税区分',
        ];
    }

    /**
     * 核に渡すオプションの並び（検査済みの形だけ。数は整数に、必須は真偽に揃える）。
     *
     * @return list<array<string, mixed>>
     */
    public function definedAddons(): array
    {
        $int = static fn (mixed $value): ?int => $value === null ? null : (int) $value;

        return array_values(array_map(static fn (array $addon): array => [
            'kind' => (string) $addon['kind'],
            'name' => (string) $addon['name'],
            'required' => filter_var($addon['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'amount' => $int($addon['amount'] ?? null) ?? 0,
            'choices' => is_array($addon['choices'] ?? null)
                ? array_values(array_map(static fn (array $choice): array => ['label' => (string) $choice['label'], 'amount' => $int($choice['amount'] ?? null) ?? 0], $addon['choices']))
                : null,
            'max_length' => $int($addon['max_length'] ?? null),
            'tax_class' => isset($addon['tax_class']) ? (string) $addon['tax_class'] : null,
        ], $this->validated('addons')));
    }
}
