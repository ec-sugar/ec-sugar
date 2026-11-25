<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Tenancy\PermissionControlledPostgreSQLDatabaseManager;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\TemplatedTenantDatabaseManager;
use Tests\TestCase;

/**
 * テストで店の DB を型から写す仕組み（ADR 0057）が、本番の作り方（template0 から作って移行を一から流す）と同じ店の DB を作るか。
 *
 * 本番の道（移行を一から流す）もここで毎回通す。型から写した店と、一から作った店の、表・列・制約・索引・トリガー・関数・持ち主・権限・
 * ロールの付け方・移行の記録・初めの行の数を比べる（店のロールの名前は同じ印に置き換えて比べる）
 */
final class TenantDatabaseTemplateTest extends TestCase
{
    use DatabaseTruncation;

    public function test_a_shop_copied_from_the_template_matches_one_migrated_from_scratch(): void
    {
        // 一から作る（本番の係。型を使わない）
        config(['tenancy.database.managers.pgsql' => PermissionControlledPostgreSQLDatabaseManager::class]);
        $fresh = Tenant::create(['id' => 'tplfresh', 'name' => '一から']);

        // 型から写す
        config(['tenancy.database.managers.pgsql' => TemplatedTenantDatabaseManager::class]);
        $copy = Tenant::create(['id' => 'tplcopy', 'name' => '型から']);

        try {
            // 型が今の指紋で残っている（写した店は型から作られた）
            $note = DB::selectOne("SELECT shobj_description(oid, 'pg_database') AS note FROM pg_database WHERE datname = ?", [TemplatedTenantDatabaseManager::templateName()]);
            $this->assertSame(TemplatedTenantDatabaseManager::fingerprint(), $note?->note);

            $expected = $this->shape($fresh);
            $actual = $this->shape($copy);

            // 比べる物が空でない（比べ方の誤りで、空どうしが同じになって通るのを防ぐ）
            $this->assertNotEmpty($expected['relations']);
            $this->assertNotEmpty($expected['functions']);
            $this->assertNotEmpty($expected['triggers']);
            $this->assertNotEmpty($expected['migrations']);

            foreach (array_keys($expected) as $part) {
                $this->assertSame($expected[$part], $actual[$part], "型から写した店の {$part} が、一から作った店と違う");
            }

            // 型のロールは、写した後に店のロールのどれにも付いていない
            $this->assertNull(DB::selectOne(
                'SELECT 1 FROM pg_auth_members m JOIN pg_roles g ON g.oid = m.roleid WHERE g.rolname = ? AND m.member <> (SELECT oid FROM pg_roles WHERE rolname = current_user)',
                [TemplatedTenantDatabaseManager::templateRoleName()],
            ));
        } finally {
            tenancy()->end();
            $fresh->delete();
            $copy->delete();
        }
    }

    public function test_a_stale_template_is_rebuilt_and_the_fingerprint_follows_the_migration_files(): void
    {
        // 型を作らせてから、注釈を古い指紋に書き換える（移行のファイルが変わった後と同じ）
        $first = Tenant::create(['id' => 'tplone', 'name' => '1']);
        $template = TemplatedTenantDatabaseManager::templateName();
        DB::statement('COMMENT ON DATABASE "'.$template.'" IS \'stale\'');
        TemplatedTenantDatabaseManager::forgetVerified();

        $second = Tenant::create(['id' => 'tpltwo', 'name' => '2']);

        try {
            $note = DB::selectOne("SELECT shobj_description(oid, 'pg_database') AS note FROM pg_database WHERE datname = ?", [$template]);
            $this->assertSame(TemplatedTenantDatabaseManager::fingerprint(), $note?->note);
            $this->assertTrue($second->run(static fn (): bool => DB::table('migrations')->exists()));

            // 移行のファイルが 1 つ増えると指紋が変わる
            $before = TemplatedTenantDatabaseManager::fingerprint();
            $dir = sys_get_temp_dir().'/ec-template-'.bin2hex(random_bytes(4));
            mkdir($dir);
            file_put_contents($dir.'/2099_01_01_000000_example.php', '<?php return null;');
            config(['tenancy.migration_parameters.--path' => [...(array) config('tenancy.migration_parameters.--path'), $dir]]);

            try {
                $this->assertNotSame($before, TemplatedTenantDatabaseManager::fingerprint());
            } finally {
                unlink($dir.'/2099_01_01_000000_example.php');
                rmdir($dir);
            }
        } finally {
            tenancy()->end();
            $first->delete();
            $second->delete();
        }
    }

    /**
     * 店の DB の形（店のロールの名前は ROLE に置き換える）
     *
     * @return array<string, list<string>>
     */
    private function shape(Tenant $tenant): array
    {
        $role = (string) $tenant->database()->getUsername();
        $name = (string) $tenant->database()->getName();
        $sorted = static function (array $rows): array {
            sort($rows);

            return $rows;
        };
        $normalize = static fn (array $rows): array => array_map(
            static fn (object $row): string => str_replace([$role, $name], ['ROLE', 'DB'], (string) json_encode(array_values((array) $row), JSON_UNESCAPED_UNICODE)),
            $rows,
        );

        $central = [
            // DB の持ち主と権限（PUBLIC から接続を取り上げている）
            'database' => $normalize(DB::select('SELECT pg_get_userbyid(datdba) AS owner, datacl::text AS acl, datallowconn, datistemplate FROM pg_database WHERE datname = ?', [$name])),
            // 店のロールの属性と、付いているロール・付けられているロール（中央のロールは成り代われるだけ）
            'role' => $normalize(DB::select('SELECT rolsuper, rolinherit, rolcreaterole, rolcreatedb, rolcanlogin FROM pg_roles WHERE rolname = ?', [$role])),
            // 並びは名前をならした後で決める（ならす前の本物の名前で並べると、比べる 2 つの店で順が入れ替わることがある）
            'memberships' => $sorted($normalize(DB::select(
                'SELECT g.rolname AS granted, u.rolname AS member, m.admin_option, m.inherit_option, m.set_option FROM pg_auth_members m
                 JOIN pg_roles g ON g.oid = m.roleid JOIN pg_roles u ON u.oid = m.member WHERE g.rolname = ? OR u.rolname = ?',
                [$role, $role],
            ))),
        ];

        $inside = $tenant->run(static function () use ($normalize): array {
            $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY 1");
            $counts = [];

            foreach ($tables as $table) {
                $counts[] = $table->tablename.'='.DB::table($table->tablename)->count();
            }

            return [
                'schema' => $normalize(DB::select("SELECT pg_get_userbyid(nspowner) AS owner, nspacl::text AS acl FROM pg_namespace WHERE nspname = 'public'")),
                'relations' => $normalize(DB::select(
                    "SELECT c.relname, c.relkind, pg_get_userbyid(c.relowner) AS owner, c.relacl::text AS acl FROM pg_class c
                     JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' ORDER BY 1",
                )),
                'columns' => $normalize(DB::select(
                    "SELECT table_name, column_name, data_type, is_nullable, column_default FROM information_schema.columns
                     WHERE table_schema = 'public' ORDER BY 1, 2",
                )),
                'constraints' => $normalize(DB::select(
                    "SELECT c.conrelid::regclass::text AS rel, c.conname, pg_get_constraintdef(c.oid) AS def FROM pg_constraint c
                     JOIN pg_namespace n ON n.oid = c.connamespace WHERE n.nspname = 'public' ORDER BY 1, 2",
                )),
                'indexes' => $normalize(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' ORDER BY 1")),
                'triggers' => $normalize(DB::select('SELECT tgrelid::regclass::text AS rel, tgname, pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE NOT tgisinternal ORDER BY 1, 2')),
                'functions' => $normalize(DB::select(
                    "SELECT p.proname, pg_get_userbyid(p.proowner) AS owner, p.proacl::text AS acl, md5(pg_get_functiondef(p.oid)) AS def FROM pg_proc p
                     JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public' ORDER BY 1",
                )),
                'types' => $normalize(DB::select(
                    "SELECT t.typname, pg_get_userbyid(t.typowner) AS owner FROM pg_type t
                     JOIN pg_namespace n ON n.oid = t.typnamespace WHERE n.nspname = 'public' ORDER BY 1",
                )),
                'migrations' => $normalize(DB::select('SELECT migration, batch FROM migrations ORDER BY migration')),
                'rows' => $counts,
            ];
        });

        return [...$central, ...$inside];
    }
}
