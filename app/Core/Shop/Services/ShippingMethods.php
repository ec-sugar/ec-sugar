<?php

declare(strict_types=1);

namespace App\Core\Shop\Services;

use App\Core\Event\EventStore;
use App\Core\Shop\Events\ShippingPolicyChanged;
use App\Core\Shop\Models\ShippingMethod;
use App\Core\Shop\Shipping\Regions;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * 送料の表を読む・変える（ADR 0064）。変えるのはここだけ。配送の方法（1〜10）ごとに 47 都道府県の額と「この金額以上で無料」。
 * 書き込みは EventStore::lock() → 方法の行のロックの順（ADR 0012）。表はまとめて置き換える（47 県がそろわない保存は断る）。
 * お金に関わるので、変えたらイベントに残し、定期便の約束の見直し（ShippingPolicyChanged）を出す
 */
final class ShippingMethods
{
    public const int MAX_METHODS = 10;

    public const int MAX_FEE = 100_000;

    public const int MAX_FREE_OVER = 10_000_000;

    public const int MAX_NAME = 30;

    public function __construct(private readonly EventStore $events) {}

    /**
     * 表（並び順）。rates は都道府県 => 額（JIS の順）
     *
     * @return list<array{id: string, name: string, position: int, free_over: int|null, rates: array<string, int>}>
     */
    public function all(): array
    {
        $rates = [];
        foreach (DB::table('shipping_rates')->get(['method_id', 'prefecture', 'fee']) as $row) {
            $rates[(string) $row->method_id][(string) $row->prefecture] = (int) $row->fee;
        }

        $list = [];
        foreach (ShippingMethod::query()->orderBy('position')->orderBy('id')->get() as $method) {
            $table = [];
            foreach (Regions::PREFECTURES as $prefecture) {
                $table[$prefecture] = $rates[$method->id][$prefecture] ?? 0;
            }
            $list[] = ['id' => $method->id, 'name' => $method->name, 'position' => $method->position, 'free_over' => $method->free_over, 'rates' => $table];
        }

        return $list;
    }

    /**
     * 表をまとめて置き換える。送られなかった方法は消す（その方法の商品はいちばん上の方法に戻る）。形が違えば ValidationException
     *
     * @param  array<string, mixed>  $input  {methods: list<{id: string|null, name, free_over: int|null, rates: {都道府県: 額}}>}
     * @return list<array{id: string, name: string, position: int, free_over: int|null, rates: array<string, int>}>
     */
    public function replace(array $input, ?string $actor = null): array
    {
        $methods = self::validate($input);

        DB::transaction(function () use ($methods, $actor): void {
            $this->events->lock();
            $current = ShippingMethod::query()->lockForUpdate()->get()->keyBy('id');
            $before = collect($this->all())->keyBy('id');

            foreach ($methods as $index => $method) {
                if ($method['id'] !== null && ! $current->has($method['id'])) {
                    throw ValidationException::withMessages(["methods.{$index}.id" => 'この配送の方法は見つかりません。画面を読み込み直してください。']);
                }
            }

            $kept = array_values(array_filter(array_column($methods, 'id')));
            $removed = $current->keys()->diff($kept)->values()->all();
            ShippingMethod::query()->whereIn('id', $removed)->delete();
            // 名前の入れ替え（A と B を取り替える）でも一意の制約に当たらないよう、残す方法の名前をいったん ID にしてから付け直す
            foreach ($kept as $id) {
                ShippingMethod::query()->whereKey($id)->update(['name' => $id]);
            }

            $changed = [];
            $top = null;
            foreach ($methods as $index => $method) {
                $values = ['name' => $method['name'], 'position' => $index + 1, 'free_over' => $method['free_over']];

                if ($method['id'] === null) {
                    $row = ShippingMethod::create($values);
                } else {
                    // 読んだモデルは仮の名前にする前の値を覚えているので、保存は列を直に書く（同じ名前に戻すときも書く）
                    $row = $current[$method['id']];
                    ShippingMethod::query()->whereKey($row->id)->update($values + ['updated_at' => now()]);
                }

                $top ??= $row->id;
                DB::table('shipping_rates')->where('method_id', $row->id)->delete();
                DB::table('shipping_rates')->insert(array_map(static fn (string $p): array => ['method_id' => $row->id, 'prefecture' => $p, 'fee' => $method['rates'][$p]], Regions::PREFECTURES));

                $old = $before->get($row->id);
                $now = ['id' => $row->id, 'name' => $method['name'], 'position' => $index + 1, 'free_over' => $method['free_over'], 'rates' => $method['rates']];

                if ($old !== $now) {
                    $changed[] = ['id' => $row->id, 'name' => $method['name'], 'free_over' => $method['free_over'], 'added' => $old === null];
                }
            }

            if ($changed === [] && $removed === []) {
                return;
            }

            // 額そのものは表が持つ。記録には変わった方法と名前・無料の線だけ（個人情報は無い）。集約はいちばん上の方法
            $this->events->append('shop.shipping_methods_updated', 'shipping_method', (string) $top, ['changed' => $changed, 'removed' => $removed], $actor);
            event(new ShippingPolicyChanged($actor ?? 'system'));
        });

        return $this->all();
    }

    /**
     * いちばん上の方法を全国同じ額にする（運営の近道。入れたときのサンプル・テスト。ShopSettingsService::setShipping から）
     */
    public function setFlat(int $fee, ?int $freeOver, ?string $actor = null): void
    {
        $methods = $this->all();
        $first = $methods[0] ?? ['id' => null, 'name' => '通常便'];
        $methods[0] = ['id' => $first['id'], 'name' => $first['name'], 'free_over' => $freeOver, 'rates' => Regions::flat($fee)];

        $this->replace(['methods' => array_map(static fn (array $m): array => ['id' => $m['id'], 'name' => $m['name'], 'free_over' => $m['free_over'], 'rates' => $m['rates']], $methods)], $actor);
    }

    /**
     * まだ送料を決めていない店か（方法が 1 つで、全部 0 円・無料の線なし。入れたときのサンプルが送料を入れてよいか）
     */
    public function unset(): bool
    {
        $methods = $this->all();

        return count($methods) <= 1 && ($methods === [] || ($methods[0]['free_over'] === null && max($methods[0]['rates']) === 0));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{id: string|null, name: string, free_over: int|null, rates: array<string, int>}>
     */
    private static function validate(array $input): array
    {
        $yen = static fn (int $max, int $min): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($max, $min): void {
            // 小数・指数・真偽を受けない（整数の円だけ）
            if (! (is_int($value) || (is_string($value) && preg_match('/\A\d{1,9}\z/', $value) === 1)) || (int) $value < $min || (int) $value > $max) {
                $fail(':attribute は '.number_format($min).'〜'.number_format($max).' の整数（円）で入力してください。');
            }
        };

        $data = Validator::make($input, [
            'methods' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_METHODS],
            'methods.*' => ['array:id,name,free_over,rates'],
            'methods.*.id' => ['present', 'nullable', 'string', 'regex:/\A[0-9a-z]{26}\z/'],
            'methods.*.name' => ['required', 'string', 'max:'.self::MAX_NAME, 'not_regex:/[\x00-\x1F\x7F]/', 'not_regex:/\A\s*\z/'],
            'methods.*.free_over' => ['present', 'nullable', $yen(self::MAX_FREE_OVER, 1)],
            'methods.*.rates' => ['required', 'array'],
            'methods.*.rates.*' => ['required', $yen(self::MAX_FEE, 0)],
        ], attributes: [
            'methods' => '配送の方法', 'methods.*.name' => '方法の名前', 'methods.*.free_over' => '送料無料になる金額', 'methods.*.rates.*' => '送料',
        ])->validate();

        $out = [];
        $names = [];

        foreach ($data['methods'] as $index => $method) {
            $rates = (array) $method['rates'];

            if (count($rates) !== count(Regions::PREFECTURES) || array_diff(Regions::PREFECTURES, array_keys($rates)) !== []) {
                throw ValidationException::withMessages(["methods.{$index}.rates" => '47 都道府県すべての送料を入力してください。']);
            }

            $name = trim((string) $method['name']);
            $key = mb_strtolower($name);

            if (isset($names[$key])) {
                throw ValidationException::withMessages(["methods.{$index}.name" => '同じ名前の配送の方法があります。']);
            }

            $names[$key] = true;
            $table = [];
            foreach (Regions::PREFECTURES as $prefecture) {
                $table[$prefecture] = (int) $rates[$prefecture];
            }

            $id = $method['id'] ?? null;
            $out[] = ['id' => is_string($id) ? $id : null, 'name' => $name, 'free_over' => $method['free_over'] === null ? null : (int) $method['free_over'], 'rates' => $table];
        }

        $ids = array_filter(array_column($out, 'id'));
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['methods' => '同じ配送の方法が 2 回あります。']);
        }

        return $out;
    }
}
