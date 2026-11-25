<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 商品の CSV を App にした（ADR 0107 の段 1）ので、店で「使う」にする。
 *
 * 核にあった機能が App になると、App は店ごとに使う・使わないを選ぶもの（ADR 0025）なので、既定は「使わない」になる。
 * そのままだと更新した店で商品の CSV の画面（書き出し・取り込み）が消えてしまうため、ここで「使う」の行を足して、
 * 今までどおり使える状態にする。これから作る店も、今までの核の機能と同じように最初から使えるようにそろえる。
 * 止めたい店は管理画面の「App」で止められる（行はそのまま残り、enabled が false になる）。
 *
 * 公開の前の例外（ADR 0006）: 移行で店のデータ（App の使う・使わない）を書く。ふつうは店の人の操作でだけ変わる行で、
 * 変えたことは記録（domain_events）に残すが、ここは前のデータの移し替えなので残さない（店の人の操作ではない）。
 * 前のバージョンへ戻しても、この行があるだけで前のプログラムは今までどおり動く（核の機能だったので印を見ない）。
 * すでに行のある店（1.0 の前に手で止めた・入れ直した店）はそのままにする
 */
return new class extends Migration
{
    private const string PLUGIN = 'product-csv';

    public function up(): void
    {
        if (DB::table('plugin_states')->where('plugin', self::PLUGIN)->exists()) {
            return;
        }

        DB::table('plugin_states')->insert([
            'id' => strtolower((string) Str::ulid()),
            'plugin' => self::PLUGIN,
            'enabled' => true,
            'changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // 足した行は消さない（前のバージョンでは見ない行で、戻してからまた上げた時に「使う」のままにしておく）
    }
};
