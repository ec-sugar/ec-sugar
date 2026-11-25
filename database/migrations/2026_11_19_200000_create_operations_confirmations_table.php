<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 運営が「確かめた」と答えたことの記録。今は 1 種類: APP_KEY の控えをサーバーの外に取ったこと（app_key_backup）。
 * 置くのは「何を・いつ・誰が」だけで、鍵・設定の値・控えの置き場は置かない（アプリは控えの中身を扱わない）。
 *
 * ホストの報告（host_checks）には置かない: あちらは報告のたびに丸ごと置き換える表で、人の答えが次の報告で消える。
 * 種類ごとに 1 行（主キー）。最初の答えを残し、押し直しても書き換えない。答えた運営を消しても記録は残す（誰がは空になる。監査ログには残っている）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_confirmations', function (Blueprint $table): void {
            $table->string('key', 40)->primary();
            $table->timestampTz('confirmed_at');
            $table->foreignUlid('operator_id')->nullable()->constrained('operators')->nullOnDelete();
        });

        DB::statement("ALTER TABLE operations_confirmations ADD CONSTRAINT operations_confirmations_key CHECK (key IN ('app_key_backup'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_confirmations');
    }
};
