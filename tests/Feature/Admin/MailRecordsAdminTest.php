<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Http\Controllers\Api\MailRecordController;
use App\Admin\Mail\ShopTestMail;
use App\Admin\Models\AuditEvent;
use App\Admin\Services\OperatorAccess;
use App\Core\Mail\Models\MailRecord;
use App\Core\Party\Services\CustomerAccounts;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 設定 › メールの記録（ADR 0095）。一覧と 1 通は閲覧から（宛先を返さない）、宛先は運用から（監査ログに残す）、
 * 試しのメールは運用から・運営ごとに 10 分に 3 通まで。ほかの店の記録は見えない
 */
final class MailRecordsAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
        $this->withoutVite();
        Cache::store()->flush();
        $this->tenant('mlm', 'メールの店');
        $this->tenant('mln');
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_an_owner_lists_filters_and_opens_records_and_sees_recipients_with_an_audit(): void
    {
        $this->enrolledOperator();
        $order = strtolower((string) Str::ulid());
        [$sent, $failed, $customer] = $this->inside('mlm', function () use ($order): array {
            $customer = app(CustomerAccounts::class)->forEmail('hanako@example.com', 'test');
            Mail::to('hanako@example.com')->send((new ShopTestMail('メールの店', 'now'))->forTransaction($order));
            $sent = (string) MailRecord::query()->value('id');
            $failed = MailRecord::query()->create(['kind' => 'restock.restocked', 'subject' => '[メールの店] 入荷のお知らせ', 'status' => 'failed', 'reason' => 'auth', 'smtp_code' => 535, 'smtp_status' => '5.7.8', 'recipients' => ['addresses' => ['taro@example.com']], 'recipient_count' => 1]);

            return [$sent, $failed->id, $customer->id];
        });

        $list = $this->send('GET', '/api/tenants/mlm/mail-records')->assertOk();
        $list->assertJsonPath('total', 2)->assertJsonPath('failed_recently', 1)->assertJsonPath('can_see_recipients', true)->assertJsonPath('can_send_test', true);
        $this->assertSame(['core.test', 'restock.restocked'], array_column($list->json('kinds'), 'value'));
        // 一覧には宛先を出さない
        $this->assertStringNotContainsString('example.com', (string) $list->getContent());

        $this->send('GET', '/api/tenants/mlm/mail-records?status=failed')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('records.0.id', $failed)->assertJsonPath('records.0.reason', 'auth')->assertJsonPath('records.0.smtp_code', 535);
        $this->send('GET', '/api/tenants/mlm/mail-records?kind=core.test')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('records.0.kind_label', 'テストメール');
        $this->send('GET', '/api/tenants/mlm/mail-records?kind=Bad%20Kind')->assertStatus(422);

        // 1 通: 関係する注文と、台帳にある宛先ならそのお客さん（アドレスは返さない）
        $show = $this->send('GET', "/api/tenants/mlm/mail-records/{$sent}")->assertOk();
        $show->assertJsonPath('record.status', 'sent')->assertJsonPath('record.transaction_id', $order)->assertJsonPath('record.customer_id', $customer);
        $this->assertStringNotContainsString('example.com', (string) $show->getContent());

        // 宛先は運用の段階から。見たことを中央の監査ログに（宛先は残さない）
        $this->send('GET', "/api/tenants/mlm/mail-records/{$sent}/personal")->assertOk()->assertJsonPath('personal.recipients', ['hanako@example.com']);
        $audit = AuditEvent::query()->where('type', 'operator.personal_data_viewed')->where('data->mail_record_id', $sent)->sole();
        $this->assertSame(['subject' => 'mail_record', 'mail_record_id' => $sent], $audit->data);
        $this->assertStringNotContainsString('example.com', (string) json_encode($audit->data));

        // ほかの店からは見えない
        $this->send('GET', "/api/tenants/mln/mail-records/{$sent}")->assertNotFound();
        $this->send('GET', "/api/tenants/mln/mail-records/{$sent}/personal")->assertNotFound();
        $this->send('GET', '/api/tenants/mln/mail-records')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_read_staff_see_the_list_but_not_recipients_nor_send_tests(): void
    {
        $id = $this->inside('mlm', function (): string {
            Mail::to('hanako@example.com')->send(new ShopTestMail('メールの店', 'now'));

            return (string) MailRecord::query()->value('id');
        });
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'mlm', 'read', 'test');

        $this->send('GET', '/api/tenants/mlm/mail-records')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('can_see_recipients', false)->assertJsonPath('can_send_test', false);
        $this->send('GET', "/api/tenants/mlm/mail-records/{$id}")->assertOk();
        $this->send('GET', "/api/tenants/mlm/mail-records/{$id}/personal")->assertForbidden();
        $this->send('POST', '/api/tenants/mlm/mail-records/test')->assertForbidden();
        // 断った要求は見た記録を残さない（監査ログは追記だけで、前のテストの行も残るので、この記録の ID で数える）
        $this->assertSame(0, AuditEvent::query()->where('type', 'operator.personal_data_viewed')->where('data->mail_record_id', $id)->count());
        // 任されていない店は見えない
        $this->send('GET', '/api/tenants/mln/mail-records')->assertNotFound();
    }

    public function test_the_test_mail_goes_to_the_signed_in_operator_and_is_limited(): void
    {
        [$staff] = $this->enrolledOperator('staff@example.com', 'staff');
        app(OperatorAccess::class)->grant($staff, 'mlm', 'operate', 'test');

        // キューは同期（テストの既定）なので、その場で送られて記録に出る
        for ($i = 0; $i < MailRecordController::TEST_PER_OPERATOR; $i++) {
            $this->send('POST', '/api/tenants/mlm/mail-records/test')->assertStatus(202);
        }

        $this->send('POST', '/api/tenants/mlm/mail-records/test')->assertStatus(429)->assertHeader('Retry-After');

        $this->inside('mlm', function (): void {
            $records = MailRecord::query()->get();
            $this->assertCount(MailRecordController::TEST_PER_OPERATOR, $records);
            $this->assertSame(['core.test'], $records->pluck('kind')->unique()->values()->all());
            $this->assertSame(['addresses' => ['staff@example.com']], $records->firstOrFail()->recipients);
            $this->assertSame('sent', $records->firstOrFail()->status);
        });
        // 宛先は暗号化（積んだ仕事も暗号化。ShouldBeEncrypted）
        $this->inside('mlm', fn () => $this->assertStringNotContainsString('staff@example.com', (string) json_encode(DB::table('mail_records')->get()->all())));
    }
}
