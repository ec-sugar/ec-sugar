<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../api.js';
import { formatAmount, formatDate, ORDER_STATUS_LABELS, ORDER_STATUS_TONES, shortId } from '../format.js';
import { crumbsFor } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { SETUP_STEPS, stepNote, welcomeLater, welcomeSlides } from '../setup.js';
import { useSampleRemoval } from '../samples.js';
import { rememberTenant, tenantName } from '../tenants.js';
import { session } from '../session.js';
import RecoveryPendingNotice from '../components/RecoveryPendingNotice.vue';
import UnusualSessionNotice from '../components/UnusualSessionNotice.vue';
import SampleRemovalNotices from '../components/SampleRemovalNotices.vue';
import SampleRemoveButton from '../components/SampleRemoveButton.vue';
import SinglePasskeyNotice from '../components/SinglePasskeyNotice.vue';
import AppButton from '../components/ui/AppButton.vue';
import AppIcon from '../components/ui/AppIcon.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppReveal from '../components/ui/AppReveal.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import CellTitle from '../components/ui/CellTitle.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import NumCell from '../components/ui/NumCell.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import ProgressSegments from '../components/ui/ProgressSegments.vue';
import SetupSteps from '../components/ui/SetupSteps.vue';
import StatCard from '../components/ui/StatCard.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';
import SubSteps from '../components/ui/SubSteps.vue';
import TextLink from '../components/ui/TextLink.vue';
import TodoList from '../components/ui/TodoList.vue';
import WelcomeCard from '../components/ui/WelcomeCard.vue';

// 店のホーム。形は店の様子で変わる:
//   準備中の店: 開設の手順が主役（番号・済の印・進み具合。サンプルがあれば「最初の商品」の中にサンプルの手順）。注文があれば数とやることも
//   公開した店: 数（今日の注文・今月の売上・平均注文単価）・やること・最近の注文が主役で、開設の手順は下に畳む（開いて見られる）
// 最初の 1 回だけ「ようこそ」を上に出す（運営ごと・店ごと。閉じた印はサーバーに置く）。済んだかも数もサーバーが決め、ここは並べるだけ
const props = defineProps({ tenant: { type: String, required: true } });
const overview = ref(null);
const setup = ref(null);
const error = ref(null);
const welcomeOpen = ref(false);
// 公開した店の開設の手順を開いているか（準備中の店はいつも開いている）
const setupOpen = ref(false);

// サンプルをまとめて消す（オーナーだけ。商品の一覧の帯と同じ流れ。消したら読み直す）
const sampleRemoval = useSampleRemoval(() => props.tenant, { after: () => load() });

const name = computed(() => overview.value?.tenant.name ?? tenantName(props.tenant));
const isOwner = computed(() => session.operator?.role === 'owner');
// 動きに問題があることの帯（ADR 0043）。オーナーにだけ（直すのはオーナーの仕事）。数は中央の軽い API の要対応の件数で、
// 店の API からは取らない（店の文脈の中で中央の表を読まない）。取れなくても画面は止めず、帯を出さないだけ
const alerts = ref(0);
const opened = computed(() => setup.value?.opened === true);
const remaining = computed(() => setup.value?.samples.remaining ?? null);
// 残っているサンプルの中身（数のある物だけを並べる）
const sampleParts = computed(() => {
    const samples = remaining.value;

    if (!samples) {
        return [];
    }

    return [
        samples.listings > 0 ? `商品 ${samples.listings} 点` : null,
        samples.categories > 0 ? `カテゴリ ${samples.categories} 個` : null,
        samples.photos > 0 ? `ショップの写真 ${samples.photos} 枚` : null,
        samples.content ? 'テーマの内容' : null,
        samples.legal ? '特商法の表記のサンプル' : null,
    ].filter(Boolean);
});

const steps = computed(() => (setup.value?.steps ?? []).map((step) => ({
    ...step,
    ...SETUP_STEPS[step.key],
    note: stepNote(step, setup.value),
    test: `setup-step-${step.key}`,
})));
// 次にやる手順（まだの手順のいちばん上）。ボタンを主な操作の色にする（「ようこそ」を出している間は、そちらが主な操作）
const nextStep = computed(() => steps.value.find((step) => !step.done)?.key ?? null);
const listingDone = computed(() => steps.value.some((step) => step.key === 'listing' && step.done));
// サンプルの手順。サンプルを入れたことのある店だけ。「見る」は先へ進んだら済とみなす
const sampleSteps = computed(() => [
    { key: 'view', label: 'サンプルを見る（ショップと商品の一覧で、商品がどう表示されるかを確認する）', done: listingDone.value || remaining.value === null, test: 'sample-step-view' },
    { key: 'replace', label: '自分の商品に置き換える（登録するか、サンプルを書き換える）', done: listingDone.value, test: 'sample-step-replace' },
    { key: 'remove', label: 'サンプルをまとめて削除', done: remaining.value === null, test: 'sample-step-remove' },
]);

const stats = computed(() => overview.value?.stats ?? null);
const recent = computed(() => overview.value?.recent ?? []);
// 数は公開した店か、注文のある店だけ（空の 0 を並べない）
const showStats = computed(() => opened.value || recent.value.length > 0);
// App の色の種類（CellType の tone）を、やることの点の色へ
const APP_TONES = { bad: 'danger', warn: 'warn', good: 'accent', muted: 'neutral' };
const todoItems = computed(() => {
    const todo = overview.value?.todo;

    if (!todo) {
        return [];
    }

    return [
        { key: 'to-ship', label: '発送を待つ注文', description: '入金済みの注文です。発送したら、注文の画面で発送を登録します。', count: todo.to_ship, unit: '件', tone: 'accent', to: { name: 'transactions', params: { tenant: props.tenant }, query: { status: 'paid' } }, test: 'todo-to-ship' },
        // 銀行振込・代金引換（ADR 0074）
        { key: 'to-ship-cod', label: '発送を待つ代引きの注文', description: '代金引換の注文です。代金はお届けの時に運送会社が受け取ります。', count: todo.to_ship_cod ?? 0, unit: '件', tone: 'accent', to: { name: 'transactions', params: { tenant: props.tenant }, query: { status: 'cod_pending' } }, test: 'todo-to-ship-cod' },
        { key: 'bank-awaiting', label: '入金待ちの注文（振込）', description: '銀行振込の注文です。振込が届いたら、注文の画面で「入金を確認した」を押します（確認するまで発送できません）。', count: todo.bank_awaiting ?? 0, unit: '件', tone: 'accent', to: { name: 'transactions', params: { tenant: props.tenant }, query: { payment: 'bank_awaiting' } }, test: 'todo-bank-awaiting' },
        { key: 'bank-due-soon', label: '期限の近い入金待ち（振込）', description: 'お振込みの期限まで 2 日を切った銀行振込の注文です。振込が届いていれば、注文の画面で「入金を確認した」を押します。', count: todo.bank_due_soon ?? 0, unit: '件', tone: 'warn', to: { name: 'transactions', params: { tenant: props.tenant }, query: { payment: 'bank_awaiting' } }, test: 'todo-bank-due-soon' },
        { key: 'cod-to-confirm', label: '入金の確認を待つ代引き', description: '発送した代引きの注文です。運送会社から代金が届いたら、注文の画面で「代引きの入金を確認した」を押します。', count: todo.cod_to_confirm ?? 0, unit: '件', tone: 'warn', to: { name: 'transactions', params: { tenant: props.tenant }, query: { payment: 'cod_unpaid' } }, test: 'todo-cod-to-confirm' },
        { key: 'to-ship-free', label: '発送を待つ注文（0 円）', description: 'お支払いが不要な、受付済みの注文です。', count: todo.to_ship_free, unit: '件', tone: 'accent', to: { name: 'transactions', params: { tenant: props.tenant }, query: { status: 'confirmed' } }, test: 'todo-to-ship-free' },
        // ギフトカード（ADR 0076）: 売った注文が返金されて止めたカード（残高を 0 にするか、確認して再開する）と、贈り物の送料の差額を返しきれなかった注文
        { key: 'gift-cards-stopped', label: '返金で停止したギフトカード', description: 'ギフトカードを販売した注文が返金されたため、自動で停止しました。残高を 0 にするか、確認してから再開します。', count: todo.gift_cards_stopped ?? 0, unit: '枚', tone: 'warn', to: { name: 'settings-gift-cards', params: { tenant: props.tenant } }, test: 'todo-gift-cards-stopped' },
        { key: 'shipping-unreturned', label: '返金しきれなかった送料の差額', description: '贈り物の送料の差額を、現金・ポイント・ギフトカードで返しきれませんでした（直近 30 日）。お客さんに直接お返しください。', count: todo.shipping_unreturned ?? 0, unit: '件', tone: 'warn', to: { name: 'transactions', params: { tenant: props.tenant } }, test: 'todo-shipping-unreturned' },
        { key: 'agent-proposals', label: 'AI エージェントの承認待ちの提案', description: 'AI エージェントが提案した変更です。内容を見て、承認か却下を押します（7 日で期限切れ）。', count: todo.agent_proposals ?? 0, unit: '件', tone: 'warn', to: { name: 'agent-proposals', params: { tenant: props.tenant } }, test: 'todo-agent-proposals' },
        { key: 'out-of-stock', label: '在庫切れの商品', description: '公開中で、在庫が 0 の商品です。在庫を増やすか、非公開にします。', count: todo.out_of_stock, unit: '点', tone: 'warn', to: { name: 'listings', params: { tenant: props.tenant }, query: { status: 'active', sort: 'stock' } }, test: 'todo-out-of-stock' },
        // App のやること（ADR 0051。定期便の「止まった定期便」など）。押すと App の画面の一覧の、その絞り込みへ
        ...(overview.value?.app_todo ?? []).map((item) => ({
            key: `app-${item.plugin}-${item.filter}`,
            label: item.label,
            description: item.description,
            count: item.count,
            unit: '件',
            tone: APP_TONES[item.tone] ?? 'neutral',
            to: { name: 'app-page', params: { tenant: props.tenant, plugin: item.plugin }, query: { filter: item.filter } },
            test: `todo-app-${item.plugin}-${item.filter}`,
        })),
    ].filter((item) => item.count > 0);
});
const showTodo = computed(() => opened.value || todoItems.value.length > 0);
// 主な欄の並び。準備中は開設の手順が先、公開した店は数・やること・最近の注文が先で手順は下
const parts = computed(() => [
    ...(opened.value ? [] : ['setup']),
    ...(showStats.value ? ['stats'] : []),
    ...(showTodo.value || showStats.value ? ['lists'] : []),
    ...(opened.value ? ['setup'] : []),
]);
const slides = computed(() => (setup.value ? welcomeSlides(setup.value) : []));

// テナント ID は経路の形（router.js）で絞ってあり、さらに URL に入れる前に符号化する
function path(suffix) {
    return `/api/tenants/${encodeURIComponent(props.tenant)}/${suffix}`;
}

async function load() {
    const [nextOverview, nextSetup] = await Promise.all([api('GET', path('overview')), api('GET', path('setup'))]);
    overview.value = nextOverview;
    setup.value = nextSetup;
}

watch(() => props.tenant, async () => {
    overview.value = null;
    setup.value = null;
    sampleRemoval.reset();
    error.value = null;
    welcomeOpen.value = false;
    setupOpen.value = false;

    try {
        await load();
        rememberTenant(overview.value.tenant.id, overview.value.tenant.name, overview.value.tenant.storefront_url, overview.value.tenant.level);
        welcomeOpen.value = !setup.value.welcome.seen && !welcomeLater.has(props.tenant);
        // 公開した店でもサンプルが残っていれば、消す操作が見えるように開いておく
        setupOpen.value = setup.value.opened && setup.value.samples.remaining !== null;
    } catch (e) {
        error.value = e;
    }
}, { immediate: true });

async function loadAlerts() {
    if (!isOwner.value) {
        alerts.value = 0;

        return;
    }

    try {
        alerts.value = (await api('GET', '/api/operations/alerts')).urgent ?? 0;
    } catch {
        alerts.value = 0;
    }
}

watch([() => props.tenant, isOwner], loadAlerts, { immediate: true });

// 「ようこそ」を閉じる（もう出さない）。印を置けなくても、この画面では閉じる（次に開いた時にもう一度出るだけ）
function closeWelcome() {
    welcomeOpen.value = false;
    welcomeLater.delete(props.tenant);
    api('PUT', path('welcome')).catch(() => {});
}

// あとで見る（印は置かない。この画面を開いている間は出さない）
function laterWelcome() {
    welcomeOpen.value = false;
    welcomeLater.add(props.tenant);
}

</script>

<template>
    <PageHeader :help="helpLink('tenant', tenant)" :title="name" :crumbs="crumbsFor('tenant', tenant, name)">
        <template #title><span data-test="tenant-name">{{ name }}</span></template>
        <template #actions>
            <AppButton v-if="overview?.tenant.storefront_url" :href="overview.tenant.storefront_url" data-test="open-storefront-header">
                <AppIcon name="external" />ショップを開く
            </AppButton>
            <AppButton :to="{ name: 'listings', params: { tenant } }" data-test="open-listings">
                <AppIcon name="box" />商品を開く
            </AppButton>
        </template>
    </PageHeader>
    <!-- 動きに問題がある（運営ダッシュボードの要対応が 1 件以上）。オーナーにだけ。-->
    <AppNotice v-if="isOwner && alerts > 0" tone="error" :live="false" data-test="ops-alert-banner">
        <span>システムに問題があります（要対応 {{ alerts }} 件）。<TextLink :to="{ name: 'operations' }" data-test="ops-alert-link">運営ダッシュボードを見る</TextLink></span>
    </AppNotice>
    <!-- 見られる店が 1 つなら、ここがホーム。パスキーが 1 本だけの運営に通知する -->
    <RecoveryPendingNotice />
    <UnusualSessionNotice />
    <SinglePasskeyNotice
        v-if="session.passkeys?.count === 1"
        :owner="session.operator?.role === 'owner'"
        :synced="session.passkeys.synced"
        link
        data-test="single-passkey"
    />
    <ErrorState v-if="error" :error="error" />
    <LoadingText v-else-if="overview === null || setup === null" />

    <template v-else>
        <!-- 最初の 1 回の「ようこそ」（運営ごと・店ごと） -->
        <WelcomeCard v-if="welcomeOpen" :slides="slides" @close="closeWelcome" @later="laterWelcome" />

        <!-- 返金が要る支払い（二重に払われた・取り消した注文に届いた）があれば、いちばん上で通知する -->
        <AppNotice v-if="overview.stray_payments?.length > 0" tone="warning" data-test="stray-notice">
            <span>返金が必要な支払いが {{ overview.stray_payments.length }} 件あります。注文の画面から返金してください。</span>
            <ul class="flex flex-col gap-1">
                <li v-for="stray in overview.stray_payments" :key="stray.id">
                    <TextLink :to="{ name: 'transaction', params: { tenant, transaction: stray.transaction_id } }">注文 {{ shortId(stray.transaction_id) }}（{{ formatAmount(stray.amount, 'JPY') }}）</TextLink>
                </li>
            </ul>
        </AppNotice>

        <!-- 要対応の返金（返金が後から失敗した・戻された・長く途中のまま。ADR 0027） -->
        <AppNotice v-if="overview.refund_issues?.length > 0" tone="error" data-test="refund-issues">
            <span>確認が必要な返金が {{ overview.refund_issues.length }} 件あります。お客さんに返金されていない可能性があります。注文の画面の「返金の記録」と、Stripe のダッシュボードで確認してください。</span>
            <ul class="flex flex-col gap-1">
                <li v-for="refund in overview.refund_issues" :key="refund.id">
                    <TextLink :to="{ name: 'transaction', params: { tenant, transaction: refund.transaction_id } }">注文 {{ shortId(refund.transaction_id) }}（{{ formatAmount(refund.amount, 'JPY') }}・{{ refund.failure_reason === 'external_refund' ? 'Stripe の画面で返金された' : ({ failed: '返金に失敗', reversed: '返金後に取り消された', held: '金額が違うため保留', requested: '処理が途中のまま', pending: '手続きに時間がかかっている' }[refund.status] ?? refund.status) }}）</TextLink>
                </li>
            </ul>
        </AppNotice>

        <!-- 並びは店の様子で替える（parts）。見た目の順と読み上げ・キーボードの順をそろえるため、DOM の順そのものを替える -->
        <template v-for="part in parts" :key="part">
            <!-- 数（今日の注文・今月の売上・平均注文単価。テストの注文は数えない） -->
            <section v-if="part === 'stats'" aria-label="今日と今月の数" class="flex flex-col gap-2" data-test="home-stats">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <StatCard label="今日の注文" :value="`${stats.today.orders} 件`" :note="formatAmount(stats.today.sales, stats.currency)" value-test="stat-today-orders" />
                    <StatCard label="今月の売上" :value="formatAmount(stats.month.sales, stats.currency)" :note="`${stats.month.orders} 件`" value-test="stat-month-sales" />
                    <StatCard label="平均注文単価（今月）" :value="formatAmount(stats.month.average, stats.currency)" value-test="stat-month-average" />
                </div>
                <AppText v-if="stats.test_orders > 0" size="sm" tone="soft" data-test="stat-test-orders">今月のテストの注文 {{ stats.test_orders }} 件は含んでいません（Stripe のテストモードで支払われた注文）。</AppText>
            </section>

            <div v-else-if="part === 'lists'" :class="['grid grid-cols-1 gap-6', showTodo && showStats ? 'lg:grid-cols-2' : '']">
                <AppPanel v-if="showTodo" title="やること" data-test="home-todo">
                    <TodoList :items="todoItems">
                        <template #empty>今やることはありません。</template>
                    </TodoList>
                </AppPanel>

                <AppPanel v-if="showStats" title="最近の注文" data-test="home-recent">
                    <template v-if="recent.length > 0" #actions>
                        <AppButton size="sm" variant="ghost" :to="{ name: 'transactions', params: { tenant } }" data-test="home-all-orders">すべての注文<AppIcon name="chevron-right" /></AppButton>
                    </template>
                    <EmptyState v-if="recent.length === 0" icon="receipt" title="まだ注文はありません">ショップで注文が入ると、ここに並びます。</EmptyState>
                    <AppTable v-else label="最近の注文" stack>
                        <thead>
                            <tr>
                                <th>注文</th>
                                <th>状態</th>
                                <th class="num">合計</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="order in recent" :key="order.id" data-test="recent-order">
                                <td>
                                    <CellTitle :label="shortId(order.id)" :sub="`${formatDate(order.created_at)}・明細 ${order.lines_count}`" :to="{ name: 'transaction', params: { tenant, transaction: order.id } }" />
                                </td>
                                <td data-label="状態">
                                    <span class="flex flex-wrap gap-1.5">
                                        <StatusBadge :tone="ORDER_STATUS_TONES[order.order_status]">{{ ORDER_STATUS_LABELS[order.order_status] ?? order.order_status }}</StatusBadge>
                                        <StatusBadge v-if="order.test" tone="neutral" data-test="recent-test">テスト</StatusBadge>
                                    </span>
                                </td>
                                <NumCell :value="formatAmount(order.total_amount, order.currency)" strong data-label="合計" />
                            </tr>
                        </tbody>
                    </AppTable>
                </AppPanel>
            </div>

            <!-- 開設の手順。準備中の店では主役（数より上）、公開した店では畳んで下に -->
            <AppPanel
                v-else-if="part === 'setup'"
                title="開設の手順"
                :description="opened ? 'ショップは公開しています。残りの手順もここから進められます。' : '上から順に進めると、ショップを公開できます。終わった手順には自動でチェックが付きます。'"
                data-test="setup"
            >
                <template #actions>
                    <AppButton v-if="!welcomeOpen" size="sm" variant="ghost" data-test="welcome-reopen" @click="welcomeOpen = true">最初の説明を見る</AppButton>
                    <AppButton v-if="opened" size="sm" :aria-expanded="setupOpen ? 'true' : 'false'" data-test="setup-toggle" @click="setupOpen = !setupOpen">
                        <AppIcon :name="setupOpen ? 'chevron-up' : 'chevron-down'" />{{ setupOpen ? '閉じる' : '開く' }}
                    </AppButton>
                </template>
                <ProgressSegments :done="setup.done" :total="setup.total" label="開設の手順の進み具合" />
                <AppReveal :show="!opened || setupOpen" class="flex flex-col gap-4">
                    <SetupSteps :steps="steps" label="開設の手順">
                        <template #action="{ step }">
                            <AppButton
                                size="sm"
                                :variant="step.done ? 'ghost' : (step.key === nextStep && !welcomeOpen ? 'primary' : 'secondary')"
                                :to="{ name: step.route, params: { tenant } }"
                                :data-test="`setup-go-${step.key}`"
                            >
                                {{ step.done ? '開く' : step.action }}<AppIcon name="chevron-right" />
                            </AppButton>
                        </template>
                        <template #detail="{ step }">
                            <!-- 入れたときのサンプル。自分の商品に置き換えたら、まとめて消す -->
                            <div v-if="step.key === 'listing' && setup.samples.installed" class="flex flex-col gap-2" data-test="samples">
                                <AppText v-if="remaining" size="sm" tone="muted" data-test="sample-parts">雑貨のお店のサンプル（{{ sampleParts.join('・') }}）が入っています。</AppText>
                                <SubSteps :items="sampleSteps" label="サンプルの手順">
                                    <template #action="{ item }">
                                        <template v-if="item.key === 'view' && remaining">
                                            <AppButton v-if="overview.tenant.storefront_url" size="sm" variant="ghost" :href="overview.tenant.storefront_url" data-test="sample-open-storefront"><AppIcon name="external" />ショップで見る</AppButton>
                                            <AppButton size="sm" variant="ghost" :to="{ name: 'listings', params: { tenant } }" data-test="sample-open-listings">商品の一覧</AppButton>
                                        </template>
                                        <template v-else-if="item.key === 'remove' && remaining">
                                            <SampleRemoveButton v-if="isOwner" :removal="sampleRemoval" />
                                            <AppText v-else as="span" size="sm" tone="soft">削除できるのはオーナーだけです。</AppText>
                                        </template>
                                    </template>
                                </SubSteps>
                                <AppText v-if="remaining" size="sm" tone="soft">削除されるのはサンプルとして入れたものだけです。注文に使われた商品と、編集したものは残ります。送料はそのまま残るので、設定の「配送と送料」で確認してください。</AppText>
                                <SampleRemovalNotices :removal="sampleRemoval" />
                            </div>
                        </template>
                    </SetupSteps>
                </AppReveal>
            </AppPanel>
        </template>
    </template>
</template>
