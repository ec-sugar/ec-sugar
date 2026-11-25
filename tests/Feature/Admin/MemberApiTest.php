<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\AuditEvent;
use App\Admin\Models\Operator;
use App\Admin\Models\OperatorGrant;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\OperatorSession;
use Tests\Support\SoftwareAuthenticator;
use Tests\TestCase;

/**
 * メンバーと権限の画面の API（ADR 0024）。オーナーだけ、変えるのは直前の再確認の後、変えたことは監査ログ。
 * 自分は止められない、最後のオーナーは外せない。ログインの履歴はオーナーは全員、スタッフは自分の分。
 */
final class MemberApiTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;

    private Tenant $tenant;

    private Operator $owner;

    private SoftwareAuthenticator $authenticator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        $this->tenant = Tenant::create(['id' => 'maa', 'name' => '店 maa']);
        [$this->owner, $this->authenticator] = $this->enrolledOperator('owner@example.com');
    }

    protected function tearDown(): void
    {
        $this->tenant->delete();
        parent::tearDown();
    }

    public function test_an_owner_adds_a_member_and_gives_a_shop_after_confirming(): void
    {
        $this->send('GET', '/api/members')->assertOk()->assertJsonPath('members.0.email', 'owner@example.com')->assertJsonPath('tenants.0.id', 'maa');
        $this->send('POST', '/api/members', ['name' => '山田', 'email' => 'staff@example.com'])->assertStatus(423);
        $this->assertNull(Operator::query()->whereEmail('staff@example.com')->first());

        $this->reconfirm($this->owner, $this->authenticator);
        $created = $this->send('POST', '/api/members', ['name' => '山田', 'email' => ' Staff@Example.com '])->assertCreated()
            ->assertJsonPath('members.1.role', 'staff')
            ->assertJsonPath('members.1.grants', []);
        $this->assertStringContainsString('/enroll#', (string) $created->json('enrollment_url'));
        $staff = Operator::query()->whereEmail('staff@example.com')->sole();

        // 同じメール・形の違う入力は断る
        $this->send('POST', '/api/members', ['name' => '二人目', 'email' => 'staff@example.com'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->send('POST', '/api/members', ['name' => "改行\n入り", 'email' => 'x@example.com'])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->send('PUT', "/api/members/{$staff->id}/grants/maa", ['level' => 'operate'])->assertOk()->assertJsonPath('members.1.grants.maa', 'operate');
        $this->send('PUT', "/api/members/{$staff->id}/grants/maa", ['level' => 'admin'])->assertStatus(422);
        $this->send('PUT', "/api/members/{$staff->id}/grants/nope", ['level' => 'read'])->assertNotFound();
        $this->assertSame('operate', OperatorGrant::query()->where('operator_id', $staff->id)->value('level'));

        // 自分は止められない。最後のオーナーは外せない
        $this->send('PUT', "/api/members/{$this->owner->id}/status", ['active' => false])->assertStatus(409)->assertJsonPath('user_message', fn (string $m): bool => str_contains($m, '自分自身は停止できません'));
        $this->send('PUT', "/api/members/{$this->owner->id}/role", ['role' => 'staff'])->assertStatus(409)->assertJsonPath('user_message', fn (string $m): bool => str_contains($m, '最後の有効なオーナー'));
        $this->send('PUT', "/api/members/{$staff->id}/status", ['active' => false])->assertOk()->assertJsonPath('members.1.active', false);

        $types = AuditEvent::query()->where('operator_id', $this->owner->id)->whereIn('type', ['operator.created', 'operator.grant_changed', 'operator.disabled'])->pluck('type')->all();
        $this->assertEqualsCanonicalizing(['operator.created', 'operator.grant_changed', 'operator.disabled'], $types);
    }

    public function test_staff_cannot_manage_members_and_see_only_their_own_logins(): void
    {
        $this->reconfirm($this->owner, $this->authenticator);
        $this->send('POST', '/api/members', ['name' => '山田', 'email' => 'staff@example.com'])->assertCreated();

        // スタッフとしてログインし直す
        $this->sessionId = null;
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        [$staff] = $this->enrolledOperator('staff2@example.com', 'staff');
        $this->send('GET', '/api/members')->assertForbidden();
        $this->send('POST', '/api/members', ['name' => '乗っ取り', 'email' => 'evil@example.com'])->assertForbidden();
        $this->send('PUT', "/api/members/{$staff->id}/role", ['role' => 'owner'])->assertForbidden();
        $this->assertSame('staff', $staff->fresh()?->role);

        $logins = $this->send('GET', '/api/login-history')->assertOk()->json('logins');
        $this->assertNotEmpty($logins);
        $this->assertSame([$staff->id], array_values(array_unique(array_map(static fn (array $l) => $l['operator']['id'] ?? null, $logins))));
    }
}
