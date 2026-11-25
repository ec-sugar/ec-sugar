<?php

declare(strict_types=1);

namespace App\Admin\Http\Requests;

use App\Core\Discount\Enums\CouponKind;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * クーポン・自動の割引を作る・直す（EnterTenant の後。ADR 0054・0065）。値の幅はここで、DB の制約（coupons_* の CHECK）が 2 枚目。
 * 作る（POST）は種類（method: code か automatic）とコードも受け取り、直す（PUT）は種類とコード以外の全部を受け取る（画面は 1 枚のフォームの値をまとめて送る）。
 * 自動の割引はコードを持たず、お客さんに見せる名前（label）が要る。
 * コードの形と重なり・対象の ID がこの店にあるか・使われた後に変えない欄・自動の割引の数の上限は、錠の中で CouponService が確かめる
 */
final class CouponRequest extends FormRequest
{
    public const int MAX_AMOUNT = 100_000_000;

    public const int MAX_TARGETS = 100;

    public const int MAX_USAGE = 10_000_000;

    public const int MAX_PER_CUSTOMER = 1_000;

    public const int MAX_QUANTITY = 1_000;

    public const int MAX_LABEL = 40;

    public function authorize(): bool
    {
        return true; // 認可は経路のミドルウェア（auth:operator ＋ EnsureOperatorIsActive ＋ EnterTenant の段階）
    }

    protected function prepareForValidation(): void
    {
        // 対象の ID は小文字にそろえる（店の DB の ULID は小文字）
        $ids = $this->input('target_ids');

        if (is_array($ids)) {
            $this->merge(['target_ids' => array_map(static fn (mixed $id): mixed => is_string($id) ? strtolower($id) : $id, $ids)]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $kind = $this->input('kind');
        $creating = $this->isMethod('POST');
        $automatic = $creating && $this->input('method') === 'automatic';

        return [
            'method' => $creating ? ['sometimes', 'string', Rule::in(['code', 'automatic'])] : ['prohibited'],
            // 自動の割引はコードを持たない（送られても捨てる）
            'code' => $creating ? ['exclude_if:method,automatic', 'required', 'string', 'max:64'] : ['prohibited'],
            'name' => ['required', 'string', 'max:100'],
            // お客さんに見せる名前は自動の割引だけ（作る時は必須。直す時はサービスが種類を見て確かめる。クーポンでは捨てる）。改行・制御文字は入れない
            'label' => $automatic
                ? ['required', 'string', 'max:'.self::MAX_LABEL, 'not_regex:/[\p{Cc}\p{Cf}]/u']
                : ['exclude_if:method,code', 'nullable', 'string', 'max:'.self::MAX_LABEL, 'not_regex:/[\p{Cc}\p{Cf}]/u'],
            'kind' => ['required', 'string', Rule::enum(CouponKind::class)],
            // 送料無料は値を持たない（0 にそろえる）。定率は 1〜100（%）、定額は 1 円から
            'value' => ['exclude_if:kind,free_shipping', 'required', 'integer', 'min:1', 'max:'.($kind === CouponKind::Percent->value ? 100 : self::MAX_AMOUNT)],
            // 上限の額は定率だけ（ほかの種類では捨てる）
            'max_discount' => ['exclude_unless:kind,percent', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'target' => ['required', 'string', Rule::in(['all', 'categories', 'listings'])],
            'target_ids' => ['exclude_if:target,all', 'required', 'array', 'list', 'min:1', 'max:'.self::MAX_TARGETS],
            'target_ids.*' => ['exclude_if:target,all', 'string', 'ulid', 'distinct'],
            'min_subtotal' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            // 対象の商品の数の合計がこれ以上（まとめ買い。ADR 0065）
            'min_quantity' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
            'starts_at' => ['nullable', 'string', 'max:40', 'date', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'ends_at' => ['nullable', 'string', 'max:40', 'date', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_USAGE],
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_CUSTOMER],
            'members_only' => ['required', 'boolean'],
            'status' => $creating ? ['sometimes', 'string', Rule::in(['active', 'paused'])] : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value.max' => $this->input('kind') === CouponKind::Percent->value ? '割引の率は 1〜100% にしてください。' : '値引きの額は ¥100,000,000 までにしてください。',
            'code.prohibited' => 'コードは作った後に変更できません。変えたいときは、新しいクーポンを作ってください。',
            'method.prohibited' => 'クーポンと自動の割引は、作った後に切り替えられません。新しく作ってください。',
            'label.required' => '自動の割引には、お客さんに表示する名前を入力してください（例: 秋のセール 全品 10%）。',
            'label.max' => 'お客さんに表示する名前は '.self::MAX_LABEL.' 文字までにしてください。',
            'label.not_regex' => 'お客さんに表示する名前に、改行や見えない文字は使えません。',
            'status.prohibited' => '停止と再開は、それぞれのボタンで行います。',
            'target_ids.required' => '対象のカテゴリか商品を 1 つ以上選んでください。',
            'target_ids.min' => '対象のカテゴリか商品を 1 つ以上選んでください。',
            'target_ids.max' => '対象は '.self::MAX_TARGETS.' 個までです。',
            'target_ids.*.ulid' => '選んだカテゴリか商品が見つかりません。画面を読み込み直してください。',
        ];
    }

    /**
     * 期間の前後（両方あるときだけ。片方だけなら、その日から・その日まで）
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $starts = $this->moment('starts_at');
                $ends = $this->moment('ends_at');

                if ($starts !== null && $ends !== null && ! $starts->lt($ends) && ! $validator->errors()->has('ends_at')) {
                    $validator->errors()->add('ends_at', '終了日時は、開始日時より後にしてください。');
                }
            },
        ];
    }

    /**
     * サービスへ渡す値（種類ごとに使わない欄をそろえる: 送料無料の値は 0、定率でない上限の額は無し、全品の対象は空）
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = $this->validated();
        $kind = (string) $data['kind'];
        $target = (string) $data['target'];
        $creating = $this->isMethod('POST');
        $automatic = $creating && ($data['method'] ?? 'code') === 'automatic';

        $values = array_filter([
            'method' => $creating ? ($automatic ? 'automatic' : 'code') : null,
            'code' => $data['code'] ?? null,
            'status' => $data['status'] ?? null,
        ], static fn (mixed $v): bool => $v !== null) + [
            'name' => trim((string) $data['name']),
            'kind' => $kind,
            'value' => $kind === CouponKind::FreeShipping->value ? 0 : (int) $data['value'],
            'max_discount' => $kind === CouponKind::Percent->value && isset($data['max_discount']) ? (int) $data['max_discount'] : null,
            'target' => $target,
            'target_ids' => $target === 'all' ? [] : array_values(array_map('strval', (array) $data['target_ids'])),
            'min_subtotal' => isset($data['min_subtotal']) ? (int) $data['min_subtotal'] : null,
            'min_quantity' => isset($data['min_quantity']) ? (int) $data['min_quantity'] : null,
            'starts_at' => $this->moment('starts_at'),
            'ends_at' => $this->moment('ends_at'),
            'usage_limit' => isset($data['usage_limit']) ? (int) $data['usage_limit'] : null,
            'members_only' => (bool) $data['members_only'],
        ];

        // 名前は送られた時だけ（自動の割引の名前。前後の空白はサービスが除く）
        if (array_key_exists('label', $data)) {
            $values['label'] = $data['label'];
        }

        // 1 人の回数（ADR 0054 の 3・0065 の 6）: 作る時に送られなければ、クーポンは 1 回・自動の割引は何回でも。null を送ると何回でも。
        // 直す時に送られなければ今のまま
        if ($this->exists('per_customer_limit')) {
            $values['per_customer_limit'] = isset($data['per_customer_limit']) ? (int) $data['per_customer_limit'] : null;
        } elseif ($creating) {
            $values['per_customer_limit'] = $automatic ? null : 1;
        }

        return $values;
    }

    private function moment(string $field): ?CarbonImmutable
    {
        $value = $this->input($field);

        if (! is_string($value) || trim($value) === '' || strtotime($value) === false) {
            return null;
        }

        return CarbonImmutable::parse($value)->utc()->startOfMinute();
    }
}
