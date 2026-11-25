<?php

declare(strict_types=1);

namespace App\Core\Order\Services;

use App\Core\Event\EventStore;
use App\Core\Order\Enums\OrderFieldType;
use App\Core\Order\Models\OrderField;
use App\Core\Order\Models\OrderFieldValue;
use App\Core\Plugin\Gift\GiftNote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 注文の項目（ADR 0066）: 店が注文の入力に足す欄（のしの有無・用途・ご要望など）の決まりを変える・お客さんの値を確かめる・注文に残す・見せる。
 *
 * - 決まりを変えるのはここだけ。書き込みは EventStore::lock() → 行のロックの順（ADR 0012）。記録（order_field.*）には決まりをそのまま残す（店の人が決める文で、個人情報ではない）
 * - 同時に使う欄は 10 個まで（DB のトリガーでも守る）。欄は全部で 50 個まで（ホストする時の上限）
 * - 注文に使われた欄は消せない（「使わない」にだけできる。DB の外部キーでも守る）。過去の注文は、値に写した名前と種類で見せる
 * - お客さんの値は、注文の入力・確かめの画面・確定の錠の中の 3 回、今の決まりで確かめる（必須・長さ・選択肢の中か）。使わない欄・知らない欄の値は捨てる
 * - 値は店の鍵で暗号化して持つ（ADR 0010）。注文のイベントには欄の ID だけを入れ、値は入れない
 */
final class OrderFields
{
    public const string RECORDED = 'order.fields_recorded';

    public const int MAX_ENABLED = 10;

    public const int MAX_TOTAL = 50;

    public const int NAME_MAX = 40;

    public const int DESCRIPTION_MAX = 100;

    public const int OPTIONS_MAX = 20;

    public const int OPTION_MAX = 40;

    /** 並びの上限（DB の制約 order_fields_position_range と同じ） */
    public const int MAX_POSITION = 1000;

    public function __construct(private readonly EventStore $events) {}

    /**
     * 注文の入力に出す欄（使う欄を並びの順に）
     *
     * @return list<OrderField>
     */
    public function active(): array
    {
        return array_values(OrderField::query()->where('enabled', true)->orderBy('position')->orderBy('id')->get()->all());
    }

    /**
     * 全部の欄（使わない欄も。管理画面の一覧）
     *
     * @return list<OrderField>
     */
    public function all(): array
    {
        return array_values(OrderField::query()->orderBy('position')->orderBy('id')->get()->all());
    }

    /**
     * 注文に使われた欄の ID（消せない欄）
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    public function used(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_fill_keys(OrderFieldValue::query()->whereIn('order_field_id', $ids)->distinct()->pluck('order_field_id')->map(static fn (mixed $id): string => (string) $id)->all(), true);
    }

    /**
     * 欄を足す（並びは最後）。形が違えば ValidationException（欄の名前は送った名前のまま）
     *
     * @param  array<string, mixed>  $input  {name, description, type, options, required, enabled}
     */
    public function create(array $input, string $actor): OrderField
    {
        $values = self::definition($input);

        return DB::transaction(function () use ($values, $actor): OrderField {
            $this->events->lock();

            if (OrderField::query()->count() >= self::MAX_TOTAL) {
                throw ValidationException::withMessages(['name' => '注文の項目は '.self::MAX_TOTAL.' 個までです。使わない項目を削除してから追加してください。']);
            }

            $this->assertEnabledRoom($values['enabled'], null);
            $field = OrderField::create([...$values, 'position' => $this->nextPosition()]);
            $this->events->append('order_field.created', 'order_field', $field->id, self::snapshot($field), $actor);

            return $field;
        });
    }

    /**
     * 欄の決まりを直す（名前・説明・種類・選択肢・必須・使う／使わない）。注文に使われた欄でも直せる（過去の注文は写した名前で見せる）。
     * 変えたら真（同じ決まりなら記録を増やさず偽。管理画面は偽なら監査ログにも残さない）
     *
     * @param  array<string, mixed>  $input
     */
    public function update(string $id, array $input, string $actor): bool
    {
        $values = self::definition($input);

        return DB::transaction(function () use ($id, $values, $actor): bool {
            $this->events->lock();
            $field = OrderField::query()->lockForUpdate()->findOrFail($id);
            $before = self::snapshot($field);
            $field->fill($values);

            if (self::snapshot($field) === $before) {
                return false;
            }

            $this->assertEnabledRoom($values['enabled'], $field->id);
            $field->save();
            $this->events->append('order_field.updated', 'order_field', $field->id, self::snapshot($field), $actor);

            return true;
        });
    }

    /**
     * 欄を消す。注文に使われた欄は消せない（ValidationException。「使わない」にする）
     */
    public function delete(string $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $this->events->lock();
            $field = OrderField::query()->lockForUpdate()->findOrFail($id);

            if (OrderFieldValue::query()->where('order_field_id', $field->id)->exists()) {
                throw ValidationException::withMessages(['order_field' => '「'.$field->name.'」は注文で回答されたため削除できません。お客さんの画面に表示しないときは、「使う」をオフにしてください。']);
            }

            $field->delete();
            $this->events->append('order_field.deleted', 'order_field', $field->id, ['name' => $field->name], $actor);
        });
    }

    /**
     * 並びを決め直す。全部の欄の ID を、出したい順に（足りない・余分・重なりは ValidationException）。並びが変わったら真
     */
    public function reorder(mixed $ids, string $actor): bool
    {
        $data = Validator::make(['ids' => $ids], [
            'ids' => ['required', 'array', 'list', 'max:'.self::MAX_TOTAL],
            'ids.*' => ['string', 'distinct', 'ulid'],
        ], [], ['ids' => '並び', 'ids.*' => '並び'])->validate();
        $order = array_map('strtolower', $data['ids']);

        return DB::transaction(function () use ($order, $actor): bool {
            $this->events->lock();
            $fields = OrderField::query()->lockForUpdate()->get()->keyBy('id');
            $known = $fields->keys()->map(static fn (mixed $id): string => (string) $id)->sort()->values()->all();
            $sorted = $order;
            sort($sorted);

            if ($known !== $sorted) {
                throw ValidationException::withMessages(['ids' => 'ほかの操作で項目が追加・削除されました。画面を読み込み直してから並べ替えてください。']);
            }

            $changed = false;
            foreach ($order as $position => $id) {
                $field = $fields->get($id);
                assert($field instanceof OrderField);

                if ($field->position !== $position) {
                    $field->position = $position;
                    $field->save();
                    $changed = true;
                }
            }

            if ($changed) {
                $this->events->append('order_field.reordered', 'order_field', $order[0], ['ids' => $order], $actor);
            }

            return $changed;
        });
    }

    /**
     * お客さんが入れた値を今の決まりで確かめ、形をそろえて返す（欄の ID => 値）。誤りは ValidationException（欄の名前は fields.<欄の ID>）。
     * 使う欄だけを見る（使わない欄・知らない欄・ほかの店の欄の値は捨てる）。空の任意の欄は入れない。チェックは '1'（はい）か '0'（いいえ）
     *
     * @param  array<array-key, mixed>  $raw
     * @return array<string, string>
     */
    public function check(array $raw): array
    {
        $answers = [];
        $errors = [];

        foreach ($this->active() as $field) {
            $value = $raw[$field->id] ?? null;
            $value = is_string($value) ? $value : null;
            $problem = null;

            switch ($field->type) {
                case OrderFieldType::Checkbox:
                    $on = $value === '1';
                    $problem = $field->required && ! $on ? '「'.$field->name.'」にチェックを入れてください。' : null;
                    $answers[$field->id] = $on ? '1' : '0';
                    break;
                case OrderFieldType::Select:
                    $value ??= '';
                    if ($value === '') {
                        $problem = $field->required ? '「'.$field->name.'」を選んでください。' : null;
                    } elseif (! in_array($value, $field->options, true)) {
                        $problem = '「'.$field->name.'」は、表示されている選択肢から選んでください。';
                    } else {
                        $answers[$field->id] = $value;
                    }
                    break;
                default:
                    $problem = self::textProblem($field, $value ?? '');
                    $text = GiftNote::normalize($value ?? '');
                    if ($problem === null && $text !== '') {
                        $answers[$field->id] = $text;
                    }
            }

            if ($problem !== null) {
                $errors['fields.'.$field->id] = $problem;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $answers;
    }

    /**
     * 確かめの画面の指紋に入れる要約（使う欄の決まりと値のハッシュ）。確かめの後に店が欄を変えたら（必須にした・消した・名前や説明や選択肢を変えた）、指紋が変わって確定しない。
     * 欄は ID の順に並べる（並べ替えただけなら答えの意味は変わらないので止めない）。説明も入れる（同意のチェックに使った時、見せた文で確定する）。
     * 欄も値も無い店は空（指紋は前と同じ形）
     *
     * @param  array<string, string>  $answers
     */
    public function digest(array $answers): string
    {
        $fields = $this->active();

        if ($fields === [] && $answers === []) {
            return '';
        }

        ksort($answers);
        usort($fields, static fn (OrderField $a, OrderField $b): int => strcmp($a->id, $b->id));

        return hash('sha256', json_encode([
            array_map(static fn (OrderField $f): array => [$f->id, $f->type->value, $f->name, $f->description, $f->required, $f->options], $fields),
            $answers,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * 確かめの画面に見せる値（使う欄の順に、値のある欄だけ）
     *
     * @param  array<string, string>  $answers
     * @return list<array{name: string, value: string}>
     */
    public function preview(array $answers): array
    {
        $shown = [];

        foreach ($this->active() as $field) {
            if (isset($answers[$field->id])) {
                $shown[] = ['name' => $field->name, 'value' => $field->type->display($answers[$field->id])];
            }
        }

        return $shown;
    }

    /**
     * 注文の確定の中で呼ぶ（連鎖のロックの後、同じトランザクション）。値を欄の名前と種類の写しと一緒に残し、記録には欄の ID だけを残す
     *
     * @param  array<string, string>  $answers  check() の答え
     */
    public function record(string $transactionId, array $answers, string $actor): void
    {
        $this->events->lock();
        $ids = [];

        foreach ($this->active() as $field) {
            if (! isset($answers[$field->id])) {
                continue;
            }

            OrderFieldValue::create([
                'transaction_id' => $transactionId,
                'order_field_id' => $field->id,
                'name' => $field->name,
                'description' => $field->description,
                'type' => $field->type,
                'position' => $field->position,
                'value' => $answers[$field->id],
            ]);
            $ids[] = $field->id;
        }

        if ($ids !== []) {
            $this->events->append(self::RECORDED, 'transaction', $transactionId, ['field_ids' => $ids], $actor);
        }
    }

    /**
     * 注文に残した値（注文の時の名前・説明・種類で、その時の並びの順に）。label はお客さんに見せる形（チェックは「はい」「いいえ」）
     *
     * @return list<array{name: string, description: string|null, type: OrderFieldType, value: string, label: string}>
     */
    public function forTransaction(string $transactionId): array
    {
        return array_values(OrderFieldValue::query()->where('transaction_id', $transactionId)->orderBy('position')->orderBy('id')->get()
            ->map(static fn (OrderFieldValue $v): array => ['name' => $v->name, 'description' => $v->description, 'type' => $v->type, 'value' => $v->value, 'label' => $v->type->display($v->value)])
            ->all());
    }

    /**
     * お客さんに見せる注文の値（ADR 0066 の決定 5）: 選ぶ・チェックの答えだけ。文の答えは、打ち間違えたメールの持ち主に見せない・
     * 他人宛ての注文の知らせに好きな文を載せる口にしないため、メール・ご注文の状況・マイページには出さず、文の答えがあったことだけを返す
     *
     * @return array{0: list<array{name: string, value: string}>, 1: bool} [見せる答え, 文の答えがあるか]
     */
    public function forCustomer(string $transactionId): array
    {
        $shown = [];
        $withheld = false;

        foreach ($this->forTransaction($transactionId) as $f) {
            if ($f['type']->freeText()) {
                $withheld = true;
            } else {
                $shown[] = ['name' => $f['name'], 'value' => $f['label']];
            }
        }

        return [$shown, $withheld];
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(OrderField $field): array
    {
        return [
            'name' => $field->name,
            'description' => $field->description,
            'type' => $field->type->value,
            'options' => $field->options,
            'required' => $field->required,
            'enabled' => $field->enabled,
        ];
    }

    /**
     * 店の人が入れる欄の決まりの規則（管理画面の FormRequest も同じものを使う。ここでも確かめ直す）
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(mixed $type): array
    {
        $line = static function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && (str_contains($value, "\n") || preg_match(GiftNote::UNSAFE, $value) === 1)) {
                $fail(':attribute は改行のない 1 行で、使えない文字（制御文字・文字の向きを変える文字・幅の無い文字）を含めずに入力してください。');
            }
        };

        return [
            'name' => ['required', 'string', 'max:'.self::NAME_MAX, $line],
            'description' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX, $line],
            'type' => ['required', 'string', Rule::enum(OrderFieldType::class)],
            // 選択肢は選ぶ欄だけ（ほかの種類で送られてきても見ない）
            'options' => ['exclude_unless:type,'.OrderFieldType::Select->value, 'array', 'list', 'max:'.self::OPTIONS_MAX, Rule::requiredIf($type === OrderFieldType::Select->value)],
            'options.*' => ['exclude_unless:type,'.OrderFieldType::Select->value, 'required', 'string', 'distinct', 'max:'.self::OPTION_MAX, $line],
            'required' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'options.required' => '「選択式」の項目には、選択肢を 1 つ以上入力してください。',
            'options.*.distinct' => '同じ選択肢が 2 つあります。',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'name' => '項目の名前', 'description' => '説明', 'type' => '種類', 'options' => '選択肢', 'options.*' => '選択肢',
            'required' => '必須', 'enabled' => '使う',
        ];
    }

    /**
     * 新しい欄の並び（最後）。上限（1000）を超えるなら、今の並びを 0 から詰め直してから足す（消した欄の隙間で上限を超えない）
     */
    private function nextPosition(): int
    {
        $next = ((int) OrderField::query()->max('position')) + 1;

        if ($next <= self::MAX_POSITION) {
            return $next;
        }

        $position = 0;
        foreach (OrderField::query()->orderBy('position')->orderBy('id')->lockForUpdate()->get() as $field) {
            $field->position = $position++;
            $field->save();
        }

        return $position;
    }

    /**
     * 使う欄にするとき、ほかの使う欄と合わせて上限を超えないか（連鎖のロックの中で数える）
     */
    private function assertEnabledRoom(bool $enabled, ?string $self): void
    {
        if (! $enabled) {
            return;
        }

        $others = OrderField::query()->where('enabled', true)->when($self !== null, static fn ($q) => $q->whereKeyNot($self))->count();

        if ($others >= self::MAX_ENABLED) {
            throw ValidationException::withMessages(['enabled' => '同時に使える項目は '.self::MAX_ENABLED.' 個までです。ほかの項目を「使わない」にしてから使ってください。']);
        }
    }

    /**
     * 店の人が入れた欄の決まりを確かめ、形をそろえる
     *
     * @param  array<string, mixed>  $input
     * @return array{name: string, description: string|null, type: OrderFieldType, options: list<string>, required: bool, enabled: bool}
     */
    private static function definition(array $input): array
    {
        foreach (['name', 'description'] as $key) {
            if (is_string($input[$key] ?? null)) {
                $input[$key] = trim($input[$key]);
            }
        }

        // 選択肢は選ぶ欄だけ（ほかの種類で送られてきても見ない）
        if (($input['type'] ?? null) !== OrderFieldType::Select->value) {
            unset($input['options']);
        } elseif (is_array($input['options'] ?? null)) {
            $input['options'] = array_map(static fn (mixed $o): mixed => is_string($o) ? trim($o) : $o, $input['options']);
        }

        $data = Validator::make($input, self::rules($input['type'] ?? null), self::messages(), self::attributes())->validate();

        $type = OrderFieldType::from((string) $data['type']);

        return [
            'name' => (string) $data['name'],
            'description' => ($data['description'] ?? '') === '' ? null : (string) $data['description'],
            'type' => $type,
            'options' => $type === OrderFieldType::Select ? array_values(array_map('strval', $data['options'] ?? [])) : [],
            'required' => (bool) $data['required'],
            'enabled' => (bool) $data['enabled'],
        ];
    }

    /**
     * 文の欄の誤り（合えば null）
     */
    private static function textProblem(OrderField $field, string $raw): ?string
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            return '「'.$field->name.'」は文字で入力してください。';
        }

        $text = GiftNote::normalize($raw);

        if ($text === '') {
            return $field->required ? '「'.$field->name.'」を入力してください。' : null;
        }

        $max = (int) $field->type->maxLength();

        if (mb_strlen($text) > $max) {
            return '「'.$field->name.'」は '.number_format($max).' 文字以内で入力してください。';
        }

        if ($field->type === OrderFieldType::Text && str_contains($text, "\n")) {
            return '「'.$field->name.'」は改行せずに 1 行で入力してください。';
        }

        if (preg_match(GiftNote::UNSAFE, $text) === 1) {
            return '「'.$field->name.'」に使えない特殊な文字（制御文字など）が含まれています。その文字を消してから、もう一度お試しください。';
        }

        return null;
    }
}
