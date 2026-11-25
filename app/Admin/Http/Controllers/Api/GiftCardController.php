<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Middleware\EnterTenant;
use App\Admin\Http\Presenters\TransactionPresenter;
use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorAccess;
use App\Core\GiftCard\Models\GiftCard;
use App\Core\GiftCard\Models\GiftCardEntry;
use App\Core\GiftCard\Services\GiftCardException;
use App\Core\GiftCard\Services\GiftCardService;
use App\Core\Transaction\Enums\Phase;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 設定 › ギフトカード（ADR 0076）。発行したカードの一覧と 1 枚の帳簿・止める・手で足し引き・未使用の残高の合計。
 *
 * - 見る（index・show）: 末尾 4 文字・額面・残高・期限・様子・帳簿。コードそのものは持っていないので返せない。閲覧の段階から。
 *   店の人の手の足し引きの理由（自由な文）は運用の段階からだけ。未使用の残高の合計（店の負債。資金決済法の判断のため）はお金の段階の人にだけ（売上と同じ段）
 * - 止める・再開（stop・resume）: 盗難の申し出など。運用の段階から。中央の監査ログに残す
 * - 足す・引く（adjust）: お金の段階で、直前のパスキーの再確認の後（経路の印。ADR 0024）。理由は 200 文字まで。中央の監査ログに額だけを残す（理由は残さない）
 * - 注文に使った額をカードへ戻す（releaseForOrder）: 外でした返金の後に、店の人が額を選んで（自動では戻さない）。お金の段階で直前の再確認の後。中央の監査ログに額だけ
 */
final class GiftCardController
{
    private const int PER_PAGE = 50;

    private const int HISTORY = 100;

    private const array STATES = ['all', 'active', 'stopped', 'expired', 'empty'];

    public function __construct(private readonly GiftCardService $giftCards) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => ['nullable', 'string', 'in:'.implode(',', self::STATES)],
            'last4' => ['nullable', 'string', 'regex:/\A[2-9A-HJKMNP-Za-hjkmnp-z]{4}\z/'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $now = CarbonImmutable::now();
        $state = (string) ($data['state'] ?? 'all');
        $latest = DB::table('gift_card_entries')->selectRaw('DISTINCT ON (gift_card_id) gift_card_id, balance_after')->orderBy('gift_card_id')->orderByDesc('card_seq');
        $query = GiftCard::query()->leftJoinSub($latest, 'latest', 'latest.gift_card_id', '=', 'gift_cards.id')
            ->select(['gift_cards.*', DB::raw('COALESCE(latest.balance_after, 0) AS balance')]);

        match ($state) {
            'stopped' => $query->whereNotNull('gift_cards.stopped_at'),
            'expired' => $query->whereNull('gift_cards.stopped_at')->where('gift_cards.expires_at', '<=', $now),
            'empty' => $query->whereNull('gift_cards.stopped_at')->where('gift_cards.expires_at', '>', $now)->whereRaw('COALESCE(latest.balance_after, 0) = 0'),
            'active' => $query->whereNull('gift_cards.stopped_at')->where('gift_cards.expires_at', '>', $now)->whereRaw('COALESCE(latest.balance_after, 0) > 0'),
            default => null,
        };

        if (isset($data['last4'])) {
            $query->where('gift_cards.last4', strtoupper((string) $data['last4']));
        }

        $page = $query->orderByDesc('gift_cards.created_at')->orderByDesc('gift_cards.id')->paginate(self::PER_PAGE, ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'cards' => collect($page->items())->map(fn (GiftCard $card): array => $this->row($card, (int) $card->getAttribute('balance'), $now))->values(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'outstanding' => $this->level($request) === 'money' ? $this->giftCards->outstanding($now) : null,
            'can_stop' => $this->atLeast($request, 'operate'),
            'can_adjust' => $this->level($request) === 'money',
        ]);
    }

    public function show(Request $request, string $cardId): JsonResponse
    {
        $card = $this->find($cardId);
        $notes = $this->atLeast($request, 'operate');

        return response()->json(['card' => [
            ...$this->row($card, $this->giftCards->balance($card->id), CarbonImmutable::now()),
            'history' => $this->giftCards->history($card->id, self::HISTORY)->map(static fn (GiftCardEntry $e): array => [
                'id' => $e->id,
                'kind' => $e->kind,
                'amount' => $e->amount,
                'balance_after' => $e->balance_after,
                'transaction_id' => $e->transaction_id,
                'reason' => $notes ? $e->note : null,
                'by_operator' => str_starts_with((string) $e->actor, 'operator:'),
                'occurred_at' => $e->occurred_at->toIso8601String(),
            ])->values(),
        ]]);
    }

    public function stop(Request $request, string $cardId, AuditLog $audit): JsonResponse
    {
        return $this->setStopped($request, $cardId, true, $audit);
    }

    public function resume(Request $request, string $cardId, AuditLog $audit): JsonResponse
    {
        return $this->setStopped($request, $cardId, false, $audit);
    }

    /**
     * 手で足す・引く（お金の段階・直前の再確認）。残高より多くは引けない・期限の過ぎたカードには足せない（422）
     */
    public function adjust(Request $request, string $cardId, AuditLog $audit): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'between:-'.GiftCardService::MAX_AMOUNT.','.GiftCardService::MAX_AMOUNT],
            'reason' => ['required', 'string', 'max:'.GiftCardService::REASON_MAX],
        ], attributes: ['amount' => '調整する額', 'reason' => '理由']);
        $card = $this->find($cardId);
        $entry = $this->giftCards->adjust($card, (int) $data['amount'], (string) $data['reason'], TenantActor::of($request));

        // 理由（自由な文）は中央に残さない。だれが・どのカードに・いくら
        $audit->record('operator.gift_card_adjusted', $this->operator(), ['gift_card_id' => $card->id, 'amount' => $entry->amount], (string) Tenant::current()->getTenantKey(), $request->ip());

        return response()->json(['balance' => $entry->balance_after], 201);
    }

    /**
     * 注文に使ったギフトカードの額を、店の人が選んでカードへ戻す（理由 refund で 1 回。ADR 0076）。払う前の注文（自動で戻る）・戻せる額の無い注文・戻せる額を超える額は 422
     */
    public function releaseForOrder(Request $request, string $transactionId, AuditLog $audit, TransactionPresenter $presenter): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1', 'max:'.GiftCardService::MAX_AMOUNT]], attributes: ['amount' => 'カードへ戻す額']);
        $transaction = Transaction::query()->findOrFail(strtolower($transactionId));
        $releasable = $this->giftCards->releasable($transaction->id);

        if ($transaction->kind !== 'sale' || $transaction->phase === Phase::Agreement || $releasable < 1) {
            throw new GiftCardException('この注文には、カードへ戻せるギフトカードの額がありません。');
        }

        if ((int) $data['amount'] > $releasable) {
            throw new GiftCardException('カードへ戻せるのは ¥'.number_format($releasable).' までです。');
        }

        $back = $this->giftCards->release($transaction->id, TenantActor::of($request), 'refund', (int) $data['amount']);

        if ($back < 1) {
            throw new GiftCardException('この注文のギフトカードの額は、すでにカードの残高に戻されています。');
        }

        $audit->record('operator.gift_card_released', $this->operator(), ['transaction_id' => $transaction->id, 'amount' => $back], (string) Tenant::current()->getTenantKey(), $request->ip());

        return response()->json(['transaction' => $presenter->present(Transaction::query()->findOrFail($transaction->id))]);
    }

    private function setStopped(Request $request, string $cardId, bool $stop, AuditLog $audit): JsonResponse
    {
        $card = $this->find($cardId);
        $card = $stop ? $this->giftCards->stop($card, TenantActor::of($request)) : $this->giftCards->resume($card, TenantActor::of($request));
        $audit->record($stop ? 'operator.gift_card_stopped' : 'operator.gift_card_resumed', $this->operator(), ['gift_card_id' => $card->id], (string) Tenant::current()->getTenantKey(), $request->ip());

        return response()->json(['card' => $this->row($card, $this->giftCards->balance($card->id), CarbonImmutable::now())]);
    }

    /**
     * @return array{id: string, last4: string, amount: int, balance: int, expires_at: string, state: string, stopped_at: string|null, stopped_reason: string|null, test: bool, mailed: bool, purchase_transaction_id: string|null, created_at: string}
     */
    private function row(GiftCard $card, int $balance, CarbonImmutable $now): array
    {
        return [
            'id' => $card->id,
            'last4' => $card->last4,
            'amount' => $card->amount,
            'balance' => $balance,
            'expires_at' => $card->expires_at->toIso8601String(),
            'state' => match (true) {
                $card->stopped_at !== null => 'stopped',
                $card->expired($now) => 'expired',
                $balance <= 0 => 'empty',
                default => 'active',
            },
            'stopped_at' => $card->stopped_at?->toIso8601String(),
            // staff（店の人）・refund（売った注文が返金されて自動で止めた）
            'stopped_reason' => $card->stopped_reason,
            // テストの支払いで売ったカード（本番の鍵の店では使えない）
            'test' => $card->test,
            'mailed' => $card->mailed_at !== null,
            'purchase_transaction_id' => $card->purchase_transaction_id,
            'created_at' => $card->created_at->toIso8601String(),
        ];
    }

    private function find(string $cardId): GiftCard
    {
        return GiftCard::query()->findOrFail(strtolower($cardId));
    }

    private function level(Request $request): ?string
    {
        $level = $request->attributes->get(EnterTenant::LEVEL);

        return is_string($level) ? $level : null;
    }

    private function atLeast(Request $request, string $level): bool
    {
        return (OperatorAccess::LEVELS[$this->level($request) ?? ''] ?? 0) >= OperatorAccess::LEVELS[$level];
    }

    private function operator(): Operator
    {
        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);

        return $operator;
    }
}
