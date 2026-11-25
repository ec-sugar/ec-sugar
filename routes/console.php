<?php

use App\Admin\Jobs\QueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 期限の切れたカートを毎日消す（ADR 0021）
Schedule::command('storefront:prune-carts')->dailyAt('04:10')->withoutOverlapping();
// 90 日より前のメールの記録を毎日消す（ADR 0095）
Schedule::command('mail-records:prune')->dailyAt('04:15')->withoutOverlapping(30);
// 最後に動いてから 1 年を過ぎたポイントを毎日消す（ADR 0068 の 5）。重なっても会員ごとに錠の中で確かめ直すので 1 回だけ
Schedule::command('points:expire')->dailyAt('04:20')->withoutOverlapping(30);
// 有効期限を過ぎたギフトカードの残高を毎日期限切れにする（ADR 0076）。重なってもカードごとに錠の中で確かめ直すので 1 回だけ
Schedule::command('gift-cards:expire')->dailyAt('04:25')->withoutOverlapping(30);
// 流し損ねた記録（注文の知らせ・App の受け手）を受け手ごとの印から先へ流し直す（ADR 0089）。ふだんはコミットの直後に流れ、ここは落ちた時の拾い直し。
// 店ごとに 1 本だけが流す（店の DB の鍵）ので、重なっても二重には流さない
Schedule::command('events:relay')->everyMinute()->withoutOverlapping(10);
// 7 日を過ぎた AI エージェントの待ちの案を期限切れにする（ADR 0101）。重なっても案ごとに錠の中で確かめ直すので 1 回だけ
Schedule::command('agent:expire-proposals')->hourlyAt(35)->withoutOverlapping(30);
// AI エージェントの OAuth の使い終わった頼み・リフレッシュトークン・期限の切れた鍵・使われないクライアントを 1 時間ごとに消す（ADR 0101。使われない登録を早く消して、登録の全体の枠を空ける）
Schedule::command('agent:prune-oauth')->hourlyAt(40)->withoutOverlapping(30);
// 払われないまま期限の過ぎた店頭の注文を取り消し、在庫を戻す
Schedule::command('storefront:expire-unpaid-orders')->everyFiveMinutes()->withoutOverlapping();

// 途中の返金を確かめ直す（ADR 0027）。Webhook の取りこぼしと、押し直されないまま残った返金を拾う
Schedule::command('storefront:reconcile-refunds')->everyTenMinutes()->withoutOverlapping();
// つないだ店の Stripe の登録の状態。済んでいない店は 10 分ごと、止められたことに気づくため全店を 1 時間ごと
Schedule::command('storefront:refresh-stripe-connect --pending')->everyTenMinutes()->withoutOverlapping();
Schedule::command('storefront:refresh-stripe-connect')->hourlyAt(25)->withoutOverlapping();

// 運営全体の画面の数（ADR 0042）。店ごとに店の文脈で数え、件数と金額だけを中央に書く。記録の改竄の検証は全件を読むので 1 日 1 回
// 重なり止めの鍵は 30 分で切れる（処理が落ちて鍵が残っても、既定の 24 時間止まらない）。同じ店の 2 つの回が重なっても ShopDigests が店ごとの鍵で順番にする
Schedule::command('operations:collect')->everyTenMinutes()->withoutOverlapping(30);
Schedule::command('operations:collect --verify')->dailyAt('03:40')->withoutOverlapping(30);

// 壊れたことをオーナーに知らせる（ADR 0043）。最初はすぐ、同じものは 1 時間に 1 通まで。キューを通さず直に送る。
// 古い記録（最後が 90 日より前）は夜に消す
Schedule::command('operations:alert')->everyMinute()->withoutOverlapping(10);
Schedule::command('operations:alert --prune')->dailyAt('04:30')->withoutOverlapping(30);
// キューの脈。片付かないまま 10 分経てば、運営の様子と知らせに「キューが止まっている」
Schedule::call(static fn () => QueueHeartbeat::send())->name('operations:queue-heartbeat')->everyFiveMinutes()->withoutOverlapping(10);

// デモの店（ADR 0086）を毎晩、作った時の状態に戻す（来訪者の注文・会員・申し込み・止めたメールを店の DB ごと消して作り直す）。
// 印のある店（ID が demo で始まり、スクリプトを流し終えた店）だけ。ほかの店には触れない
Schedule::command('demo:reset')->dailyAt('04:50')->withoutOverlapping(120);
// デモの店の売れる数（在庫・予約の枠・予約販売の数・席）を 1 時間ごとに作った時の数へ戻す（ADR 0086。払わずに押さえて翌朝まで売り切れにさせない）。
// 戻し（demo:reset）と重なったら、店ごとの錠でその店を飛ばす
Schedule::command('demo:restock')->hourlyAt(20)->withoutOverlapping(30);
