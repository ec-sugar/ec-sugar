<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * 入口の境界を検証する。
 *  - 中央ドメインは中央として応答する
 *  - テナントのドメインはそのテナントの文脈で応答する
 *  - 未知のホストは 404（500 にして存在するテナントを推測させない）
 *  - 中央ドメインからテナントのルートには入れない
 */
final class TenantRoutingTest extends TestCase
{
    // RefreshDatabase はトランザクションで包むが、Postgres は CREATE DATABASE をその中で実行できない。
    // テナントを作るテストは、移行 1 回 ＋ テーブル切り詰めの DatabaseTruncation を使う
    use DatabaseTruncation;

    public function test_central_domain_answers_as_central(): void
    {
        $this->getJson('http://localhost/')
            ->assertOk()
            ->assertJson(['scope' => 'central']);
    }

    public function test_unknown_host_is_not_found(): void
    {
        $this->getJson('http://nope.localhost/')
            ->assertNotFound()
            ->assertJson(['message' => 'Not Found']);
    }

    public function test_tenant_domain_answers_in_tenant_context(): void
    {
        $tenant = Tenant::create(['id' => 'shop1', 'name' => 'Shop 1']);
        $tenant->domains()->create(['domain' => 'shop1.localhost']);

        try {
            // 店頭はテーマの画面（Blade）を、そのテナントの名前で返す（ADR 0018）。ビルドの成果物には頼らない
            $this->withoutVite()->get('http://shop1.localhost/')
                ->assertOk()
                ->assertSee('<title>Shop 1</title>', false);
        } finally {
            tenancy()->end(); // 文脈を閉じてから消す（Tests\TestCase::tearDown も保険で同じことをする）
            $tenant->delete(); // TenantDeleted → DeleteDatabase
        }
    }
}
