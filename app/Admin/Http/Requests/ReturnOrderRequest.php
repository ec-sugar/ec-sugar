<?php

declare(strict_types=1);

namespace App\Admin\Http\Requests;

use App\Core\Order\ReturnRequest;
use App\Core\Payment\Enums\ReturnReason;
use App\Core\Transaction\Totals;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 返品・一部返金の入力（確かめの画面と実行で同じ。ADR 0048）。品の額はサーバーが明細から決める（送れるのは、品の返す額を下げる額と金額だけの返金の額）。
 * 行の数・数量・金額に上限を付ける。外でした返金の理由（external）は店の人が選ばない
 */
final class ReturnOrderRequest extends FormRequest
{
    public const int MAX_AMOUNT = 100_000_000;

    public function authorize(): bool
    {
        return true; // 認可は経路のミドルウェア（auth:operator ＋ EnterTenant ＋ お金の段階）
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:'.Totals::MAX_LINES],
            'items.*.position' => ['required', 'integer', 'min:1', 'max:65535', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.restock' => ['sometimes', 'boolean'],
            'items.*.addons' => ['sometimes', 'boolean'],
            'shipping' => ['sometimes', 'boolean'],
            'amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT],
            // 品の返す額（空なら既定の「品の額と返せる残りの小さい方」。0 から既定まで。上限はサーバーが明細と残りから確かめる）
            'goods_amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT],
            'reason' => ['required', 'string', Rule::in(array_map(static fn (ReturnReason $r): string => $r->value, ReturnReason::choosable()))],
            'channel' => ['required', 'string', 'in:provider,outside'],
            // 確かめの画面で見せた合計（実行の時は必ず。作り直した合計と違えば断る。0 円の品だけの返品は 0）
            'expected_total' => [str_ends_with($this->path(), '/preview') ? 'nullable' : 'required', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['items' => '返品する商品', 'items.*.quantity' => '返品する数', 'amount' => '金額だけの返金', 'goods_amount' => '商品の返金額', 'reason' => '理由', 'channel' => '返金の方法'];
    }

    public function toReturnRequest(): ReturnRequest
    {
        $items = [];

        foreach ((array) $this->validated('items', []) as $item) {
            $items[(int) $item['position']] = [
                'quantity' => (int) $item['quantity'],
                'restock' => (bool) ($item['restock'] ?? false),
                'addons' => (bool) ($item['addons'] ?? false),
            ];
        }

        /** @var 'provider'|'outside' $channel */
        $channel = (string) $this->validated('channel');

        $goods = $this->validated('goods_amount');

        return new ReturnRequest($items, (bool) $this->validated('shipping', false), (int) ($this->validated('amount') ?? 0), ReturnReason::from((string) $this->validated('reason')), $channel,
            $goods === null ? null : (int) $goods);
    }

    public function expectedTotal(): ?int
    {
        $total = $this->validated('expected_total');

        return $total === null ? null : (int) $total;
    }
}
