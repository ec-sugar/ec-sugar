<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\Middleware\EnterTenant;
use App\Admin\Jobs\SendShopTestMail;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorAccess;
use App\Core\Mail\MailRecords;
use App\Core\Mail\MailTag;
use App\Core\Mail\Models\MailRecord;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Plugin\PluginRegistry;
use App\Core\RateLimit\Throttle;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 設定 › メールの記録（ADR 0095）。店が送ったメールの一覧と 1 通の様子、送れなかった理由、試しのメール。
 *
 * - 見る（index・show）: 種類・件名・状態・理由・時刻・関係する注文とお客さん。閲覧の段階から。宛先のメールアドレスは返さない
 * - 宛先（personal）: お客さんの「名前と連絡先」と同じ運用の段階から。見たことを中央の監査ログに残す（宛先は残さない）
 * - 試しのメール（test）: ログイン中の運営のアドレスへ 1 通。運用の段階から。運営ごとに 10 分に 3 通・店で 1 日 20 通まで
 */
final class MailRecordController
{
    private const int PER_PAGE = 50;

    private const array STATUSES = ['all', 'failed', 'sending', 'sent'];

    /** 試しのメールの上限（運営ごと・店ごと） */
    public const int TEST_PER_OPERATOR = 3;

    public const int TEST_PER_OPERATOR_SECONDS = 600;

    public const int TEST_PER_SHOP = 20;

    /** 核のメールの名前（App のメールは App の名前で出す） */
    private const array CORE_LABELS = [
        'core.order_paid' => 'ご注文の確認',
        'core.order_awaiting_payment' => 'ご注文の確認（お支払い待ち）',
        'core.order_payment_lapsed' => 'お支払いの期限切れ',
        'core.order_shipped' => '発送のお知らせ',
        'core.order_refunded' => '返金のお知らせ',
        'core.login_code' => '会員のログインのコード',
        'core.email_changed' => 'メールアドレスの変更',
        'core.account_notice' => '会員のアカウントの操作',
        'core.test' => 'テストメール',
    ];

    public function __construct(private readonly PluginRegistry $plugins) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', self::STATUSES)],
            'kind' => ['nullable', 'string', 'max:64', 'regex:'.MailTag::KIND_PATTERN],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $status = (string) ($data['status'] ?? 'all');
        $query = MailRecord::query()
            ->when($status !== 'all', static fn ($q) => $q->where('status', $status))
            ->when(isset($data['kind']), static fn ($q) => $q->where('kind', (string) $data['kind']));
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(self::PER_PAGE, ['*'], 'page', (int) ($data['page'] ?? 1));
        $now = CarbonImmutable::now();

        return response()->json([
            'records' => collect($page->items())->map(fn (MailRecord $record): array => $this->row($record, $now))->values(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            // 絞り込みの候補（記録にある種類だけ）
            'kinds' => MailRecord::query()->distinct()->orderBy('kind')->pluck('kind')
                ->map(fn (string $kind): array => ['value' => $kind, 'label' => $this->label($kind)])->values(),
            // この 7 日に送れなかった数（画面の上の知らせ）
            'failed_recently' => MailRecord::query()->where('status', 'failed')->where('updated_at', '>=', $now->subDays(7)->utc())->count(),
            'keep_days' => MailRecords::KEEP_DAYS,
            'can_see_recipients' => $this->atLeast($request, 'operate'),
            'can_send_test' => $this->atLeast($request, 'operate'),
        ]);
    }

    public function show(string $recordId): JsonResponse
    {
        $record = $this->find($recordId);
        // 宛先がお客さんの台帳にあれば、その人の画面へ（メールの完全一致。名前もアドレスも返さない）
        $customer = $record->recipient_index === null ? null
            : CustomerAccount::query()->where('email_index', $record->recipient_index)->value('id');

        return response()->json(['record' => [
            ...$this->row($record, CarbonImmutable::now()),
            'customer_id' => is_string($customer) ? $customer : null,
            'trace_id' => $record->trace_id,
        ]]);
    }

    /**
     * 宛先のメールアドレス（運用の段階から。経路の印）。見たことを中央の監査ログに残す
     */
    public function personal(Request $request, string $recordId, AuditLog $audit): JsonResponse
    {
        $record = $this->find($recordId);
        $audit->record('operator.personal_data_viewed', $this->operator(), ['subject' => 'mail_record', 'mail_record_id' => $record->id], (string) Tenant::current()->getTenantKey(), $request->ip());

        return response()->json(['personal' => ['recipients' => $record->recipients['addresses'] ?? []]]);
    }

    /**
     * 試しのメールを、ログイン中の運営のアドレスへ送る（キューを通す）。数えてから比べる（同時に押しても上限を越えない）
     */
    public function test(): JsonResponse
    {
        $operator = $this->operator();
        $over = Throttle::firstOver([
            [Throttle::shopKey('mail-test', (string) $operator->getKey()), self::TEST_PER_OPERATOR, self::TEST_PER_OPERATOR_SECONDS],
            [Throttle::shopKey('mail-test'), self::TEST_PER_SHOP, 86400],
        ]);

        if ($over !== null) {
            return response()->json(['message' => 'テストメールは、少し時間を置いてから送ってください（1 人 10 分に 3 通・店で 1 日 20 通まで）。'], 429)
                ->header('Retry-After', (string) max(1, Throttle::availableIn($over)));
        }

        SendShopTestMail::dispatch((string) $operator->email, CarbonImmutable::now()->timezone('Asia/Tokyo')->format('Y-m-d H:i'));

        return response()->json(['queued' => true], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MailRecord $record, CarbonImmutable $now): array
    {
        return [
            'id' => $record->id,
            'kind' => $record->kind,
            'kind_label' => $this->label($record->kind),
            'subject' => $record->subject,
            'status' => $record->status,
            // 送っているのまま 1 時間を過ぎた（送れたか分からない）
            'stale' => $record->status === 'sending' && $record->updated_at->lt($now->subMinutes(MailRecords::STALE_MINUTES)),
            'reason' => $record->reason,
            'smtp_code' => $record->smtp_code,
            'smtp_status' => $record->smtp_status,
            'attempts' => $record->attempts,
            'recipient_count' => $record->recipient_count,
            'transaction_id' => $record->transaction_id,
            'created_at' => $record->created_at->toIso8601String(),
            'updated_at' => $record->updated_at->toIso8601String(),
            'sent_at' => $record->sent_at?->toIso8601String(),
        ];
    }

    private function label(string $kind): string
    {
        if (isset(self::CORE_LABELS[$kind])) {
            return self::CORE_LABELS[$kind];
        }

        $app = explode('.', $kind, 2)[0];

        return $app !== 'core' && $this->plugins->has($app) ? 'App「'.$this->plugins->get($app)->title.'」' : 'そのほかのメール';
    }

    private function find(string $recordId): MailRecord
    {
        return MailRecord::query()->findOrFail(strtolower($recordId));
    }

    private function atLeast(Request $request, string $level): bool
    {
        $current = $request->attributes->get(EnterTenant::LEVEL);

        return (OperatorAccess::LEVELS[is_string($current) ? $current : ''] ?? 0) >= OperatorAccess::LEVELS[$level];
    }

    private function operator(): Operator
    {
        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);

        return $operator;
    }
}
