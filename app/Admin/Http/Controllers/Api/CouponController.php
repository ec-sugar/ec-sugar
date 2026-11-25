<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Requests\CouponRequest;
use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Core\Discount\Models\Coupon;
use App\Core\Discount\Services\CouponService;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * クーポンと自動の割引（EnterTenant の後。ADR 0054 の区切り 2・ADR 0065）: 一覧・1 件・作る・直す・止める・再開。同じ表・同じ画面で、method で見分ける。
 * 見るのは閲覧、変えるのは運用（EnterTenant の既定）。変えたことは店の台帳のイベント（actor つき）と中央の監査ログの両方に残す。
 * 値の幅は CouponRequest、コードの重なり・対象の ID・使われた後に変えない欄は CouponService が錠の中で確かめる。
 * 経路の ID はモデルに結びつけず、テナント文脈の中で探す（ほかの店のクーポンの ID は 404）
 */
final class CouponController
{
    private const int PER_PAGE = 50;

    public function __construct(
        private readonly CouponService $coupons,
        private readonly AuditLog $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'paused'])],
            'method' => ['sometimes', 'nullable', Rule::in(['code', 'automatic'])],
        ]);

        $query = Coupon::query();
        $q = trim((string) ($data['q'] ?? ''));

        if ($q !== '') {
            // コードは大文字で持つ。メモと自動の割引の名前は部分一致（% と _ は文字として）
            $like = '%'.addcslashes($q, '\\%_').'%';
            $query->where(static fn ($w) => $w->where('code', 'like', '%'.addcslashes(mb_strtoupper($q), '\\%_').'%')->orWhere('name', 'ilike', $like)->orWhere('label', 'ilike', $like));
        }

        if (isset($data['method'])) {
            $query->where('method', $data['method']);
        }

        $counts = (clone $query)->toBase()->selectRaw("count(*) AS total, count(*) FILTER (WHERE status = 'active') AS active, count(*) FILTER (WHERE status = 'paused') AS paused")->first();

        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? self::PER_PAGE));
        /** @var list<Coupon> $items */
        $items = $page->items();
        $used = $this->coupons->usedCounts(array_map(static fn (Coupon $c): string => $c->id, $items));
        $now = CarbonImmutable::now();

        return response()->json([
            'coupons' => array_map(fn (Coupon $c): array => $this->present($c, $used[$c->id] ?? 0, $now), $items),
            'meta' => ['total' => $page->total(), 'per_page' => $page->perPage(), 'page' => $page->currentPage()],
            'counts' => ['all' => (int) ($counts->total ?? 0), 'active' => (int) ($counts->active ?? 0), 'paused' => (int) ($counts->paused ?? 0)],
        ]);
    }

    public function show(string $couponId): JsonResponse
    {
        return response()->json(['coupon' => $this->detail($this->find($couponId))]);
    }

    public function store(CouponRequest $request): JsonResponse
    {
        $coupon = $this->coupons->create($request->values(), TenantActor::of($request));
        $this->record($request, $coupon, 'created');

        return response()->json(['coupon' => $this->detail($coupon)], 201);
    }

    public function update(CouponRequest $request, string $couponId): JsonResponse
    {
        $coupon = $this->coupons->update(strtolower($couponId), $request->values(), TenantActor::of($request));
        $this->record($request, $coupon, 'updated');

        return response()->json(['coupon' => $this->detail($coupon)]);
    }

    public function pause(Request $request, string $couponId): JsonResponse
    {
        $coupon = $this->coupons->setStatus(strtolower($couponId), 'paused', TenantActor::of($request));
        $this->record($request, $coupon, 'paused');

        return response()->json(['coupon' => $this->detail($coupon)]);
    }

    public function resume(Request $request, string $couponId): JsonResponse
    {
        $coupon = $this->coupons->setStatus(strtolower($couponId), 'active', TenantActor::of($request));
        $this->record($request, $coupon, 'resumed');

        return response()->json(['coupon' => $this->detail($coupon)]);
    }

    /**
     * 1 件の答え（一覧の形に、変えられない欄の印と対象の名前を足す）
     *
     * @return array<string, mixed>
     */
    private function detail(Coupon $coupon): array
    {
        $coupon->refresh();
        $used = $this->coupons->usedCounts([$coupon->id])[$coupon->id] ?? 0;
        $names = match ($coupon->target) {
            'categories' => DB::table('categories')->whereIn('id', $coupon->target_ids)->pluck('name', 'id')->all(),
            'listings' => DB::table('listings')->whereIn('id', $coupon->target_ids)->pluck('title', 'id')->all(),
            default => [],
        };

        return $this->present($coupon, $used, CarbonImmutable::now()) + [
            // 一度でも注文に使われたら、種類・値・上限の額・対象は変えられない（CouponService::FIXED_ONCE_USED）
            'locked' => $this->coupons->everUsed($coupon),
            // 対象の名前（消したカテゴリ・商品は null）
            'targets' => array_map(static fn (string $id): array => ['id' => $id, 'name' => isset($names[$id]) ? (string) $names[$id] : null], $coupon->target_ids),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Coupon $coupon, int $used, CarbonImmutable $now): array
    {
        return [
            'id' => $coupon->id,
            'method' => $coupon->method,
            'code' => $coupon->code,
            'label' => $coupon->label,
            'name' => $coupon->name,
            'kind' => $coupon->kind->value,
            'value' => $coupon->value,
            'max_discount' => $coupon->max_discount,
            'target' => $coupon->target,
            'target_ids' => $coupon->target_ids,
            'min_subtotal' => $coupon->min_subtotal,
            'min_quantity' => $coupon->min_quantity,
            'starts_at' => $coupon->starts_at?->toIso8601String(),
            'ends_at' => $coupon->ends_at?->toIso8601String(),
            'usage_limit' => $coupon->usage_limit,
            'per_customer_limit' => $coupon->per_customer_limit,
            'members_only' => $coupon->members_only,
            'status' => $coupon->status,
            'state' => self::state($coupon, $used, $now),
            'used' => $used,
            'created_at' => $coupon->getAttribute('created_at')?->toIso8601String(),
            'updated_at' => $coupon->getAttribute('updated_at')?->toIso8601String(),
        ];
    }

    /**
     * 今ショップで使えるか（止めた・始まる前・終わった・回数の上限・使える）。会員だけ・最低の購入額など、カートで決まる条件は見ない
     */
    private static function state(Coupon $coupon, int $used, CarbonImmutable $now): string
    {
        return match (true) {
            $coupon->status === 'paused' => 'paused',
            $coupon->ends_at !== null && $now->gte($coupon->ends_at) => 'ended',
            $coupon->starts_at !== null && $now->lt($coupon->starts_at) => 'scheduled',
            $coupon->usage_limit !== null && $used >= $coupon->usage_limit => 'used_up',
            default => 'active',
        };
    }

    /**
     * 中央の監査ログに 1 行（誰が・どの店で・どのクーポンを・何をしたか）。コードは宣伝の物で秘密ではない（ADR 0054 の 8）。自動の割引はコードが null
     */
    private function record(Request $request, Coupon $coupon, string $action): void
    {
        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);
        $this->audit->record('operator.coupon_changed', $operator, ['coupon_id' => $coupon->id, 'code' => $coupon->code, 'action' => $action], (string) Tenant::current()->getTenantKey(), $request->ip());
    }

    private function find(string $couponId): Coupon
    {
        return Coupon::query()->findOrFail(strtolower($couponId));
    }
}
