<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 管理画面で作った復元のキットの「開ける鍵」の公開の半分（ADR 0111）。
 *
 * 開ける鍵（秘密の半分）はオーナーのブラウザの中で作り、キットに入れて手元に保存する。サーバーへ来るのは公開の半分と指紋だけ。
 * ホストの ec-sugar は、サーバーの外のバックアップを暗号化する相手がまだ無い時に、ここの鍵を採る（operations:recovery-recipient）。
 *
 * - 1 行だけ（id = 1）。作り直すと置き換わり、前の指紋は監査ログに残る
 * - 置くのは公開してよい値だけ（公開鍵・指紋・いつ・誰が）。APP_KEY・キットの合い言葉・秘密の鍵は置かない
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recovery_kit_recipients', function (Blueprint $table): void {
            $table->smallInteger('id')->primary();
            $table->char('fingerprint', 40);
            $table->text('public_key');
            $table->timestampTz('registered_at');
            $table->foreignUlid('operator_id')->nullable()->constrained('operators')->nullOnDelete();
        });

        DB::statement('ALTER TABLE recovery_kit_recipients ADD CONSTRAINT recovery_kit_recipients_single CHECK (id = 1)');
        DB::statement("ALTER TABLE recovery_kit_recipients ADD CONSTRAINT recovery_kit_recipients_fingerprint CHECK (fingerprint ~ '^[0-9A-F]{40}$')");
        DB::statement("ALTER TABLE recovery_kit_recipients ADD CONSTRAINT recovery_kit_recipients_public_key CHECK (char_length(public_key) BETWEEN 200 AND 8192 AND public_key ~ '^-----BEGIN PGP PUBLIC KEY BLOCK-----' AND public_key !~ 'PRIVATE')");

        // ホストがいまバックアップの暗号化に使っている公開鍵の指紋（ホストが知らせる。1 行だけ）。上の鍵と違えば、画面が知らせる
        Schema::create('recovery_kit_host_keys', function (Blueprint $table): void {
            $table->smallInteger('id')->primary();
            $table->string('fingerprint', 64);
            $table->timestampTz('reported_at');
        });

        DB::statement('ALTER TABLE recovery_kit_host_keys ADD CONSTRAINT recovery_kit_host_keys_single CHECK (id = 1)');
        DB::statement("ALTER TABLE recovery_kit_host_keys ADD CONSTRAINT recovery_kit_host_keys_fingerprint CHECK (fingerprint ~ '^[0-9A-F]{40,64}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_kit_host_keys');
        Schema::dropIfExists('recovery_kit_recipients');
    }
};
