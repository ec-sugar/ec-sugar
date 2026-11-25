<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Admin\Agent\AgentTokens;
use App\Admin\Models\Operator;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 運営のログイン中のセッション（ADR 0083）。
 *
 * ログインごとに表 operator_sessions に 1 行を作り、セッションには行の ID（SESSION_KEY）だけを置く。EnsureOperatorIsActive が
 * 毎リクエストで行が生きているかを確かめるので、行を終えればそのセッションは次のリクエストで締め出される。
 * キャッシュ（RevokedSessions）と違い、Redis が消えても忘れない。終える時:
 *
 * - ログアウト（logout）・一覧から 1 つ（ended_by_self）・ほかを全部（others）
 * - パスキーを消した: そのパスキーで入ったセッション（passkey_removed。盗まれた端末のパスキーを消せば、その端末の画面も切れる）
 * - 運営を止めた（disabled）・絶対期限を過ぎた（expired）
 */
final class OperatorSessions
{
    public const string SESSION_KEY = 'admin.operator_session';

    /** 最後に使った時刻を書き直す間隔（秒）。毎リクエストで書かない */
    private const int TOUCH_SECONDS = 60;

    public function __construct(private readonly AuditLog $audit) {}

    /**
     * ログインした時に呼ぶ（セッションを作り直した後）。行を作り、その ID をセッションに置く
     */
    public function start(Request $request, Operator $operator, ?string $passkeyId, bool $unusual = false): string
    {
        $id = (string) Str::ulid();
        $now = CarbonImmutable::now();

        $this->db()->table('operator_sessions')->insert([
            'id' => $id,
            'operator_id' => $operator->id,
            'passkey_id' => $passkeyId,
            'device' => self::device($request->userAgent()),
            'unusual' => $unusual,
            'created_at' => $now,
            'last_seen_at' => $now,
        ]);
        $request->session()->put(self::SESSION_KEY, $id);

        return $id;
    }

    /**
     * このセッションの行が生きているか（無い・終えた・ほかの運営の行なら false）。生きていれば最後に使った時刻を進める
     */
    public function alive(Request $request, Operator $operator): bool
    {
        $id = $request->session()->get(self::SESSION_KEY);

        if (! is_string($id) || ! Str::isUlid($id)) {
            return false;
        }

        $row = $this->db()->table('operator_sessions')->where('id', $id)->first(['operator_id', 'ended_at', 'last_seen_at']);

        if ($row === null || $row->operator_id !== $operator->id || $row->ended_at !== null) {
            return false;
        }

        $now = CarbonImmutable::now();

        if ($now->getTimestamp() - CarbonImmutable::parse((string) $row->last_seen_at)->getTimestamp() >= self::TOUCH_SECONDS) {
            $this->db()->table('operator_sessions')->where('id', $id)->whereNull('ended_at')->update(['last_seen_at' => $now]);
        }

        return true;
    }

    public function current(Request $request): ?string
    {
        $id = $request->session()->get(self::SESSION_KEY);

        return is_string($id) && Str::isUlid($id) ? $id : null;
    }

    /**
     * 今のセッションが、いつもと違う接続元から入ったものか（ADR 0083 の印）。行が無ければ false。
     * 支払いの設定の鍵を変えた時の記録に写す（おかしな動きの知らせ。ADR 0103）
     */
    public function unusual(Request $request): bool
    {
        $id = $request->hasSession() ? $this->current($request) : null;

        return $id !== null && (bool) $this->db()->table('operator_sessions')->where('id', $id)->value('unusual');
    }

    /**
     * 1 つを終える（自分の行だけ）。終えたら true
     */
    public function end(Operator $operator, string $id, string $reason): bool
    {
        return $this->db()->table('operator_sessions')
            ->where('id', $id)->where('operator_id', $operator->id)->whereNull('ended_at')
            ->update(['ended_at' => CarbonImmutable::now(), 'end_reason' => $reason]) === 1;
    }

    /**
     * その運営のセッションを全部終える（$except は残す）。終えた数
     */
    public function endAll(Operator $operator, string $reason, ?string $except = null): int
    {
        $ended = $this->db()->table('operator_sessions')
            ->where('operator_id', $operator->id)->whereNull('ended_at')
            ->when($except !== null, static fn ($query) => $query->where('id', '<>', $except))
            ->update(['ended_at' => CarbonImmutable::now(), 'end_reason' => $reason]);
        // AI エージェントの鍵も一緒に切る（盗まれたセッションから作られた鍵を残さない。ADR 0100、見直しの中 1）
        app(AgentTokens::class)->revokeAllFor($operator, 'sessions_'.$reason);

        return $ended;
    }

    /**
     * そのパスキーで入ったセッションを終える（$except は残す。消した本人が今使っている画面）。終えた数
     */
    public function endForPasskey(Operator $operator, string $passkeyId, ?string $except = null): int
    {
        // AI エージェントの鍵も一緒に切る（どのパスキーのセッションで作ったかは持たないので、本人の鍵を全部。ADR 0100）
        app(AgentTokens::class)->revokeAllFor($operator, 'passkey_removed');

        return $this->db()->table('operator_sessions')
            ->where('operator_id', $operator->id)->where('passkey_id', $passkeyId)->whereNull('ended_at')
            ->when($except !== null, static fn ($query) => $query->where('id', '<>', $except))
            ->update(['ended_at' => CarbonImmutable::now(), 'end_reason' => 'passkey_removed']);
    }

    /**
     * 本人のログイン中のセッション（新しい順）。入ったパスキーの名前を添える
     *
     * @return list<array{id: string, device: string, passkey: ?string, unusual: bool, created_at: string, last_seen_at: string, current: bool}>
     */
    public function active(Operator $operator, ?string $current): array
    {
        $maxAge = (int) config('admin.session_max_hours') * 3600;

        return $this->db()->table('operator_sessions as s')
            ->leftJoin('operator_passkeys as p', 'p.id', '=', 's.passkey_id')
            ->where('s.operator_id', $operator->id)->whereNull('s.ended_at')
            // 絶対期限を過ぎた行は、次のリクエストを待たずに一覧から外す（終えるのは EnsureOperatorIsActive）
            ->where('s.created_at', '>=', CarbonImmutable::now()->subSeconds($maxAge))
            ->orderByDesc('s.created_at')
            ->get(['s.id', 's.device', 'p.name as passkey', 's.unusual', 's.created_at', 's.last_seen_at'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'device' => (string) $row->device,
                'passkey' => is_string($row->passkey) ? $row->passkey : null,
                'unusual' => (bool) $row->unusual,
                'created_at' => CarbonImmutable::parse((string) $row->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
                'last_seen_at' => CarbonImmutable::parse((string) $row->last_seen_at)->utc()->format('Y-m-d\TH:i:s\Z'),
                'current' => $row->id === $current,
            ])
            ->values()
            ->all();
    }

    /**
     * ホームの知らせ: 今の画面でない、いつもと違う接続元から入ったセッションが生きていれば、その 1 つ
     *
     * @return array{id: string, device: string, created_at: string}|null
     */
    public function unusualOther(Operator $operator, ?string $current): ?array
    {
        foreach ($this->active($operator, $current) as $session) {
            if ($session['unusual'] && ! $session['current']) {
                return ['id' => $session['id'], 'device' => $session['device'], 'created_at' => $session['created_at']];
            }
        }

        return null;
    }

    /**
     * 終えたことを監査ログに残す（数と理由だけ）
     */
    public function record(Operator $operator, string $reason, int $count, ?string $ip): void
    {
        if ($count > 0) {
            $this->audit->record('operator.sessions_ended', $operator, ['reason' => $reason, 'count' => $count], ip: $ip);
        }
    }

    /**
     * 端末の種類（ブラウザと OS だけ。版や細かい印は置かない）
     */
    public static function device(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        // iPhone・iPad の UA にも「Mac OS X」が入るので先に見る
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown',
        };

        return "{$browser} / {$os}";
    }

    private function db(): ConnectionInterface
    {
        return DB::connection((new Operator)->getConnectionName());
    }
}
