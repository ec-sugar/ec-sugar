<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Models\Operator;
use App\Admin\Models\OperatorEnrollment;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorAccess;
use App\Admin\Services\OperatorEnrollments;
use App\Admin\Services\OperatorRecoveryCodes;
use App\Admin\Services\OperatorSessions;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 運営アカウントの管理（ADR 0013）。アカウントの作成は CLI だけでできる。登録トークンは CLI のほか、オーナーがメンバーの画面で（スタッフの分）、本人が回復用のコードで（ADR 0038）出せる。
 *
 *   php artisan operator create   hana@example.com --name=Hana   作成して登録 URL を出す
 *   php artisan operator enroll   hana@example.com [--revoke]    パスキーを失くしたとき。--revoke で既存のパスキーを全部消す
 *   php artisan operator disable  hana@example.com               止める（次のリクエストで締め出される）
 *   php artisan operator enable   hana@example.com
 *   php artisan operator list
 *   php artisan operator role     hana@example.com owner        役割（owner / staff。ADR 0024）
 *   php artisan operator grant    staff@example.com shop1 operate  店ごとの権限（read / operate / money / none）
 *
 * 登録 URL は表示したこの端末にしか出ない。チャットやメールに貼らず、本人が開く。
 */
final class OperatorCommand extends Command
{
    /** 最初の運営をオーナーにするかを決める間のロック（中央 DB の advisory lock） */
    private const int CREATE_LOCK = 0x5E_C0_A1;

    protected $signature = 'operator
        {action : create | enroll | disable | enable | list | role | grant}
        {email? : 運営のメールアドレス}
        {value? : role のとき owner / staff、grant のときテナントの ID}
        {level? : grant のとき read / operate / money / none}
        {--name= : 表示名（create のとき）}
        {--revoke : enroll のとき、登録済みのパスキーをすべて削除する}';

    protected $description = '管理画面のメンバーの作成・登録トークンの発行・停止';

    public function handle(OperatorEnrollments $enrollments, AuditLog $audit, OperatorAccess $access): int
    {
        $action = (string) $this->argument('action');

        if ($action === 'list') {
            $this->table(['email', 'name', 'role', 'passkeys', 'status', 'last_login_at'], Operator::query()->withCount('passkeys')->get()->sortBy('email')->values()
                ->map(static fn (Operator $o): array => [
                    $o->email, $o->name, $o->role, $o->getAttribute('passkeys_count'), $o->isActive() ? 'active' : 'disabled', $o->last_login_at?->toIso8601String() ?? '-',
                ])->all());

            return self::SUCCESS;
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));

        if ($email === '') {
            $this->error('メールアドレスを指定してください');

            return self::INVALID;
        }

        $issuer = 'cli:'.(get_current_user() ?: 'unknown');

        switch ($action) {
            case 'create':
                $name = trim((string) $this->option('name'));

                if ($name === '' || mb_strlen($name) > 100) {
                    $this->error('--name に 1〜100 文字の表示名を指定してください');

                    return self::INVALID;
                }

                if (Operator::query()->whereEmail($email)->exists()) {
                    $this->error('そのメールアドレスのメンバーはもういます（パスキーの再発行は enroll）');

                    return self::FAILURE;
                }

                // 有効なオーナーが 1 人もいなければオーナーにする（入れたばかりのサーバーの最初の運営。スタッフのままだと何も見えない）。
                // 確かめと作成を 1 つのロックの中で（同時に 2 人を作っても、両方がオーナーを見落とさない）
                $operator = DB::connection((new Operator)->getConnectionName())->transaction(static function () use ($name, $email): Operator {
                    DB::connection((new Operator)->getConnectionName())->select('SELECT pg_advisory_xact_lock(?)', [self::CREATE_LOCK]);
                    $operator = new Operator(['name' => $name, 'email' => $email]);
                    $operator->role = Operator::query()->where('role', 'owner')->whereNull('disabled_at')->exists() ? 'staff' : 'owner';
                    $operator->save();

                    return $operator;
                });
                $audit->record('operator.created', $operator, ['by' => $issuer, 'role' => $operator->role]);
                $this->info("運営 {$operator->email} を作りました".($operator->role === 'owner' ? '（有効なオーナーがいなかったので、オーナーにしました）' : '（スタッフ。店ごとの権限は operator grant で）'));
                $this->printEnrollment($enrollments->issue($operator, $issuer));

                return self::SUCCESS;

            case 'enroll':
                $operator = $this->find($email);

                if ($operator === null || ! $operator->isActive()) {
                    $this->error('有効なメンバーが見つかりません');

                    return self::FAILURE;
                }

                if ($this->option('revoke')) {
                    // 乗っ取りの後始末: パスキーと一緒に回復用のコードも消す（乗っ取った人が作ったコードで戻らせない。ADR 0038）
                    [$count, $codes] = DB::connection($operator->getConnectionName())->transaction(static fn (): array => [
                        $operator->passkeys()->delete(),
                        OperatorRecoveryCodes::forget($operator, 'revoked'),
                    ]);
                    $audit->record('operator.passkeys_revoked', $operator, ['count' => $count, 'recovery_codes' => $codes, 'by' => $issuer]);
                    $this->warn("登録済みのパスキーを {$count} 本削除しました");
                }

                $this->printEnrollment($enrollments->issue($operator, $issuer));

                return self::SUCCESS;

            case 'disable':
            case 'enable':
                $operator = $this->find($email);

                if ($operator === null) {
                    $this->error('メンバーが見つかりません');

                    return self::FAILURE;
                }

                // 確かめと書き込みを 1 つのトランザクションで（2 人のオーナーを同時に止めて 0 人にしない）
                try {
                    DB::connection($operator->getConnectionName())->transaction(function () use ($operator, $action, $access, $audit, $issuer): void {
                        $locked = Operator::query()->whereKey($operator->id)->lockForUpdate()->firstOrFail();

                        if ($action === 'disable') {
                            $access->keepAnOwner($locked);
                        }

                        $locked->disabled_at = $action === 'disable' ? CarbonImmutable::now() : null;
                        $locked->save();

                        // 止めたら回復用のコードも消し、ログイン中のセッションも終える（有効に戻した時に、前のコードや画面で入れないように。ADR 0038）
                        if ($action === 'disable') {
                            OperatorRecoveryCodes::forget($locked, 'disabled');
                            app(OperatorSessions::class)->endAll($locked, 'disabled');
                        }
                        $audit->record("operator.{$action}d", $locked, ['by' => $issuer]);
                    });
                } catch (DomainException $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }
                $this->info("{$operator->email} を ".($action === 'disable' ? '止めました' : '戻しました'));

                return self::SUCCESS;

            case 'role':
            case 'grant':
                $operator = $this->find($email);

                if ($operator === null) {
                    $this->error('メンバーが見つかりません');

                    return self::FAILURE;
                }

                try {
                    if ($action === 'role') {
                        $access->setRole($operator, (string) $this->argument('value'), $issuer);
                        $this->info("{$operator->email} の役割を ".$this->argument('value').' にしました');
                    } else {
                        $tenant = Tenant::query()->find((string) $this->argument('value'));
                        $level = (string) $this->argument('level');

                        if ($tenant === null) {
                            $this->error('テナントが見つかりません');

                            return self::FAILURE;
                        }

                        $access->grant($operator, (string) $tenant->getTenantKey(), $level === 'none' ? null : $level, $issuer);
                        $this->info("{$operator->email} の {$tenant->getTenantKey()} の権限を {$level} にしました");
                    }
                } catch (DomainException $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                return self::SUCCESS;

            default:
                $this->error("不明な操作です: {$action}");

                return self::INVALID;
        }
    }

    private function find(string $email): ?Operator
    {
        return Operator::query()->whereEmail($email)->first();
    }

    /**
     * @param  array{url: string, enrollment: OperatorEnrollment, minutes: int}  $issued
     */
    private function printEnrollment(array $issued): void
    {
        $minutes = $issued['minutes'];
        $valid = $minutes % 60 === 0 ? ($minutes / 60).' 時間' : "{$minutes} 分";
        $this->newLine();
        $this->line("パスキーの登録 URL（{$valid}有効、1 回限り。本人がこの端末から開く）:");
        $this->line("  {$issued['url']}");
        $this->newLine();
    }
}
