<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Crypto\TenantCipher;
use App\Core\Party\Enums\PartyKind;
use App\Core\Party\Models\Party;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * 個人情報の列がテナント鍵で暗号化され、ブラインドインデックスで検索できること（ADR 0010）。
 */
final class TenantCryptoTest extends TestCase
{
    use DatabaseTruncation;

    public function test_pii_is_encrypted_per_tenant_and_searchable_by_blind_index(): void
    {
        $a = Tenant::create(['id' => 'cra', 'name' => 'A']);
        $b = Tenant::create(['id' => 'crb', 'name' => 'B']);

        try {
            tenancy()->initialize($a);

            $party = Party::create([
                'kind' => PartyKind::Customer,
                'name' => '山田　太郎',
                'email' => 'Taro@Example.com',
                'phone' => '090-1234-5678',
                'address' => ['zip' => '100-0001', 'line1' => '千代田区千代田 1-1'],
            ]);

            // DB には暗号文しか無い
            $raw = DB::table('parties')->where('id', $party->id)->first();
            $this->assertNotNull($raw);
            $this->assertStringStartsWith(TenantCipher::PREFIX, (string) $raw->name);
            $this->assertStringStartsWith(TenantCipher::PREFIX, (string) $raw->address);
            $this->assertStringNotContainsString('山田', (string) $raw->name);
            $this->assertStringNotContainsString('千代田', (string) $raw->address);
            $this->assertSame(64, strlen((string) $raw->email_index));

            // モデル経由では平文に戻る
            $fresh = $party->fresh();
            $this->assertNotNull($fresh);
            $this->assertSame('山田　太郎', $fresh->name);
            $this->assertSame(['zip' => '100-0001', 'line1' => '千代田区千代田 1-1'], $fresh->address);

            // 正規化した完全一致で見つかる（大文字小文字・空白・ハイフンの違いを吸収）
            $this->assertSame($party->id, Party::whereBlind('email', '  taro@example.com ')->value('id'));
            $this->assertSame($party->id, Party::whereBlind('phone', '09012345678')->value('id'));
            $this->assertSame($party->id, Party::whereBlind('name', '山田 太郎')->value('id'));
            $this->assertNull(Party::whereBlind('email', 'other@example.com')->value('id'));

            $indexInA = (string) $raw->email_index;
            $cipherInA = (string) $raw->name;
            tenancy()->end();

            // 別テナントでは同じ平文でも暗号文もインデックスも違う（鍵がテナントごと）
            tenancy()->initialize($b);
            $other = Party::create(['kind' => PartyKind::Customer, 'name' => '山田　太郎', 'email' => 'taro@example.com']);
            $rawB = DB::table('parties')->where('id', $other->id)->first();
            $this->assertNotNull($rawB);
            $this->assertNotSame($indexInA, (string) $rawB->email_index);
            $this->assertNotSame($cipherInA, (string) $rawB->name);
        } finally {
            tenancy()->end();
            $a->delete();
            $b->delete();
        }
    }

    public function test_encryption_is_refused_outside_tenant_context(): void
    {
        $this->expectException(RuntimeException::class);

        app(TenantCipher::class)->encrypt('x');
    }

    public function test_tenant_data_key_is_issued_on_creation_and_stored_encrypted(): void
    {
        $tenant = Tenant::create(['id' => 'crk', 'name' => 'K']);

        try {
            $key = $tenant->getAttribute('data_key');
            $this->assertIsString($key);
            $this->assertSame(32, strlen((string) base64_decode($key, true)));

            $raw = (string) DB::table('tenants')->where('id', 'crk')->value('data');
            $this->assertStringNotContainsString($key, $raw);
            $this->assertStringContainsString('data_key', $raw);
        } finally {
            $tenant->delete();
        }
    }
}
