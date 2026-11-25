<script setup>
import { computed, onMounted, ref } from 'vue';
import { api, describe as describeError } from '../api.js';
import { ConfirmationCancelled, withConfirmation } from '../confirmation.js';
import { formatAmount, formatBytes, formatDate } from '../format.js';
import { crumbsFor } from '../navigation.js';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import CellTitle from '../components/ui/CellTitle.vue';
import CodeText from '../components/ui/CodeText.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import DailyBars from '../components/ui/DailyBars.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import HealthList from '../components/ui/HealthList.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import NumCell from '../components/ui/NumCell.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import RecoveryKitPanel from '../components/RecoveryKitPanel.vue';
import StatCard from '../components/ui/StatCard.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';
import TextLink from '../components/ui/TextLink.vue';

// 運営全体の様子（ADR 0042。案 A「困りごとを先に」）。オーナーだけ（API が RequireOwner で断る）。
// 上に要対応・近いうちにの通知（無ければ 1 行）→ 店の数・今月の売上・今月の注文・動いているバージョン → 売上の動きと機械の調子 → 店の一覧。
// 数はどれも、各店が店の中で数えて中央に置いた件数と金額（この画面は店の DB を読まない）。文はここで作り、サーバーは何が何件かだけを返す
const data = ref(null);
const error = ref(null);

async function load() {
    try {
        data.value = await api('GET', '/api/operations');
    } catch (e) {
        error.value = e;
    }
}

onMounted(load);

// 流し損ねた・止めた通知を、店ごとに流し直す（ADR 0089）。オーナーだけ・直前の再確認の後（API が求める。再確認の画面は api.js が出す）
const retrying = ref(null);
const retryResult = ref(null);

async function retry(tenantId) {
    retrying.value = tenantId;
    retryResult.value = null;

    try {
        const result = await api('POST', `/api/tenants/${tenantId}/event-retries`);
        retryResult.value = { tone: result.stopped > 0 ? 'warning' : 'success', text: result.busy
            ? 'ほかの処理が実行中でした。少し待ってから、もう一度お試しください。'
            : `再送しました（処理できた通知 ${result.delivered} 件・再送待ち ${result.waiting} 件・停止したもの ${result.stopped} 件）。` };
        await load();
    } catch {
        retryResult.value = { tone: 'error', text: '再送できませんでした。もう一度お試しください。' };
    } finally {
        retrying.value = null;
    }
}

// 「APP_KEY の控えをサーバーの外に取りました」と答える。オーナーだけ・直前の再確認の後（API が 423 を返したら、withConfirmation が本人確認の画面を出してからやり直す）。
// 送るものは無い（鍵・設定の値はこの画面に来ない・送らない）。答えると、困りごとが消える
const confirmingBackup = ref(false);
const backupResult = ref(null);

async function confirmBackup() {
    confirmingBackup.value = true;
    backupResult.value = null;

    try {
        await withConfirmation(() => api('POST', '/api/operations/app-key-backup'));
        backupResult.value = { tone: 'success', text: 'APP_KEY のバックアップを取ったことを記録しました。' };
        await load();
    } catch (error) {
        // 本人確認を取り消しただけなら、誤りとして出さない。ほかは理由を出す（権限が無い・回数が多い など）
        if (!(error instanceof ConfirmationCancelled)) {
            backupResult.value = { tone: 'error', text: `記録できませんでした。${describeError(error)}` };
        }
    } finally {
        confirmingBackup.value = false;
    }
}

// 困りごとの文と行き先（店の中の該当の画面）
const ISSUES = {
    incidents: { text: (i) => `この 24 時間に起きたエラーが ${i.count} 件あります（下の「エラー」で場所と回数を見られます）`, to: null },
    collect_failed: { text: (i) => `「${i.tenant.name}」の集計ができませんでした（前回の数値のままです）`, to: null },
    stray_payments: { text: (i) => `「${i.tenant.name}」に返金が必要な支払いが ${i.count} 件あります`, to: (i) => ({ name: 'tenant', params: { tenant: i.tenant.id } }) },
    refund_issues: { text: (i) => `「${i.tenant.name}」に確認が必要な返金が ${i.count} 件あります`, to: (i) => ({ name: 'tenant', params: { tenant: i.tenant.id } }) },
    chain_broken: { text: (i) => `「${i.tenant.name}」の記録の検証に失敗しました（あとから書き換えられたかもしれません）`, to: (i) => ({ name: 'settings-records', params: { tenant: i.tenant.id } }) },
    chain_rolled_back: { text: (i) => `「${i.tenant.name}」の記録が、前に確認した時より前の状態に戻っています（末尾を削除されたかもしれません）`, to: (i) => ({ name: 'settings-records', params: { tenant: i.tenant.id } }) },
    stuck_mails: { text: (i) => `「${i.tenant.name}」で、お客さんに届いていない通知のメールが ${i.count} 通あります`, to: (i) => ({ name: 'transactions', params: { tenant: i.tenant.id } }) },
    // 流し損ねた通知（ADR 0089）。止めたものは、原因を直してから「流し直す」。待ちは毎分の見回りが届け直す
    events_stopped: { text: (i) => `「${i.tenant.name}」で、何度も失敗したため停止した通知が ${i.count} 件あります（下の「エラー」で原因を解決してから再送してください）`, to: null, retry: true },
    events_waiting: { text: (i) => `「${i.tenant.name}」で、再送待ちの通知が ${i.count} 件あります（自動で再送します）`, to: null, retry: true },
    media_quota: { text: (i) => i.count >= 100 ? `「${i.tenant.name}」の写真の保存先が上限に達しました（新しい写真をアップロードできません）` : `「${i.tenant.name}」の写真の保存先が上限の ${i.count}% です`, to: (i) => ({ name: 'photos', params: { tenant: i.tenant.id } }) },
    failed_jobs: { text: (i) => `この 7 日に失敗したバックグラウンド処理が ${i.count} 件あります`, to: null },
    queue_backlog: { text: (i) => `バックグラウンド処理の待ちが ${i.count} 件たまっています`, to: null },
    queue_unreachable: { text: () => 'バックグラウンド処理のキューに接続できません', to: null },
    queue_stopped: { text: (i) => `バックグラウンド処理（キュー）が ${i.count} 分進んでいません（注文の通知のメールなどが送られていません）`, to: null },
    schedule_stopped: { text: () => '定期の処理が 30 分以上動いていません（この画面の数値が古いままです）', to: null },
    not_collected: { text: (i) => `まだ集計していない店が ${i.count} 店あります（定期の処理が 10 分ごとに集計します）`, to: null },
    // ホスト（サーバーの ec-sugar）からの報告。subject はホスト名・置き場・バージョン
    certificate_expired: { text: (i) => `${i.subject} の証明書の期限が切れています`, to: null },
    certificate_urgent: { text: (i) => `${i.subject} の証明書の期限まで ${i.count} 日です（更新が遅れています）`, to: null },
    certificate_expiring: { text: (i) => `${i.subject} の証明書の期限まで ${i.count} 日です`, to: null },
    certificate_unreadable: { text: (i) => `${i.subject} の証明書を読み取れませんでした`, to: null },
    disk_critical: { text: (i) => `${DISKS[i.subject] ?? i.subject}の保存先の空きが ${i.count}% です`, to: null },
    disk_low: { text: (i) => `${DISKS[i.subject] ?? i.subject}の保存先の空きが ${i.count}% です`, to: null },
    update_failed: { text: (i) => `更新に失敗し、前のバージョンへのロールバックもヘルスチェックを通りませんでした${versionOf(i)}`, to: null },
    update_rolled_back: { text: (i) => `更新がヘルスチェックを通らず、前のバージョンへロールバックしました${versionOf(i)}`, to: null },
    update_stopped: { text: (i) => `更新を途中で止めました（前のバージョンのまま動いています）${versionOf(i)}`, to: null },
    update_not_checked: { text: () => '自動更新が 48 時間以上、更新の取得元に接続できていません', to: null },
    backup_failed: { text: () => '最後のバックアップに失敗しました', to: null },
    backup_stale: { text: () => 'バックアップが 48 時間以上取れていません', to: null },
    backup_missing: { text: () => 'バックアップがまだ一度も取れていません', to: null },
    host_report_stale: { text: () => 'サーバーの監視の報告が届いていません（48 時間以上）', to: null },
    // 外への通信の出口の代理（ADR 0102）。宛先の名前はサーバーの sudo ec-sugar egress で見る
    egress_proxy_down: { text: () => '外部への通信に使う送信用プロキシが止まっています（支払い・LINE・Slack などへ送信できません。サーバーで sudo ec-sugar status）', to: null },
    egress_failing: { text: (i) => `この 1 時間、外部への通信が ${i.count} 件接続できず、1 件も成功していません（支払いが止まっているかもしれません。サーバーで sudo ec-sugar egress）`, to: null },
    egress_refused: { text: (i) => `この 24 時間に、外部への通信を ${i.count} 件ブロックしました（許可リストが足りないかもしれません。サーバーで sudo ec-sugar egress）`, to: null },
    egress_unlisted: { text: (i) => `この 24 時間に、許可リストに無い宛先への通信を ${i.count} 件許可しました（ブロックモードにする前に、サーバーで sudo ec-sugar egress で見直してください）`, to: null },
    // Cloudflare Tunnel の見張り（見直しの中 5。ec-sugar watch が 10 分ごと）
    tunnel_foreign: { text: () => 'ほかの場所からトンネルに接続されています（トークンが漏れたかもしれません）', to: null },
    tunnel_down: { text: () => 'トンネルが接続されていません（ショップと管理画面に外からアクセスできません）', to: null },
    tunnel_unknown: { text: () => 'トンネルの接続元を確認できませんでした（Cloudflare に届かないか、監視用の鍵の権限が足りません）', to: null },
    tunnel_watch_stale: { text: () => 'トンネルの監視が 1 時間以上動いていません', to: null },
    // 中の照らしと外の見張り（ADR 0105。ec-sugar integrity が毎分）。subject は違いの名前
    integrity_mismatch: { text: (i) => `サーバー上のファイルや設定が、基準の状態と違います${i.subject ? `（${i.subject}）` : ''}。更新や設定の変更に心当たりが無ければ、不正に侵入されたかもしれません（sudo ec-sugar status で確認してください）`, to: null },
    integrity_lock_held: { text: () => 'ec-sugar の処理のロックが 30 分以上解除されていません（更新やバックアップが止まっていないか、sudo ec-sugar status で確認してください）', to: null },
    sentinel_silent: { text: () => '外部監視（ec-sugar-sentinel）からの確認が 30 分以上届いていません（監視用のサーバーが止まったか、無効にされた可能性があります）', to: null },
    // サーバーの外のバックアップ（ADR 0110。保存先を設定したサーバーだけ）。subject は失敗の理由の決まった語
    offsite_failed: { text: (i) => `サーバーの外へ、バックアップを送れませんでした${OFFSITE_REASONS[i.subject] ? `（${OFFSITE_REASONS[i.subject]}）` : ''}。サーバーで sudo ec-sugar offsite status`, to: null },
    offsite_stale: { text: () => 'サーバーの外へ、バックアップを 48 時間以上送れていません（サーバーで sudo ec-sugar offsite status）', to: null },
    offsite_heads_failed: { text: () => '記録の連鎖の先頭（店ごとの最後の記録の番号と hash）を、サーバーの外へ送れていません（次の回で送り直します。続く時はサーバーで sudo ec-sugar offsite status）', to: null },
    offsite_heads_stale: { text: () => '記録の連鎖の先頭を、サーバーの外へ 48 時間以上送れていません（サーバーで sudo ec-sugar offsite status）', to: null },
    offsite_lock_open: { text: () => 'サーバーの外の保存先で、送ったバックアップを上書き・削除できる状態です（削除できない期間の設定が解除されているか、設定されていません。保存先の画面で確認してください）', to: null },
    // APP_KEY の控え。オーナーが「控えを取りました」と答えるまで出し続ける（ec-sugar で入れたサーバーだけ）
    app_key_backup_unconfirmed: { text: () => '復元のキットをまだ作っていません。サーバーを失うと、バックアップがあってもお客さんの個人情報を復号できません。下の「復元のキット」から作れます（サーバーで sudo ec-sugar app-key でバックアップを取った場合は、右のボタン）', to: null, backup: true },
    // おかしな動き（ADR 0103）。最後に見つけてから 24 時間出る。数と種類だけ（接続元・鍵は出さない。ログイン履歴で見る）
    anomaly_refunds: { text: (i) => `「${i.tenant.name}」で、1 時間に返金が ${i.count} 件・合計 ${formatAmount(i.amount ?? 0, 'JPY')} ありました（心当たりが無ければ、取引とログイン履歴を確認してください）`, to: (i) => ({ name: 'transactions', params: { tenant: i.tenant.id } }) },
    anomaly_payment_keys: { text: (i) => `「${i.tenant.name}」の支払いの設定の鍵（Stripe）が、深夜かいつもと違う接続元から ${i.count} 回変更されました（心当たりが無ければ、Stripe の鍵を作り直してください）`, to: (i) => ({ name: 'settings-payments', params: { tenant: i.tenant.id } }) },
    anomaly_denials: { text: (i) => `同じ接続元から、多くの店へのアクセスやログインの失敗が続いています（${i.count} か所。ログイン履歴で接続元を確認してください）`, to: () => ({ name: 'login-history' }) },
    // 店が空: 2 店以上のつなぎで、店に散らした書き込み（ADR 0104）
    anomaly_agent_writes: { text: (i) => (i.tenant ? `「${i.tenant.name}」で、1 つの AI エージェントのアクセスキーから 1 時間に ${i.count} 件の変更の操作がありました（心当たりが無ければ、アクセスキーを取り消してください）` : `いくつかの店にまたがって、1 つの AI エージェントの接続から 1 時間に ${i.count} 件の変更の操作がありました（心当たりが無ければ、接続を取り消してください）`), to: null },
    anomaly_access_changes: { text: (i) => `短い間に、メンバーの追加・オーナーへの変更・パスキーの回復の申し込み・AI エージェントのアクセスキーの発行のうち ${i.count} 種類が重なりました（心当たりが無ければ、メンバーとアクセスキーを確認してください）`, to: () => ({ name: 'members' }) },
};

// サーバーの外へ送れなかった理由（ホストが渡す決まった語 → 人の言葉。知らない語は理由なしで出す）
const OFFSITE_REASONS = {
    config: '保存先の設定が読めません',
    'curl-old': 'サーバーの curl が古くて送れません',
    'no-gpg': '暗号化に使う gpg がサーバーにありません',
    'no-recipient': '暗号化の鍵がまだありません（下の「復元のキット」を作ると、その鍵で送り始めます）',
    'bad-recipient': '暗号化の公開鍵が使えません。期限が切れたか、壊れています',
    put: '保存先にアップロードできませんでした。時間をおいて自動で再送します',
    'gave-up': '何度再送しても保存先にアップロードできなかったため、自動の再送を止めました',
};

// 空き容量の置き場の名前（data は Docker の置き場で DB と写真、backup は /srv/ec-sugar のバックアップ）
const DISKS = { data: 'データ（DB・写真）', backup: 'バックアップ' };

function versionOf(issue) {
    return issue.subject ? `（バージョン ${issue.subject}）` : '';
}

function describe(issue) {
    const kind = ISSUES[issue.key];

    return {
        key: `${issue.key}-${issue.tenant?.id ?? issue.subject ?? 'all'}`,
        text: kind ? kind.text(issue) : issue.key,
        to: kind?.to ? kind.to(issue) : null,
        retry: kind?.retry && issue.tenant ? issue.tenant.id : null,
        backup: kind?.backup === true,
    };
}

const urgent = computed(() => (data.value?.issues ?? []).filter((issue) => issue.level === 'urgent').map(describe));
const soon = computed(() => (data.value?.issues ?? []).filter((issue) => issue.level === 'soon').map(describe));

const version = computed(() => data.value?.version ?? null);
const dailyTotal = computed(() => (data.value?.daily ?? []).reduce((sum, day) => ({ sales: sum.sales + day.sales, orders: sum.orders + day.orders }), { sales: 0, orders: 0 }));
const health = computed(() => {
    const h = data.value?.health;

    if (!h) {
        return [];
    }

    // ホストの報告が一度も無い（ステージングや手元の開発）なら「まだ見ていません」の札だけ
    const NOT_YET = 'サーバーの監視（ec-sugar）の報告がまだありません';
    let chainText = 'まだ確認していません（1 日 1 回、夜に確認します）';

    if (h.chain.broken > 0) {
        chainText = `${h.chain.broken} 店で失敗しました`;
    } else if (h.chain.status === 'ok') {
        chainText = `${h.chain.unchecked > 0 ? `未確認の店 ${h.chain.unchecked}・` : ''}確認した時刻 ${formatDate(h.chain.checked_at)}`;
    }

    const items = [
        { key: 'schedule', label: '定期の処理', status: h.schedule.status, text: h.schedule.at ? `最後に集計した時刻 ${formatDate(h.schedule.at)}` : 'まだ一度も集計していません', test: 'health-schedule' },
        { key: 'queue', label: 'キュー（バックグラウンド処理）', status: h.queue.status, text: h.queue.waiting === null ? '接続できません' : `待ち ${h.queue.waiting} 件・この 7 日の失敗 ${h.queue.failed ?? '—'} 件`, test: 'health-queue' },
        { key: 'mail', label: '通知のメール', status: h.mail.status, text: h.mail.stuck > 0 ? `届いていないメール ${h.mail.stuck} 通` : '届いていないメールはありません', test: 'health-mail' },
        { key: 'chain', label: '記録の改竄の検証', status: h.chain.status, text: chainText, test: 'health-chain' },
        { key: 'certificates', label: '証明書の期限', status: h.certificates.status, text: certificatesText(h.certificates) ?? NOT_YET, test: 'health-certificates' },
        { key: 'disk', label: '空き容量', status: h.disk.status, text: diskText(h.disk) ?? NOT_YET, test: 'health-disk' },
        { key: 'updates', label: '更新', status: h.updates.status, text: updatesText(h.updates) ?? NOT_YET, test: 'health-updates' },
        { key: 'backup', label: 'バックアップ', status: h.backup?.status ?? 'unknown', text: backupText(h.backup) ?? NOT_YET, test: 'health-backup' },
        { key: 'host', label: 'サーバーの監視', status: h.host?.status ?? 'unknown', text: h.host?.reported_at ? `最後の報告 ${formatDate(h.host.reported_at)}（毎晩と、更新・バックアップの後。トンネルの状態は 10 分ごと）` : NOT_YET, test: 'health-host' },
    ];

    // Cloudflare Tunnel の形のときだけ（ほかの形では報告に無く、null）
    if (h.tunnel) {
        items.push({ key: 'tunnel', label: 'トンネル', status: h.tunnel.status, text: tunnelText(h.tunnel), test: 'health-tunnel' });
    }

    // 中の照らし（ec-sugar integrity の報告があるときだけ）
    if (h.integrity) {
        items.push({ key: 'integrity', label: 'サーバーの改竄の検証', status: h.integrity.status, text: integrityText(h.integrity), test: 'health-integrity' });
    }

    // サーバーの外のバックアップ（保存先を設定したサーバーだけ。設定していなければ報告に無く、null）
    if (h.offsite) {
        items.push({ key: 'offsite', label: 'サーバーの外のバックアップ', status: h.offsite.status, text: offsiteText(h.offsite), test: 'health-offsite' });
    }

    // 外への通信の出口の代理（本番の形だけ。開発の形では無い）
    if (h.egress) {
        items.push({ key: 'egress', label: '外部への通信', status: h.egress.status, text: egressText(h.egress), test: 'health-egress' });
    }

    return items;
});

function integrityText(i) {
    // 外の見張りの時刻: 来ていない時は「最後に来た時刻」、来ている時は「来始めた時刻」。ホストは状態が変わった時だけ報告し、
    // 毎回の訪問は報告しないので、来ている間の時刻は進まない（「最後」と書くと、止まっているように見える）
    const inside = i.result === 'mismatch' ? `基準の状態と違う（${i.problems.join('・')}）` : (i.result === 'ok' ? '基準の状態のまま' : 'まだ確認していません');
    const outside = i.sentinel ? (i.sentinel.result === 'silent' ? `外部監視からの確認が届いていない（最後 ${formatDate(i.sentinel.at)}）` : `外部監視からの確認あり（${formatDate(i.sentinel.at)}）`) : '外部監視からの確認はまだ一度も届いていません';

    return `${inside}・${outside}`;
}

const OFFSITE_LOCKS = { ok: '保存先のロック（削除できない設定）は有効です', open: '保存先のロック（削除できない設定）が無効です', unknown: '保存先のロックは確認できませんでした' };

function offsiteText(o) {
    const sent = o.at ? `最後に送信できた時刻 ${formatDate(o.at)}` : 'まだ一度も送信できていません';
    const last = o.result === 'failed' ? `最後の回（${formatDate(o.tried_at)}）は失敗${OFFSITE_REASONS[o.reason] ? `: ${OFFSITE_REASONS[o.reason]}` : ''}・` : '';
    const lock = OFFSITE_LOCKS[o.lock] ? `・${OFFSITE_LOCKS[o.lock]}` : '';

    return `${last}${sent}${lock}`;
}

const TUNNEL_RESULTS = {
    ok: 'このサーバーからだけ接続されています',
    foreign: 'ほかの場所からも接続されています',
    down: '接続されていません',
    unknown: '確認できませんでした',
    unwatched: '監視用の鍵が無いので確認していません',
};

function tunnelText(t) {
    const result = TUNNEL_RESULTS[t.result] ?? t.result;

    return t.result === 'unwatched' ? result : `${result}（確認した時刻 ${formatDate(t.at)}）`;
}

const EGRESS_MODES = { watch: '監視モード', enforce: 'ブロックモード', off: '送信用プロキシを使っていません' };

function egressText(e) {
    const mode = EGRESS_MODES[e.mode] ?? '送信用プロキシのモードが分かりません';

    if (e.mode === 'off') {
        return mode;
    }

    if (!e.last24h) {
        return `${mode}・送信用プロキシの件数を読めません（送信用プロキシが止まっているかもしれません）`;
    }

    const c = e.last24h;
    const stale = e.status === 'bad' ? `・最後に集計した時刻 ${formatDate(e.updated_at)}（送信用プロキシが止まっているかもしれません）` : '';

    return `${mode}・この 24 時間 許可 ${c.allowed} 件・許可リストに無い ${c.unlisted} 件・ブロック ${c.refused} 件・メールの中継 ${c.smtp} 件${stale}`;
}

function certificatesText(c) {
    if (!c?.items) {
        return null;
    }

    return c.items.map((item) => {
        if (item.days === null) {
            return `${item.host} 読み取れません`;
        }

        return item.status === 'bad' && item.days < 0 ? `${item.host} 期限切れ` : `${item.host} あと ${item.days} 日`;
    }).join('・');
}

function diskText(d) {
    return d?.items ? d.items.map((item) => `${DISKS[item.name] ?? item.name} 空き ${item.free_percent}%（${formatBytes(item.free_bytes)}）`).join('・') : null;
}

const UPDATE_RESULTS = { ok: '更新済み', rolled_back: 'ロールバック済み', failed: '失敗', stopped: '途中で停止', manual_rollback: '手動でロールバック済み' };

function updatesText(u) {
    if (!u || u.status === 'unknown') {
        return null;
    }

    const last = u.result ? `最後 ${formatDate(u.at)} ${UPDATE_RESULTS[u.result] ?? u.result}${u.version ? `（${u.version}）` : ''}` : 'まだ更新していません';

    // 自動の更新を入れていないサーバー（開発の形は既定で入れない）は、取得元を見に行く仕組みが無いので時刻を出さない
    if (u.auto === false) {
        return `${last}・自動更新はオフです（更新は手動）`;
    }

    return u.checked_at ? `${last}・取得元を確認した時刻 ${formatDate(u.checked_at)}` : last;
}

function backupText(b) {
    if (!b || b.status === 'unknown') {
        return null;
    }

    return b.at ? `最後 ${formatDate(b.at)} ${b.result === 'failed' ? '失敗' : '成功'}` : 'まだ一度も取れていません';
}

// エラー（ADR 0043）。どこで（種類・店）・何が（例外のクラスと場所）・何回・いつから。メッセージはサーバーが持っていない
const ERROR_KINDS = { http_error: '画面や API のエラー', job_failed: 'バックグラウンド処理の失敗', command_failed: '定期の処理の失敗', event_failed: '通知の処理の失敗' };
const errors = computed(() => (data.value?.errors ?? []).map((error) => ({
    ...error,
    kindLabel: ERROR_KINDS[error.kind] ?? error.kind,
    // 名前空間を外した短い名前（App\Core\X\FooException → FooException）
    shortName: error.exception.slice(error.exception.lastIndexOf('\\') + 1),
    shopLabel: error.tenant ? (error.tenant.name ?? error.tenant.id) : null,
})));

const STATUS = { open: { tone: 'ok', label: '公開中' }, preparing: { tone: 'neutral', label: '準備中' } };
</script>

<template>
    <PageHeader title="運営ダッシュボード" :crumbs="crumbsFor('operations')" />
    <ErrorState v-if="error" :error="error" />
    <LoadingText v-else-if="data === null" />

    <template v-else>
        <!-- 困りごとを先に。無ければ 1 行だけ -->
        <AppNotice v-if="urgent.length > 0" tone="error" :live="false" data-test="ops-urgent">
            <strong>要対応 {{ urgent.length }}</strong>
            <ul class="flex flex-col gap-1">
                <li v-for="item in urgent" :key="item.key" data-test="ops-issue">
                    <TextLink v-if="item.to" :to="item.to">{{ item.text }}</TextLink>
                    <span v-else>{{ item.text }}</span>
                    <ConfirmButton v-if="item.retry" label="再送する" variant="secondary" :loading="retrying === item.retry" :disabled="retrying !== null" test="ops-event-retry" @confirm="retry(item.retry)">停止した通知をもう一度送ります</ConfirmButton>
                </li>
            </ul>
        </AppNotice>
        <AppNotice v-if="soon.length > 0" tone="warning" :live="false" data-test="ops-soon">
            <strong>近いうちに</strong>
            <ul class="flex flex-col gap-1">
                <li v-for="item in soon" :key="item.key" data-test="ops-issue">
                    <TextLink v-if="item.to" :to="item.to">{{ item.text }}</TextLink>
                    <span v-else>{{ item.text }}</span>
                    <ConfirmButton v-if="item.retry" label="再送する" variant="secondary" :loading="retrying === item.retry" :disabled="retrying !== null" test="ops-event-retry" @confirm="retry(item.retry)">停止した通知をもう一度送ります</ConfirmButton>
                    <ConfirmButton v-if="item.backup" label="バックアップを取りました" confirm-label="保管しました" variant="secondary" :loading="confirmingBackup" test="ops-app-key-backup" @confirm="confirmBackup">サーバーの外に保管しましたか</ConfirmButton>
                </li>
            </ul>
        </AppNotice>
        <AppNotice v-if="urgent.length === 0 && soon.length === 0" tone="success" :live="false" data-test="ops-no-issues">要対応の問題はありません。</AppNotice>
        <AppNotice v-if="retryResult" :tone="retryResult.tone" data-test="ops-event-retry-result">{{ retryResult.text }}</AppNotice>
        <AppNotice v-if="backupResult" :tone="backupResult.tone" data-test="ops-app-key-backup-result">{{ backupResult.text }}</AppNotice>

        <!-- 店の数・今月の売上（全部の店）・今月の注文・動いているバージョン -->
        <section aria-label="全体の数" class="grid grid-cols-2 gap-4 xl:grid-cols-4" data-test="ops-stats">
            <StatCard label="店" :value="`${data.shops.total} 店`" :note="`公開中 ${data.shops.open}・準備中 ${data.shops.preparing}`" value-test="ops-shops" />
            <StatCard label="今月の売上（全部の店）" :value="formatAmount(data.month.sales, data.currency)" note="支払いの済んだ注文。取消と返金は除く" value-test="ops-month-sales" />
            <StatCard label="今月の注文" :value="`${data.month.orders} 件`" :note="data.month.test_orders > 0 ? `テストの注文 ${data.month.test_orders} 件は含んでいません` : 'テストの注文は含みません'" value-test="ops-month-orders" />
            <StatCard label="動いているバージョン" :value="version?.short ?? '開発中'" :note="version?.date ? `${formatDate(version.date)} のコード` : 'バージョン情報の無いイメージ'" value-test="ops-version" />
        </section>

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
            <AppPanel title="売上の動き" description="日ごと・30 日（全部の店。日本の時刻で区切る）" data-test="ops-daily">
                <DailyBars :items="data.daily" :currency="data.currency" label="30 日の売上の動き" />
                <AppText size="sm" tone="muted" data-test="ops-daily-total">30 日の合計 {{ formatAmount(dailyTotal.sales, data.currency) }}・{{ dailyTotal.orders }} 件</AppText>
            </AppPanel>
            <AppPanel title="システムの状態" data-test="ops-health">
                <HealthList :items="health" />
            </AppPanel>
        </div>

        <RecoveryKitPanel @done="load" />

        <AppPanel title="エラー" description="この 7 日に起きたもの（最後に起きた順）。同じ場所で起きたものは 1 行にまとめて回数を数えます。オーナーには、初回はすぐに、その後も続いていれば 1 時間ごとにメールで通知します" data-test="ops-errors">
            <EmptyState v-if="errors.length === 0" icon="check" title="この 7 日にエラーはありません" data-test="ops-errors-empty" />
            <AppTable v-else label="この 7 日のエラー" stack>
                <thead>
                    <tr>
                        <th>内容</th>
                        <th>場所</th>
                        <th>店</th>
                        <th class="num">回数</th>
                        <th>最初</th>
                        <th>最後</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="error in errors" :key="error.id" :data-kind="error.kind" data-test="ops-error">
                        <td>
                            <CellTitle :label="error.shortName" :sub="error.kindLabel" />
                        </td>
                        <td data-label="場所"><CodeText>{{ error.location }}</CodeText></td>
                        <td data-label="店">
                            <TextLink v-if="error.tenant" :to="{ name: 'tenant', params: { tenant: error.tenant.id } }">{{ error.shopLabel }}</TextLink>
                            <span v-else>—</span>
                        </td>
                        <NumCell :value="`${error.count} 回`" strong data-label="回数" />
                        <td data-label="最初">{{ formatDate(error.first_at) }}</td>
                        <td data-label="最後">
                            {{ formatDate(error.last_at) }}
                            <!-- 店の中で起きたものは、最後に起きた時の処理の ID で関係する記録を引ける（ADR 0087） -->
                            <TextLink v-if="error.tenant && error.last_trace_id" :to="{ name: 'trace', params: { tenant: error.tenant.id, trace: error.last_trace_id } }" data-test="ops-error-trace">関係する記録</TextLink>
                        </td>
                    </tr>
                </tbody>
            </AppTable>
            <AppText v-if="data.errors_total > errors.length" size="sm" tone="soft">ほかに {{ data.errors_total - errors.length }} 件あります（最後に起きた {{ errors.length }} 件だけを並べています）。</AppText>
        </AppPanel>

        <AppPanel title="店の一覧" description="要対応のある店を先に並べます。数値は 10 分ごとに店ごとに集計したものです" data-test="ops-shops-list">
            <EmptyState v-if="data.tenants.length === 0" icon="building" title="まだ店はありません">店を作ると、ここに並びます。</EmptyState>
            <AppTable v-else label="店の一覧" stack>
                <thead>
                    <tr>
                        <th>店</th>
                        <th>状態</th>
                        <th class="num">今月の売上</th>
                        <th class="num">今月の注文</th>
                        <th class="num">要対応</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="shop in data.tenants" :key="shop.id" :data-tenant="shop.id" data-test="ops-shop">
                        <td>
                            <CellTitle :label="shop.name" :sub="shop.id" :to="{ name: 'tenant', params: { tenant: shop.id } }" />
                        </td>
                        <td data-label="状態">
                            <StatusBadge v-if="STATUS[shop.status]" :tone="STATUS[shop.status].tone">{{ STATUS[shop.status].label }}</StatusBadge>
                            <StatusBadge v-else tone="neutral">未集計</StatusBadge>
                        </td>
                        <NumCell :value="formatAmount(shop.month.sales, data.currency)" strong data-label="今月の売上" />
                        <NumCell :value="`${shop.month.orders} 件`" data-label="今月の注文" />
                        <td class="num" data-label="要対応" data-test="ops-shop-issues">
                            <StatusBadge v-if="shop.issues > 0" tone="danger">{{ shop.issues }} 件</StatusBadge>
                            <span v-else>—</span>
                        </td>
                    </tr>
                </tbody>
            </AppTable>
            <AppText v-if="data.tenants_total > data.tenants.length" size="sm" tone="soft">ほかに {{ data.tenants_total - data.tenants.length }} 店あります（要対応と売上の多い {{ data.tenants.length }} 店だけを並べています）。</AppText>
        </AppPanel>
    </template>
</template>
