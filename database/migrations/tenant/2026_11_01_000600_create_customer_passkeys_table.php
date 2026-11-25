<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 会員のパスキー（ADR 0044 の 3、ADR 0023）。任意で足せる。店の DB に置き、ショップのホストに結びつく。
 * 持つのは公開鍵と数え上げ（秘密ではない）と、会員が付けた名前（暗号化）。足す・消すは本人の再確認の後
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_passkeys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->text('name');                          // 暗号化（会員が付けた名前）
            $table->string('credential_id', 1400)->unique(); // base64url（認証器の ID は 1023 バイトまで）
            $table->text('credential');                    // CredentialRecord の JSON（公開鍵・数え上げ）
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            $table->index(['customer_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_passkeys');
    }
};
