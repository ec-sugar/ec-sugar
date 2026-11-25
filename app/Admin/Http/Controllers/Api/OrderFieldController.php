<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Requests\OrderFieldOrderRequest;
use App\Admin\Http\Requests\OrderFieldRequest;
use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Core\Order\Enums\OrderFieldType;
use App\Core\Order\Models\OrderField;
use App\Core\Order\Services\OrderFields;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 設定 › 注文の項目（EnterTenant の後。ADR 0066）: 一覧・足す・直す・消す・並べ替える。
 * 読むのは閲覧から、変えるのは運用から（EnterTenant の既定。ADR 0024 の設定の画面と同じ）。
 * 入力の形は FormRequest（OrderFieldRequest・OrderFieldOrderRequest）。変えたことは店の記録（order_field.*。actor つき）と中央の監査ログの両方に残す（何も変えない保存は残さない）。形の検査と上限（使う欄は 10 個・全部で 50 個）・
 * 使われた欄を消さない決まりは OrderFields が錠の中で確かめる。経路の ID はモデルに結びつけず、テナント文脈の中で探す（ほかの店の欄の ID は 404）
 */
final class OrderFieldController
{
    public function __construct(
        private readonly OrderFields $fields,
        private readonly AuditLog $audit,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->present());
    }

    public function store(OrderFieldRequest $request): JsonResponse
    {
        $field = $this->fields->create($request->values(), TenantActor::of($request));
        $this->record($request, $field->id, 'created');

        return response()->json($this->present(), 201);
    }

    public function update(OrderFieldRequest $request, string $fieldId): JsonResponse
    {
        // 何も変えない保存は、店の記録にも監査ログにも残さない
        if ($this->fields->update(strtolower($fieldId), $request->values(), TenantActor::of($request))) {
            $this->record($request, strtolower($fieldId), 'updated');
        }

        return response()->json($this->present());
    }

    public function destroy(Request $request, string $fieldId): JsonResponse
    {
        $this->fields->delete(strtolower($fieldId), TenantActor::of($request));
        $this->record($request, strtolower($fieldId), 'deleted');

        return response()->json($this->present());
    }

    public function order(OrderFieldOrderRequest $request): JsonResponse
    {
        if ($this->fields->reorder($request->validated('ids'), TenantActor::of($request))) {
            $this->record($request, null, 'reordered');
        }

        return response()->json($this->present());
    }

    /**
     * 一覧（並びの順）と上限と種類の選択肢。used は注文に使われた欄（消せない。「使わない」にだけできる）
     *
     * @return array<string, mixed>
     */
    private function present(): array
    {
        $all = $this->fields->all();
        $used = $this->fields->used(array_map(static fn (OrderField $f): string => $f->id, $all));

        return [
            'fields' => array_map(static fn (OrderField $f): array => [
                'id' => $f->id,
                'name' => $f->name,
                'description' => $f->description,
                'type' => $f->type->value,
                'type_label' => $f->type->label(),
                'options' => $f->options,
                'required' => $f->required,
                'enabled' => $f->enabled,
                'used' => isset($used[$f->id]),
            ], $all),
            'types' => array_map(static fn (OrderFieldType $t): array => ['value' => $t->value, 'label' => $t->label(), 'max_length' => $t->maxLength()], OrderFieldType::cases()),
            'limits' => [
                'enabled' => OrderFields::MAX_ENABLED,
                'total' => OrderFields::MAX_TOTAL,
                'name' => OrderFields::NAME_MAX,
                'description' => OrderFields::DESCRIPTION_MAX,
                'options' => OrderFields::OPTIONS_MAX,
                'option' => OrderFields::OPTION_MAX,
            ],
        ];
    }

    /**
     * 中央の監査ログに 1 行（誰が・どの店で・どの欄を・何をしたか）。欄の名前と決まりは店の記録（order_field.*）に残る
     */
    private function record(Request $request, ?string $fieldId, string $action): void
    {
        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);
        $this->audit->record('operator.order_field_changed', $operator, ['field_id' => $fieldId, 'action' => $action], (string) Tenant::current()->getTenantKey(), $request->ip());
    }
}
