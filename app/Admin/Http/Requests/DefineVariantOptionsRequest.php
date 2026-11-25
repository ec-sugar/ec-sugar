<?php

declare(strict_types=1);

namespace App\Admin\Http\Requests;

use App\Core\Listing\Models\ListingOption;
use App\Core\Listing\Services\VariantService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 商品の種類を選ぶ軸（色・サイズなど）と値を決め直す（ADR 0036）。空の並びなら軸の無い商品に戻す。
 * ここでは形と個数だけを見て大きさを抑え、名前の中身（文字の種類・重なり・組み合わせの数）は核（VariantService）が確かめる。
 */
final class DefineVariantOptionsRequest extends FormRequest
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
            'options' => ['present', 'array', 'list', 'max:'.ListingOption::MAX_PER_LISTING],
            'options.*' => ['required', 'array:name,values'],
            'options.*.name' => ['required', 'string', 'max:40'],
            'options.*.values' => ['required', 'array', 'list', 'min:1', 'max:'.VariantService::MAX_VALUES_PER_OPTION],
            'options.*.values.*' => ['required', 'string', 'max:40'],
            // 軸の無い商品に初めて軸を足すとき、今の品番と在庫を渡す組み合わせ（値の名前を軸の順に）。中身は核が確かめる
            'carry_to' => ['nullable', 'array', 'list', 'max:'.ListingOption::MAX_PER_LISTING],
            'carry_to.*' => ['required', 'string', 'max:40'],
        ];
    }

    /**
     * 誤りの文に出す欄の呼び名（無いと「options.0.nameを入力してください。」と欄の名前のまま出る）。
     * 画面の「選択項目 1」「値」に合わせる。:position は何番目か（1 から）。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'version' => 'バージョン',
            'options' => '選択項目',
            'options.*' => ':position 番目の選択項目',
            'options.*.name' => '選択項目 :position の名前',
            'options.*.values' => '選択項目 :position の値',
            'options.*.values.*' => '選択項目 :first-position の :second-position 番目の値',
            'carry_to' => '今の品番と在庫を引き継ぐ種類',
            'carry_to.*' => '今の品番と在庫を引き継ぐ種類',
        ];
    }

    /**
     * 核に渡す軸の並び（検査済みの形だけ）。
     *
     * @return list<array{name: string, values: list<string>}>
     */
    public function definedOptions(): array
    {
        /** @var list<array{name: string, values: list<string>}> $options */
        $options = array_map(static fn (array $option): array => [
            'name' => (string) $option['name'],
            'values' => array_values(array_map(strval(...), $option['values'])),
        ], array_values($this->validated('options')));

        return $options;
    }

    /**
     * 今の種類を渡す組み合わせ（渡さないなら null）。
     *
     * @return list<string>|null
     */
    public function carryTo(): ?array
    {
        $carry = $this->validated('carry_to');

        return is_array($carry) ? array_values(array_map(strval(...), $carry)) : null;
    }
}
