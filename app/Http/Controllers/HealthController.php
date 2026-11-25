<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * 確かめ口（/up）。更新の確かめ（deploy/lib/common.sh の ec_health）・ブラウザのテストの起動待ちが、応答の番号だけを見る。
 *
 * Laravel の既定の確かめ口は、外のサイトのスクリプトと字体を読み込む HTML で、どの枠組みかを見せる（攻撃テスト第 2 回）。
 * ここでは「ok」か「down」の文字だけを返し、保存も検索も断る。中身の確かめは今までどおり DiagnosingHealth の聞き手
 * （中央の DB に流していない移行があれば 500。ADR 0043 の 4）で、落ちたら報告して 500（デバッグの時も、例外の中身は見せない）。
 *
 * 接続元は絞らない（管理画面のホストでも、管理画面の接続元の制限を通さない）: 更新の確かめはサーバー自身の住所から入口を通して聞くので、
 * 絞ると本番で確かめが落ち、前のバージョンへ戻ってしまう。管理画面のホスト名は証明書の公開の記録から分かるので、隠す守りは小さい（2026-11-04）
 */
final class HealthController
{
    public function __invoke(): Response
    {
        try {
            Event::dispatch(new DiagnosingHealth);
            $up = true;
        } catch (Throwable $e) {
            report($e);
            $up = false;
        }

        return response($up ? "ok\n" : "down\n", $up ? 200 : 500, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
