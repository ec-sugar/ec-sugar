<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Tenancy\PermissionControlledPostgreSQLDatabaseManager;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Stancl\Tenancy\Contracts\ManagesDatabaseUsers;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;

/**
 * テスト専用: 店の DB を、移行を流し終えた型（テンプレート）の DB から写して作る（ADR 0057）。
 *
 * 店を作るたびに核と全部の App の移行（百本近く）を一から流すと、PHP のテストの時間の多くがそこに掛かる。
 * テストの実行ごと（並べて流すときは並べた各々）に 1 度だけ型を作り、店を作るたびに Postgres の `CREATE DATABASE … TEMPLATE …` で写す。
 *
 * 守ること:
 * - 店のロールと権限（ADR 0003・0009）は本番と同じ形にする。ロールは本番の係（PermissionControlledPostgreSQLDatabaseManager::createUser）
 *   で作り、DB の持ち主は店のロール、DB への接続は PUBLIC から取り上げる（本番の createDatabase と同じ文）。型の中の表・関数の持ち主
 *   （型のロール）は、写した後に店のロールへ移す（REASSIGN OWNED）。移す間だけ店のロールに型のロールを継がせ、済んだら外す。
 *   本番の形と同じになっていることは TenantDatabaseTemplateTest が、一から作った店と比べて確かめる
 * - 型が移行の今の形と合わなければ作り直す（移行のファイルの名前と中身の指紋を、型の DB の注釈に残して比べる）
 * - 本番の店の作り方は変えない。この係はテストの中でだけ、Tests\TestCase が config の tenancy.database.managers.pgsql を差し替えて使う。
 *   移行を一から流す道は、型を使わないテスト（$tenantDatabasesFromTemplate = false）と TenantDatabaseTemplateTest が通る
 *
 * 型の名前は店の DB の頭に `_template`（例 ec_test_o_t__template）、型のロールは店のロールの頭に `_template`
 * （例 ec_test_o_t_u__template）。店の ID は英小文字か数字で始まる（ShopMedia の移行・店を作る画面）ので、店の DB やロールとは重ならない。
 * どちらも店の DB の頭で始まるので、`make test-clean` の片付けの対象に入る（消えたら次の実行で作り直す）。
 */
final class TemplatedTenantDatabaseManager extends PostgreSQLDatabaseManager implements ManagesDatabaseUsers
{
    /** 型の作り方を変えたら上げる（指紋に入るので、古い型は作り直される） */
    private const int BUILD_VERSION = 1;

    /** この処理の中で確かめ済みの型（頭と指紋）。確かめは処理ごとに 1 度だけ */
    private static ?string $verified = null;

    private PermissionControlledPostgreSQLDatabaseManager $production;

    public function __construct()
    {
        $this->production = new PermissionControlledPostgreSQLDatabaseManager;
    }

    public function setConnection(string $connection): void
    {
        parent::setConnection($connection);
        $this->production->setConnection($connection);
    }

    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        $config = $tenant->database();
        $name = (string) $config->getName();
        $database = self::identifier($name);
        $role = self::identifier($config->getUsername());
        $templateRole = self::identifier(self::templateRoleName());

        // 型を先に用意する（失敗しても店のロールを残さない）
        $template = self::identifier($this->ensureTemplate());

        // ロールは本番の係で作る（パスワード・NOINHERIT・中央のロールが成り代われるだけの付け方まで同じ）
        $this->production->createUser($config);

        $this->database()->statement("CREATE DATABASE {$database} WITH OWNER = {$role} TEMPLATE = {$template}");

        // 型の中の物（表・関数・型・連番）の持ち主を、型のロールから店のロールへ移す。REASSIGN OWNED は両方のロールの権限を
        // 持つ者だけが流せる（Postgres 16 以降）。店のロールに型のロールを継がせ（INHERIT）、店のロールに成り代わって流し、済んだら外す。
        // 型のロールは型の DB の外に何も持たない（型の DB の持ち主は中央のロール）ので、ほかの DB の物は動かない
        $this->database()->statement("GRANT {$templateRole} TO {$role} WITH INHERIT TRUE, SET FALSE");

        try {
            // 写したばかりの DB は PUBLIC が接続でき（DB の権限は型から写らない）、中央のロールで入れる。取り上げるのはこの後
            $this->onDatabase($name, null, null, static function (Connection $clone) use ($role, $templateRole): void {
                $clone->statement("SET ROLE {$role}");
                $clone->statement("REASSIGN OWNED BY {$templateRole} TO {$role}");
                $clone->statement('RESET ROLE');
            });
        } finally {
            $this->database()->statement("REVOKE {$templateRole} FROM {$role}");
        }

        // 本番の createDatabase と同じ: 所有者に成り代わって、PUBLIC の接続を取り上げる
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
        return $this->production->deleteDatabase($tenant);
    }

    public function createUser(DatabaseConfig $databaseConfig): bool
    {
        return $this->production->createUser($databaseConfig);
    }

    public function deleteUser(DatabaseConfig $databaseConfig): bool
    {
        return $this->production->deleteUser($databaseConfig);
    }

    public function userExists(string $username): bool
    {
        return $this->production->userExists($username);
    }

    public function databaseExists(string $name): bool
    {
        return $this->database()->selectOne('SELECT 1 FROM pg_database WHERE datname = ?', [$name]) !== null;
    }

    /**
     * 型の DB の名前（店の DB の頭 + _template）
     */
    public static function templateName(): string
    {
        return self::prefix().'_template';
    }

    /**
     * 型のロールの名前（店のロールの頭 + _template。店のロールは頭 + u_ + 店の ID）
     */
    public static function templateRoleName(): string
    {
        return self::prefix().'u__template';
    }

    /**
     * この処理の中の確かめ済みの印を消す（次に店を作るとき、型の注釈を読み直す。型を作り直すテストのため）
     */
    public static function forgetVerified(): void
    {
        self::$verified = null;
    }

    /**
     * 移行のファイル（名前と中身）と型の作り方の版から決める指紋。どれかが変われば型を作り直す
     */
    public static function fingerprint(): string
    {
        $files = [];

        foreach ((array) config('tenancy.migration_parameters.--path', []) as $path) {
            foreach (glob(rtrim((string) $path, '/').'/*.php') ?: [] as $file) {
                $files[basename($file)] = hash_file('sha256', $file);
            }
        }

        ksort($files);

        return 'v'.self::BUILD_VERSION.'-'.hash('sha256', (string) json_encode($files));
    }

    /**
     * 型が今の指紋で用意されているか確かめ、無ければ作る。返すのは型の DB の名前
     */
    private function ensureTemplate(): string
    {
        $template = self::templateName();
        $fingerprint = self::fingerprint();

        if (self::$verified === $template.'|'.$fingerprint) {
            return $template;
        }

        // 同じ頭で同時に走る処理が無いのが決まり（作業ごとに DB を分ける）だが、重なっても型を壊し合わないように鍵を取る
        $lock = (int) $this->database()->selectOne('SELECT hashtext(?) AS k', [$template])->k;
        $this->database()->select('SELECT pg_advisory_lock(?)', [$lock]);

        try {
            $current = $this->database()->selectOne(
                "SELECT shobj_description(oid, 'pg_database') AS note FROM pg_database WHERE datname = ?",
                [$template],
            );

            if ($current?->note !== $fingerprint) {
                $this->buildTemplate($template, $fingerprint);
            }
        } finally {
            $this->database()->select('SELECT pg_advisory_unlock(?)', [$lock]);
        }

        self::$verified = $template.'|'.$fingerprint;

        return $template;
    }

    /**
     * 型を作り直す: 中央のロールが持つ空の DB に、型のロールで店の移行を全部流す
     */
    private function buildTemplate(string $name, string $fingerprint): void
    {
        $template = self::identifier($name);
        $roleName = self::templateRoleName();
        $role = self::identifier($roleName);
        $password = Str::random(48);
        $quoted = $this->database()->getPdo()->quote($password);

        $this->database()->statement("DROP DATABASE IF EXISTS {$template} WITH (FORCE)");

        // 型のロールは作り直さずに使い回す（写した店の物は移し終えているので何も持たない）。合言葉は毎回替える
        if ($this->production->userExists($roleName)) {
            $this->database()->statement("ALTER ROLE {$role} WITH LOGIN PASSWORD {$quoted}");
        } else {
            $this->database()->statement("CREATE ROLE {$role} WITH LOGIN PASSWORD {$quoted} NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT");
        }

        // 型の DB の持ち主は中央のロール（型のロールに持たせると、写すたびの REASSIGN OWNED で型の DB ごと店へ移ってしまう）
        $this->database()->statement("CREATE DATABASE {$template} WITH TEMPLATE = template0");

        // 型のロールが表を作れるように、作る間だけ public に CREATE を渡す（終わったら外し、写す前に元の権限へ戻す）
        $this->onDatabase($name, null, null, static fn (Connection $owner) => $owner->statement("GRANT CREATE ON SCHEMA public TO {$role}"));

        try {
            $this->onDatabase($name, $roleName, $password, static function (Connection $connection): void {
                $code = Artisan::call('migrate', [
                    '--database' => $connection->getName(),
                    '--path' => (array) config('tenancy.migration_parameters.--path', []),
                    '--realpath' => true,
                    '--force' => true,
                ]);

                if ($code !== 0) {
                    throw new RuntimeException("店の DB の型に移行を流せませんでした:\n".Artisan::output());
                }
            });
        } finally {
            $this->onDatabase($name, null, null, static fn (Connection $owner) => $owner->statement("REVOKE CREATE ON SCHEMA public FROM {$role}"));
        }

        // 型には誰も入らない（入っている者がいると写せない。自動の掃除も、接続を受け付けない DB では始まらない）
        $this->database()->statement("REVOKE ALL ON DATABASE {$template} FROM PUBLIC");
        $this->database()->statement("ALTER DATABASE {$template} WITH ALLOW_CONNECTIONS false");
        $this->database()->statement("COMMENT ON DATABASE {$template} IS ".$this->database()->getPdo()->quote($fingerprint));
    }

    /**
     * 中央の接続の設定を写して、別の DB（とロール）へ一時の接続を作って処理し、必ず閉じる
     *
     * @param  Closure(Connection): mixed  $work
     */
    private function onDatabase(string $database, ?string $username, ?string $password, Closure $work): void
    {
        $name = 'tenant_template_work';
        $base = $this->database()->getConfig();
        $config = array_merge($base, ['database' => $database], $username === null ? [] : ['username' => $username, 'password' => $password]);
        unset($config['name']);

        config(["database.connections.{$name}" => $config]);

        try {
            $work(DB::connection($name));
        } finally {
            DB::purge($name);
            config(["database.connections.{$name}" => null]);
        }
    }

    private static function prefix(): string
    {
        return (string) config('tenancy.database.prefix');
    }

    /**
     * 安全な識別子だけを通し、二重引用符で囲んで返す（本番の係と同じ決まり）
     */
    private static function identifier(?string $name): string
    {
        if ($name === null || preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/', $name) !== 1) {
            throw new InvalidArgumentException('PostgreSQL の識別子として使えない名前です: '.var_export($name, true));
        }

        return '"'.$name.'"';
    }
}
