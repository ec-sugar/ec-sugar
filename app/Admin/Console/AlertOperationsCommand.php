<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Mail\OperationsAlertMail;
use App\Admin\Models\Incident;
use App\Admin\Models\Operator;
use App\Admin\Services\Anomalies;
use App\Admin\Services\AppKeyBackup;
use App\Admin\Services\OperationsOverview;
use App\Admin\Services\ShopDigests;
use App\Core\Mail\MailHeaderText;
use App\Core\Money\Money;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 壊れたことをオーナーに知らせる（ADR 0043）。毎分（routes/console.php）。
 *
 *   operations:alert           知らせることがあれば、止めていないオーナー全員へ 1 人 1 通にまとめて送る
 *   operations:alert --prune   最後に起きたのが 90 日より前の記録を消す（夜に 1 回）
 *
 * 知らせるもの:
 * - まだ知らせていないエラーの記録と、前に知らせた後に回数が増えて 1 時間を過ぎた記録（同じものは 1 時間に 1 通まで）
 * - 運営の様子の要対応の困りごとで、新しく出たもの（出ている間は 1 回だけ。消えて出直したらまた。覚えるのは中央の alerted_issues）
 * - おかしな動き（ADR 0103）: 回の始めに Anomalies が数え、見つけたものは要対応の困りごととして上と同じ決まりで知らせる
 * - 「近いうちに」の困りごとは知らせない。例外は REMINDERS に挙げたものだけ（APP_KEY の控え）で、要対応と同じ決まり
 *   （出ている間は 1 回だけ。答えるまで運営の様子には出し続けるが、メールは送り続けない）。メールでは「近いうちに」の節に分けて載せる
 *
 * 定期の処理のほかに、サーバーの見張り（ec-sugar watch）も走らせる（定期の処理が止まったことを知らせられるように）。
 * キューを通さず直に送る（キューが壊れたことをキューで知らせない）。1 人にも送れなければ印を付けず、次の回にまた試す
 */
final class AlertOperationsCommand extends Command
{
    /** 同じ記録を知らせ直すまでの間（分） */
    public const int REPEAT_MINUTES = 60;

    /** 記録を残す日数（最後に起きた時から） */
    public const int KEEP_DAYS = 90;

    /** 1 通に載せるエラーの数（残りは数だけ） */
    public const int MAIL_LIMIT = 20;

    /** 「近いうちに」の困りごとのうち、メールでも 1 回だけ知らせるもの（画面を開かないと気づけず、気づかないままだと取り返しがつかないもの） */
    public const array REMINDERS = [AppKeyBackup::ISSUE];

    /** 回どうしの鍵（送るのに時間がかかっても、落ちて残っても 2 分で切れる） */
    public const string LOCK = 'operations:alert';

    public const int LOCK_SECONDS = 120;

    protected $signature = 'operations:alert
        {--prune : 古い記録（最後に起きたのが 90 日より前）を消すだけ}';

    protected $description = '障害（エラー・要対応の問題）を、停止していないオーナーにメールで通知する';

    public function handle(OperationsOverview $overview): int
    {
        $now = CarbonImmutable::now();

        if ((bool) $this->option('prune')) {
            $removed = Incident::query()->where('last_at', '<', $now->subDays(self::KEEP_DAYS)->utc())->delete();
            // おかしな動きの記録も（最後に見つけたのが 30 日より前。ADR 0103）
            $anomalies = Anomalies::prune($now);
            $this->info("古い記録を {$removed} 件、おかしな動きの記録を {$anomalies} 件消しました");

            return self::SUCCESS;
        }

        // 定期の処理（毎分）とサーバーの見張り（ec-sugar watch、10 分ごと。定期の処理が止まっても知らせる）の両方から走る。
        // 重なると同じ知らせが 2 通になるので、鍵で 1 回ずつ。取れなければ、ほかの回に任せる
        // 鍵の置き場（Redis）が壊れている時は鍵なしで送る（2 通になるより、届かないほうが困る）
        $lock = null;

        try {
            $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

            if (! $lock->get()) {
                $this->info('ほかの回が通知しているところです');

                return self::SUCCESS;
            }
        } catch (Throwable $e) {
            report($e);
            $lock = null;
        }

        try {
            return $this->notify($overview, $now);
        } finally {
            try {
                $lock?->release();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function notify(OperationsOverview $overview, CarbonImmutable $now): int
    {
        // おかしな動きを先に数える（ADR 0103）。見つけたものは運営の様子の要対応に出て、下の困りごとと同じ決まりで知らせる。
        // 数えられなくても、エラーとほかの困りごとの知らせは止めない
        try {
            Anomalies::detect($now);
        } catch (Throwable $e) {
            report($e);
        }

        $due = Incident::query()
            ->where(static fn (Builder $query) => $query->whereNull('notified_at')->orWhere(
                static fn (Builder $query) => $query->where('notified_at', '<=', $now->subMinutes(self::REPEAT_MINUTES)->utc())->whereColumn('count', '>', 'notified_count'),
            ))
            ->orderByDesc('last_at')->orderBy('fingerprint')
            ->get();

        [$current, $texts, $soon] = $this->troubles($overview, $now);
        $central = DB::connection(ShopDigests::central());
        $known = $central->table('alerted_issues')->pluck('signature')->all();

        // 消えた困りごとは忘れる（出直したら、また知らせる）
        $gone = array_values(array_diff($known, array_keys($current)));

        if ($gone !== []) {
            $central->table('alerted_issues')->whereIn('signature', $gone)->delete();
        }

        $new = array_diff_key($current, array_flip($known));

        if ($due->isEmpty() && $new === []) {
            return self::SUCCESS;
        }

        $owners = Operator::query()->where('role', 'owner')->whereNull('disabled_at')->orderBy('created_at')->orderBy('id')->get();

        if ($owners->isEmpty()) {
            $this->error('通知する相手（停止していないオーナー）がいません。');

            return self::FAILURE;
        }

        $names = Tenant::query()->whereIn('id', $due->pluck('tenant_id')->filter()->unique()->values())->pluck('name', 'id')->all();
        $timezone = ShopDigests::timezone();
        $items = $due->take(self::MAIL_LIMIT)->map(static fn (Incident $incident): array => [
            'kind' => $incident->kind,
            'exception' => class_basename($incident->exception),
            'location' => $incident->location,
            'shop' => $incident->tenant_id === null ? null : MailHeaderText::line((string) ($names[$incident->tenant_id] ?? $incident->tenant_id)),
            'count' => $incident->count,
            'added' => $incident->notified_count === null ? null : $incident->count - $incident->notified_count,
            'first_at' => $incident->first_at->setTimezone($timezone)->format('Y年n月j日 H:i'),
        ])->values()->all();
        $more = max(0, $due->count() - self::MAIL_LIMIT);
        // 要対応と「近いうちに」（REMINDERS）を節に分ける
        $troubles = array_values(array_diff_key(array_intersect_key($texts, $new), $soon));
        $reminders = array_values(array_intersect_key($texts, $new, $soon));

        $sent = 0;

        foreach ($owners as $owner) {
            try {
                // send（直に送る）。この知らせはキューに載せない
                Mail::to($owner->email)->send(new OperationsAlertMail($owner->name, $items, $troubles, $more, $reminders));
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($sent === 0) {
            $this->error('通知のメールを 1 通も送れませんでした（次の回にまた試します）');

            return self::FAILURE;
        }

        // 送れたら印を付ける。回数は読んだ時の数を残す（読んだ後に増えた分は、次の知らせに入る）
        $central->transaction(function () use ($central, $due, $new, $now): void {
            foreach ($due as $incident) {
                Incident::query()->whereKey($incident->fingerprint)->update(['notified_at' => $now->utc(), 'notified_count' => $incident->count]);
            }

            $central->table('alerted_issues')->insertOrIgnore(array_map(
                static fn (string $signature): array => ['signature' => $signature, 'notified_at' => $now->utc()],
                array_keys($new),
            ));
        });

        $this->info("通知しました（オーナー {$sent} 人・エラー {$due->count()} 件・要対応 ".count($new).' 件）');

        return self::SUCCESS;
    }

    /**
     * 運営の様子の要対応の困りごと（エラーの 1 行は除く。エラーは上で 1 件ずつ知らせる）と、「近いうちに」のうち REMINDERS のもの。
     * 読めなければ困りごとは無しにして、エラーだけを知らせる
     *
     * @return array{0: array<string, true>, 1: array<string, string>, 2: array<string, true>} 困りごとの印（種類:店）の集まり・印ごとの文・そのうち「近いうちに」の印
     */
    private function troubles(OperationsOverview $overview, CarbonImmutable $now): array
    {
        try {
            $issues = $overview->present($now)['issues'];
        } catch (Throwable $e) {
            report($e);

            return [[], [], []];
        }

        $current = [];
        $texts = [];
        $soon = [];

        foreach ($issues as $issue) {
            $reminder = $issue['level'] === 'soon' && in_array($issue['key'], self::REMINDERS, true);

            if (($issue['level'] !== 'urgent' && ! $reminder) || $issue['key'] === 'incidents') {
                continue;
            }

            // ホストの困りごとは、店の代わりに subject（ホスト名・置き場・バージョン）で分ける（別のホストの証明書・別のバージョンの失敗はまた知らせる）
            $signature = mb_strcut($issue['key'].':'.($issue['tenant']['id'] ?? $issue['subject'] ?? ''), 0, 128, 'UTF-8');
            $current[$signature] = true;
            $texts[$signature] = self::describe($issue);

            if ($reminder) {
                $soon[$signature] = true;
            }
        }

        return [$current, $texts, $soon];
    }

    /**
     * 困りごとの文（運営の様子の画面と同じ言い方）
     *
     * @param  array{key: string, count: int, tenant: array{id: string, name: string}|null, subject?: string, amount?: int}  $issue
     */
    private static function describe(array $issue): string
    {
        $shop = MailHeaderText::line((string) ($issue['tenant']['name'] ?? ''));
        $count = $issue['count'];
        // ホストの困りごとの対象（ホスト名・置き場・バージョン。ホストの報告で形を確かめた値だけ）
        $subject = MailHeaderText::line($issue['subject'] ?? '');
        $disk = ['data' => 'データ（DB・写真）', 'backup' => 'バックアップ'][$subject] ?? $subject;
        $version = $subject !== '' ? "（バージョン {$subject}）" : '';

        return match ($issue['key']) {
            'collect_failed' => "「{$shop}」の集計ができませんでした（前回の数値のままです）",
            'stray_payments' => "「{$shop}」に返金が必要な支払いが {$count} 件あります",
            'refund_issues' => "「{$shop}」に確認が必要な返金が {$count} 件あります",
            'chain_broken' => "「{$shop}」の記録の検証に失敗しました（あとから書き換えられたかもしれません）",
            'chain_rolled_back' => "「{$shop}」の記録が、前に確認した時より前の状態に戻っています（末尾を削除されたかもしれません）",
            'media_quota' => "「{$shop}」の写真の保存先が上限に達しました（使用量 {$count}%。新しい写真をアップロードできません）",
            'failed_jobs' => "この 7 日に失敗したバックグラウンド処理が {$count} 件あります",
            'queue_backlog' => "バックグラウンド処理の待ちが {$count} 件たまっています",
            'queue_unreachable' => 'バックグラウンド処理のキューに接続できません',
            'queue_stopped' => "バックグラウンド処理（キュー）が {$count} 分進んでいません（注文の通知のメールなどが送られていません）",
            'schedule_stopped' => '定期の処理が 30 分以上動いていません（運営ダッシュボードの数値が古いままです）',
            // ホスト（ec-sugar）からの報告
            'certificate_expired' => "{$subject} の証明書の期限が切れています",
            'certificate_urgent' => "{$subject} の証明書の期限まで {$count} 日です（更新が遅れています）",
            'disk_critical' => "{$disk}の保存先の空きが {$count}% です",
            'update_failed' => "更新に失敗し、前のバージョンへのロールバックもヘルスチェックを通りませんでした{$version}",
            'update_rolled_back' => "更新がヘルスチェックを通らず、前のバージョンへロールバックしました{$version}",
            'update_stopped' => "更新を途中で止めました（前のバージョンのまま動いています）{$version}",
            'backup_failed' => '最後のバックアップに失敗しました',
            'backup_stale' => 'バックアップが 48 時間以上取れていません',
            // 外への通信の出口の代理（ADR 0102。要対応になるのは代理が止まった時と、止める形で断った時）
            'egress_proxy_down' => '外部への通信に使う送信用プロキシが止まっています（支払い・LINE・Slack などへ送信できません。サーバーで sudo ec-sugar status）',
            'egress_failing' => "この 1 時間、外部への通信が {$count} 件接続できず、1 件も成功していません（支払いが止まっているかもしれません。サーバーで sudo ec-sugar egress）",
            'egress_refused' => "この 24 時間に、外部への通信を {$count} 件ブロックしました（許可リストが足りないかもしれません。サーバーで sudo ec-sugar egress）",
            'egress_unlisted' => "この 24 時間に、許可リストに無い宛先への通信を {$count} 件許可しました（ブロックモードにする前に、サーバーで sudo ec-sugar egress で見直してください）",
            // Cloudflare Tunnel の見張り（見直しの中 5）
            'tunnel_foreign' => 'ほかの場所からトンネルに接続されています（トークンが漏れたかもしれません）',
            'tunnel_down' => 'トンネルが接続されていません（ショップと管理画面に外からアクセスできません）',
            // 中の照らしと外の見張り（ADR 0105）。違いは名前だけ（値・パスは持っていない）
            'integrity_mismatch' => 'サーバー上のファイルや設定が、基準の状態と違います'.($subject !== '' ? "（{$subject}）" : '').'。更新や設定の変更に心当たりが無ければ、不正に侵入されたかもしれません（sudo ec-sugar status で確認してください）',
            'integrity_lock_held' => 'ec-sugar の処理のロックが 30 分以上解除されていません（更新やバックアップが止まっていないか、sudo ec-sugar status で確認してください）',
            'sentinel_silent' => '外部監視（ec-sugar-sentinel）からの確認が 30 分以上届いていません（監視用のサーバーが止まったか、無効にされた可能性があります）',
            // サーバーの外のバックアップ（ADR 0110）。保存先の名前・鍵は載せない（持っていない）
            'offsite_failed' => 'サーバーの外へ、バックアップを送れていません（待っても直らない理由か、まだ 1 回も送れていません。サーバーで sudo ec-sugar offsite status）',
            'offsite_stale' => 'サーバーの外へ、バックアップを 48 時間以上送れていません（サーバーで sudo ec-sugar offsite status）',
            'offsite_heads_failed' => '記録の連鎖の先頭（店ごとの最後の記録の番号と hash）を、サーバーの外へ送れていません（次の回で送り直します。続く時はサーバーで sudo ec-sugar offsite status）',
            'offsite_heads_stale' => '記録の連鎖の先頭を、サーバーの外へ 48 時間以上送れていません（サーバーで sudo ec-sugar offsite status）',
            'offsite_lock_open' => 'サーバーの外の保存先で、送ったバックアップを上書き・削除できる状態です（削除できない期間の設定が解除されているか、設定されていません。保存先の画面で確認してください）',
            // おかしな動き（ADR 0103）。数と種類だけ（接続元・鍵・運営・お客さんは載せない。持っていない）
            Anomalies::REFUNDS => "「{$shop}」で、1 時間に返金が {$count} 件・合計 ".Money::format($issue['amount'] ?? 0).' ありました（心当たりが無ければ、取引とログイン履歴を確認してください）',
            Anomalies::PAYMENT_KEYS => "「{$shop}」の支払いの設定の鍵（Stripe）が、深夜かいつもと違う接続元から {$count} 回変更されました（心当たりが無ければ、Stripe の鍵を作り直してください）",
            Anomalies::DENIALS => "同じ接続元から、多くの店へのアクセスやログインの失敗が続いています（{$count} か所。ログイン履歴で接続元を確認してください）",
            Anomalies::AGENT_WRITES => $shop === ''
                ? "いくつかの店にまたがって、1 つの AI エージェントの接続から 1 時間に {$count} 件の変更の操作がありました（心当たりが無ければ、接続を取り消してください）"
                : "「{$shop}」で、1 つの AI エージェントのアクセスキーから 1 時間に {$count} 件の変更の操作がありました（心当たりが無ければ、アクセスキーを取り消してください）",
            Anomalies::ACCESS_CHANGES => "短い間に、メンバーの追加・オーナーへの変更・パスキーの回復の申し込み・AI エージェントのアクセスキーの発行のうち {$count} 種類が重なりました（心当たりが無ければ、メンバーとアクセスキーを確認してください）",
            // APP_KEY の控え。鍵・設定の値は載せない（持っていない）
            AppKeyBackup::ISSUE => '復元のキットをまだ作っていません。サーバーを失うと、バックアップがあってもお客さんの個人情報を復号できません。管理画面の運営ダッシュボードの「復元のキット」から作れます（サーバーで sudo ec-sugar app-key でバックアップを取った場合は、運営ダッシュボードで「バックアップを取りました」を押してください）',
            default => $issue['key'].($shop !== '' ? "（「{$shop}」）" : ''),
        };
    }
}
