<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Stancl\Tenancy\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;

/**
 * テナントごとに専用の DB ロール（最小権限）を作り、そのロールを所有者として DB を作る。
 *
 * 狙い（docs/CONCEPT.md 7.1 / ADR 0003 の「急所」）:
 *   - テナントの接続（テナント文脈で動くコード）は、自分の DB にしか入れない
 *   - 1 テナントの資格情報が漏れても、他テナントの DB には届かない
 *   - 中央アプリのロールは CREATEDB / CREATEROLE だけを持ち、superuser ではない
 *
 * 前提: PostgreSQL 16 以上（GRANT ... WITH INHERIT FALSE, SET TRUE）。
 * 資格情報はテナントの data 列に保存され、password は App\Models\Tenant の encrypted キャストで暗号化される。
 *
 * 識別子は必ず identifier() を通す。DDL はバインドできないので、文字種を検査してから二重引用符で囲む。
 */
final class PermissionControlledPostgreSQLDatabaseManager extends PostgreSQLDatabaseManager implements ManagesDatabaseUsers
{
    // 自動の掃除が終わるのを待つ回数（待ち時間は 0.2 秒ずつ伸ばし、合計で約 9 秒まで）
    private const int DROP_ATTEMPTS = 10;

    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        $config = $tenant->database();
        $database = $this->identifier($config->getName());
        $role = $this->identifier($config->getUsername());

        // ロールが先。所有者に指定するには、自分がそのロールのメンバーである必要がある
        $this->createUser($config);

        $this->database()->statement("CREATE DATABASE {$database} WITH OWNER = {$role} TEMPLATE = template0");

        // 既定では全ロールが CONNECT できるので、所有者以外を締め出す。
        // REVOKE は所有者が実行しないと「警告だけで何も取り消されない」（Postgres の仕様）ため、
        // 所有者に成り代わって実行する。superuser で試すと通ってしまうので、テストは ec_app で回す
        $this->database()->statement("SET ROLE {$role}");
        try {
            $this->database()->statement("REVOKE ALL ON DATABASE {$database} FROM PUBLIC");
        } finally {
            $this->database()->statement('RESET ROLE');
        }

        return true;
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        $config = $tenant->database();
        $database = $this->identifier($config->getName());
        $role = $this->identifier($config->getUsername());

        // DB が既に無い（後片付けで消した等）なら、所有者への成り代わりもせずロールの削除だけにする
        if ($this->databaseExists($config->getName())) {
            // DROP できるのは所有者だけ。権限を継承しない形で付与しているので、一時的に成り代わる。
            // FORCE で残っている接続（キューワーカー等）を切る
            $this->database()->statement("SET ROLE {$role}");
            try {
                // 自動の掃除（autovacuum / autoanalyze）は、接続を受け付けない DB では始まらない。
                // 走っている最中の自動処理はアプリのロールでは切れない（Postgres 17。強い pg_signal_backend は渡さない）ので、
                // 新しく始まらないようにしてから、走っている分が終わるのを待ってやり直す
                $this->database()->statement("ALTER DATABASE {$database} WITH ALLOW_CONNECTIONS false");
                $this->dropWhenIdle($database);
            } finally {
                $this->database()->statement('RESET ROLE');
            }
        }

        return $this->deleteUser($config);
    }

    /**
     * 自動の掃除が接続していて切れない（42501）ときだけ、少し待ってやり直す。それ以外の失敗はすぐ投げる。
     */
    private function dropWhenIdle(string $database): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->database()->statement("DROP DATABASE IF EXISTS {$database} WITH (FORCE)");

                return;
            } catch (QueryException $e) {
                if ($attempt >= self::DROP_ATTEMPTS || $e->getCode() !== '42501' || ! str_contains($e->getMessage(), 'terminate')) {
                    throw $e;
                }

                usleep(200_000 * $attempt);
            }
        }
    }

    public function createUser(DatabaseConfig $databaseConfig): bool
    {
        $role = $this->identifier($databaseConfig->getUsername());
        $password = $databaseConfig->getPassword();

        if (! is_string($password) || $password === '') {
            throw new InvalidArgumentException('テナント DB のパスワードが生成されていません');
        }

        $quoted = $this->database()->getPdo()->quote($password);

        $this->database()->statement(
            "CREATE ROLE {$role} WITH LOGIN PASSWORD {$quoted} NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT"
        );

        // 中央アプリのロールをメンバーにする。権限は継承しない（INHERIT FALSE）、成り代わりだけ許す（SET TRUE）。
        // これで中央の接続がテナントのテーブルを読めるようにはならず、DROP のときだけ所有者になれる
        $this->database()->statement("GRANT {$role} TO CURRENT_USER WITH INHERIT FALSE, SET TRUE");

        return true;
    }

    public function deleteUser(DatabaseConfig $databaseConfig): bool
    {
        $role = $this->identifier($databaseConfig->getUsername());

        return $this->database()->statement("DROP ROLE IF EXISTS {$role}");
    }

    public function userExists(string $username): bool
    {
        return $this->database()->selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$username]) !== null;
    }

    /**
     * 安全な識別子だけを通し、二重引用符で囲んで返す。
     */
    private function identifier(?string $name): string
    {
        if ($name === null || preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/', $name) !== 1) {
            throw new InvalidArgumentException('PostgreSQL の識別子として使えない名前です: '.var_export($name, true));
        }

        return '"'.$name.'"';
    }
}
