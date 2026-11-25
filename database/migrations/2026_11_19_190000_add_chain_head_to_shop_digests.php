<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 店ごとの様子に、記録の連鎖の先頭（最後のイベントの番号と hash）を控える（ADR 0012 の「まだできていないこと」）。
 * 連鎖の検証は「つながっているか」を見るので、末尾を切り落とされても残りは無傷に見える。検証のたびに先頭を中央に控え、
 * 次の検証で「前に控えた番号のイベントが、同じ hash で今もあるか」を見る。無ければ巻き戻された（chain_rolled_back）。
 * 置くのは番号と hash（店の鍵の HMAC。中身は分からない）と真偽だけ。前のバージョンへ戻しても、前のプログラムは読まないだけで壊れない
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE shop_digests ADD COLUMN chain_head_sequence bigint CHECK (chain_head_sequence >= 0)');
        DB::statement("ALTER TABLE shop_digests ADD COLUMN chain_head_hash text CHECK (chain_head_hash ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE shop_digests ADD COLUMN chain_rolled_back boolean');
        // 番号と hash は組（イベントが 1 つも無い店は 0 と NULL）
        DB::statement('ALTER TABLE shop_digests ADD CONSTRAINT shop_digests_chain_head CHECK ((chain_head_hash IS NULL) = (chain_head_sequence IS NULL OR chain_head_sequence = 0))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shop_digests DROP CONSTRAINT shop_digests_chain_head');
        DB::statement('ALTER TABLE shop_digests DROP COLUMN chain_rolled_back');
        DB::statement('ALTER TABLE shop_digests DROP COLUMN chain_head_hash');
        DB::statement('ALTER TABLE shop_digests DROP COLUMN chain_head_sequence');
    }
};
