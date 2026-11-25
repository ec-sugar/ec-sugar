<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Http\Requests\DefineAddonsRequest;
use App\Admin\Http\Requests\DefineVariantOptionsRequest;
use App\Admin\Http\Requests\StockOperationRequest;
use App\Admin\Http\Requests\StoreListingRequest;
use App\Admin\Http\Requests\UpdateListingRequest;
use App\Admin\Http\Requests\UpdateVariantRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 管理画面の入力の誤りの文に、欄の名前（addons.0.name・title など）がそのまま出ないこと。
 * FormRequest の attributes()（欄の呼び名）が無いと、Laravel は欄の名前をそのまま文に入れる。
 * 並びの欄は「オプション 1 の名前」のように何番目かを添えて出す。
 */
final class InputErrorNamesTest extends TestCase
{
    /**
     * @return array<string, array{class-string<DefineAddonsRequest|DefineVariantOptionsRequest|StoreListingRequest|UpdateListingRequest|UpdateVariantRequest|StockOperationRequest>, array<string, mixed>}>
     */
    public static function brokenInputs(): array
    {
        $long = str_repeat('あ', 41);

        return [
            'オプション' => [DefineAddonsRequest::class, ['addons' => [
                ['kind' => '', 'name' => '', 'required' => 'x', 'amount' => 'x', 'max_length' => 'x', 'choices' => [['label' => '', 'amount' => 'x'], 'x']],
                'x',
                ['kind' => 'text', 'name' => $long, 'choices' => 'x', 'extra' => 1],
            ]]],
            'オプションの並びの形' => [DefineAddonsRequest::class, ['version' => 0, 'addons' => 'x']],
            '種類の軸' => [DefineVariantOptionsRequest::class, ['options' => [
                ['name' => '', 'values' => []],
                ['name' => $long, 'values' => ['', $long]],
                'x',
            ], 'carry_to' => ['', 1]]],
            '種類の軸の並びの形' => [DefineVariantOptionsRequest::class, ['version' => 'x', 'carry_to' => 'x']],
            '商品の登録' => [StoreListingRequest::class, ['seller_id' => 'x', 'kind' => 'x', 'title' => ' ', 'unit_amount' => -1, 'sku' => '-', 'status' => 'x',
                'description' => 1, 'description_blocks' => 'x', 'specs' => 'x', 'summary' => 1]],
            '商品の登録の空' => [StoreListingRequest::class, []],
            '商品の編集' => [UpdateListingRequest::class, ['title' => str_repeat('a', 201), 'description_blocks' => array_fill(0, 500, []), 'specs' => array_fill(0, 500, [])]],
            '種類ごとの値' => [UpdateVariantRequest::class, ['sku' => '-', 'unit_amount' => 'x', 'status' => 'x', 'image_id' => 'x']],
            '在庫の入庫' => [StockOperationRequest::class, ['location_id' => 'x', 'variant_id' => 'x', 'quantity' => 0]],
        ];
    }

    /**
     * @param  class-string<DefineAddonsRequest|DefineVariantOptionsRequest|StoreListingRequest|UpdateListingRequest|UpdateVariantRequest|StockOperationRequest>  $class
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('brokenInputs')]
    public function test_error_messages_do_not_show_the_raw_field_names(string $class, array $input): void
    {
        $request = new $class;
        $validator = Validator::make($input, $request->rules(), $request->messages(), $request->attributes());

        $this->assertTrue($validator->fails(), '誤りの入力が通った');

        foreach ($validator->errors()->messages() as $key => $messages) {
            foreach ($messages as $message) {
                // 日本語の文に英小文字の語（欄の名前・英語の既定の文）が混ざらない
                $this->assertDoesNotMatchRegularExpression('/[a-z_]{3,}/', $message, "{$key}: {$message}");
                $this->assertStringNotContainsString(':position', $message, "{$key}: {$message}");
            }
        }
    }

    public function test_array_fields_name_their_position(): void
    {
        $request = new DefineAddonsRequest;
        $errors = Validator::make(
            ['version' => 1, 'addons' => [['kind' => 'toggle', 'name' => '包装'], ['kind' => 'choice', 'name' => '', 'choices' => [['label' => 'なし'], ['label' => '']]]]],
            $request->rules(), $request->messages(), $request->attributes(),
        )->errors();

        $this->assertSame(['オプション 2 の名前を入力してください。'], $errors->get('addons.1.name'));
        $this->assertSame(['オプション 2 の選択肢 2 の名前を入力してください。'], $errors->get('addons.1.choices.1.label'));

        $request = new DefineVariantOptionsRequest;
        $errors = Validator::make(['version' => 1, 'options' => [['name' => '', 'values' => ['赤', '']]]], $request->rules(), $request->messages(), $request->attributes())->errors();

        $this->assertSame(['選択項目 1 の名前を入力してください。'], $errors->get('options.0.name'));
        $this->assertSame(['選択項目 1 の 2 番目の値を入力してください。'], $errors->get('options.0.values.1'));
    }
}
