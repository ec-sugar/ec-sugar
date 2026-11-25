<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * テナント DB の分離を本物の PostgreSQL で検証する（ADR 0003 / 0009）。
 *  - テナントごとに専用ロールが作られ、自分の DB には入れる
 *  - そのロールでは他テナントの DB に入れない
 *  - パスワードは中央 DB に暗号化されて保存される
 *  - テナント削除で DB とロールが消える
 */
final class TenantIsolationTest extends TestCase
{
    use DatabaseTruncation;

    public function test_tenant_roles_cannot_reach_other_tenants_databases(): void
    {
        $a = Tenant::create(['id' => 'isoa', 'name' => 'A']);
        $b = Tenant::create(['id' => 'isob', 'name' => 'B']);

        try {
            $userA = (string) $a->database()->getUsername();
            $passA = (string) $a->database()->getPassword();
            $dbA = (string) $a->database()->getName();
            $dbB = (string) $b->database()->getName();

            $this->assertNotSame('', $userA);
            $this->assertNotSame('', $passA);

            // 自分の DB には入れる
            $pdo = $this->connectAs($userA, $passA, $dbA);
            $this->assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
            unset($pdo);

            // 他テナントの DB には入れない
            try {
                $this->connectAs($userA, $passA, $dbB);
                $this->fail('テナント A のロールでテナント B の DB に接続できてしまった');
            } catch (PDOException $e) {
                $this->assertStringContainsString('permission denied', strtolower($e->getMessage()));
            }

            // 中央 DB に保存されたパスワードは暗号化されている
            $raw = (string) DB::table('tenants')->where('id', 'isoa')->value('data');
            $this->assertStringNotContainsString($passA, $raw);
            $this->assertStringContainsString('tenancy_db_password', $raw);
        } finally {
            tenancy()->end();
            $a->delete();
            $b->delete();
        }

        // 削除で DB とロールが消えている
        $this->assertNull(DB::selectOne('SELECT 1 FROM pg_database WHERE datname = ?', [$dbA]));
        $this->assertNull(DB::selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$userA]));
    }

    private function connectAs(string $user, string $password, string $database): PDO
    {
        $c = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'], $database);

        return new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
