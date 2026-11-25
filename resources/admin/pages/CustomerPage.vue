<script setup>
import { computed, ref, watch } from 'vue';
import { api, describe } from '../api.js';
import { customerNumber, formatAmount, formatDate, ORDER_STATUS_LABELS, ORDER_STATUS_TONES, shortId } from '../format.js';
import { useOperation } from '../operation.js';
import { crumbsFor } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { canInTenant, tenantName } from '../tenants.js';
import AppButton from '../components/ui/AppButton.vue';
import AppIcon from '../components/ui/AppIcon.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import CellTitle from '../components/ui/CellTitle.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import DescriptionList from '../components/ui/DescriptionList.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import InsetSection from '../components/ui/InsetSection.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import NumCell from '../components/ui/NumCell.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import StatCard from '../components/ui/StatCard.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';
import TextLink from '../components/ui/TextLink.vue';
import CustomerPoints from './customer/CustomerPoints.vue';

// 1 人のお客さん（ADR 0044 の 2・4）。数と注文の表は件数と金額だけで、閲覧の段階から。
// 名前と連絡先は「表示する」を押したときだけ別の API から取る（見た事実はサーバーの監査ログに残る。運用の段階から）。
// 同じお客さんかもしれない記録（名前と電話が同じでメールが違う）・まとめる・分け直すも運用の段階から。注文は書き換えず、あとで分け直せる。
// 段階での出し分けは見た目のためだけで、守るのはサーバー（足りない段階には 403）
const props = defineProps({
    tenant: { type: String, required: true },
    customer: { type: String, required: true },
});
const detail = ref(null);
const error = ref(null);
const personal = ref(null);
const personalBusy = ref(false);
const personalError = ref(null);
const similar = ref(null);
const similarError = ref(null);
// まとめる・分け直すは別々に通知する（まとめた通知は「同じお客さんかもしれない記録」の欄、分け直した通知は「まとめた記録」の欄に出す）
const merging = useOperation();
const unmerging = useOperation();

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/customers/${encodeURIComponent(props.customer)}`);
const crumbs = computed(() => crumbsFor('customer', props.tenant, tenantName(props.tenant), customerNumber(props.customer)));
const canOperate = computed(() => canInTenant(props.tenant, 'operate'));
// まとめられた記録（まとめた先がある）は、まとめる側にならない（入れ子にしない）。まとめる・同じ人かもしれないは出さない
const mergedInto = computed(() => detail.value?.merged_into_id ?? null);

watch(() => [props.tenant, props.customer], () => {
    personal.value = null;
    personalError.value = null;
    merging.message.value = null;
    merging.error.value = null;
    unmerging.message.value = null;
    unmerging.error.value = null;
    load();
}, { immediate: true });

async function load() {
    detail.value = null;
    similar.value = null;
    error.value = null;

    try {
        detail.value = (await api('GET', base.value)).customer;
    } catch (e) {
        error.value = e;

        return;
    }

    await loadSimilar();
}

async function reload() {
    detail.value = (await api('GET', base.value)).customer;
    // まとめた記録が変わると、見えていたメールの並びも変わる。もう一度押して取り直す
    personal.value = null;
    await loadSimilar();
}

async function loadSimilar() {
    similarError.value = null;

    if (!canOperate.value || mergedInto.value !== null) {
        similar.value = null;

        return;
    }

    try {
        similar.value = (await api('GET', `${base.value}/similar`)).similar;
    } catch (e) {
        similarError.value = describe(e);
    }
}

async function showPersonal() {
    personalBusy.value = true;
    personalError.value = null;

    try {
        personal.value = (await api('GET', `${base.value}/personal`)).personal;
    } catch (e) {
        personalError.value = describe(e);
    } finally {
        personalBusy.value = false;
    }
}

function merge(record) {
    return merging.run(`merge-${record.id}`, () => api('POST', `${base.value}/merge`, { merged_id: record.id }), {
        then: reload,
        done: `記録 ${customerNumber(record.id)} をこのお客さんにまとめました。下の「まとめた記録」から分け直せます。`,
    });
}

function unmerge(record) {
    return unmerging.run(`unmerge-${record.id}`, () => api('POST', `${base.value}/unmerge`, { merged_id: record.id }), {
        then: reload,
        done: `記録 ${customerNumber(record.id)} を分け直しました。元の別のお客さんに戻っています。`,
    });
}

// 住所（注文のときの形は zip、会員の届け先は postal）。知らない欄は出さない
function addressText(address) {
    if (!address) {
        return null;
    }

    const postal = address.postal ?? address.zip ?? null;
    const text = [postal ? `〒${postal}` : null, address.prefecture, address.city, address.line1, address.line2].filter(Boolean).join(' ');

    return text === '' ? null : text;
}

// 会員の届け先は、宛名と電話も届け先ごとに持つ
function savedAddressText(address) {
    const who = [address?.name, address?.phone ? `（${address.phone}）` : null].filter(Boolean).join('');

    return [who, addressText(address)].filter(Boolean).join(' ') || null;
}

const stats = computed(() => {
    const c = detail.value;

    return [
        { label: '注文の数', value: `${c.orders} 件`, note: '支払い済みの注文だけ', test: 'customer-stat-orders' },
        { label: '合計', value: formatAmount(c.sales), note: '支払い済みの注文の合計', test: 'customer-stat-sales' },
        { label: '最後の注文', value: formatDate(c.last_order_at), note: null, test: 'customer-stat-last' },
        { label: '会員になった日', value: c.member_since ? formatDate(c.member_since) : 'ゲスト', note: c.member_since ? null : '会員ではありません', test: 'customer-stat-member' },
    ];
});
const personalItems = computed(() => {
    const p = personal.value;
    const latest = p.latest_order;

    return [
        { label: 'メール', value: p.email },
        { label: '名前（最後の注文）', value: latest?.name },
        { label: '電話（最後の注文）', value: latest?.phone },
        { label: '住所（最後の注文）', value: addressText(latest?.address) },
    ];
});
</script>

<template>
    <PageHeader :help="helpLink('customer', tenant)" :title="`お客さん ${customerNumber(customer)}`" :crumbs="crumbs">
        <template v-if="detail" #actions>
            <StatusBadge :tone="detail.member ? 'accent' : 'neutral'" data-test="customer-member">{{ detail.member ? '会員' : 'ゲスト' }}</StatusBadge>
            <!-- 会員ランク（App「会員ランク」を使う店だけ。ADR 0079） -->
            <StatusBadge v-if="detail.badge" tone="accent" data-test="customer-badge">{{ detail.badge }}</StatusBadge>
            <StatusBadge v-if="detail.disabled" tone="warn">停止中</StatusBadge>
        </template>
    </PageHeader>
    <ErrorState v-if="error" :error="error" />
    <LoadingText v-else-if="detail === null" />

    <template v-else>
        <AppNotice v-if="mergedInto" tone="info" :live="false" data-test="merged-into">
            この記録は、お客さん {{ customerNumber(mergedInto) }} にまとめられています。注文の数と一覧は、まとめた先の画面でいっしょに見られます。
            <TextLink :to="{ name: 'customer', params: { tenant, customer: mergedInto } }" data-test="merged-into-link">まとめた先を開く</TextLink>
        </AppNotice>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <StatCard v-for="stat in stats" :key="stat.label" :label="stat.label" :value="stat.value" :note="stat.note" :value-test="stat.test" />
        </div>

        <AppPanel title="注文" description="このお客さんの注文です（まとめた記録の注文も含みます）。未払いや取り消した注文も並びます。新しい順に 100 件まで。" data-test="customer-orders">
            <EmptyState v-if="detail.transactions.length === 0" icon="receipt" title="まだ注文はありません" />
            <AppTable v-else label="このお客さんの注文" stack>
                <thead>
                    <tr><th>注文</th><th>状態</th><th class="num">合計</th></tr>
                </thead>
                <tbody>
                    <tr v-for="order in detail.transactions" :key="order.id" data-test="customer-order-row" :data-from-merged="order.from_merged ? 'true' : 'false'">
                        <td>
                            <CellTitle
                                :label="shortId(order.id)"
                                :sub="`${formatDate(order.created_at)}${order.from_merged ? '・まとめた記録の注文' : ''}`"
                                :to="{ name: 'transaction', params: { tenant, transaction: order.id } }"
                            />
                        </td>
                        <td data-label="状態">
                            <StatusBadge :tone="ORDER_STATUS_TONES[order.order_status]">{{ ORDER_STATUS_LABELS[order.order_status] ?? order.order_status }}</StatusBadge>
                        </td>
                        <NumCell :value="formatAmount(order.total_amount, order.currency)" strong data-label="合計" />
                    </tr>
                </tbody>
            </AppTable>
        </AppPanel>

        <!-- ポイント（ADR 0068）。まとめられた記録は、まとめた先の画面で -->
        <CustomerPoints v-if="!mergedInto" :tenant="tenant" :customer="customer" />

        <AppPanel title="名前と連絡先" description="メールアドレス・最後の注文の名前と電話と住所・会員として登録したお届け先です。表示すると、誰がいつ見たかが記録に残ります。" data-test="customer-personal">
            <template #actions>
                <AppText v-if="!canOperate" tone="soft" data-test="personal-no-permission">名前と連絡先を表示できるのは、「運用」以上の権限のある人だけです。</AppText>
                <AppButton v-else-if="personal === null" :disabled="personalBusy" :loading="personalBusy" data-test="show-personal" @click="showPersonal">
                    <AppIcon name="key" />名前と連絡先を表示する
                </AppButton>
                <AppButton v-else variant="ghost" data-test="hide-personal" @click="personal = null">隠す</AppButton>
            </template>
            <AppNotice v-if="personalError" tone="error">{{ personalError }}</AppNotice>
            <template v-if="personal">
                <DescriptionList :items="personalItems" data-test="personal-details" />
                <AppText v-if="personal.latest_order" tone="soft" size="xs">
                    名前・電話・住所は、最後の注文（<TextLink :to="{ name: 'transaction', params: { tenant, transaction: personal.latest_order.transaction_id } }">{{ shortId(personal.latest_order.transaction_id) }}</TextLink>）のときのものです。
                </AppText>
                <InsetSection v-if="personal.merged_emails.length > 0" title="まとめた記録のメール" data-test="personal-merged-emails">
                    <DescriptionList :items="personal.merged_emails.map((entry) => ({ label: `記録 ${customerNumber(entry.id)}`, value: entry.email }))" />
                </InsetSection>
                <InsetSection v-if="personal.addresses.length > 0" title="会員のお届け先" description="会員がマイページで登録したお届け先です。" data-test="personal-addresses">
                    <DescriptionList :items="personal.addresses.map((entry, index) => ({ label: entry.default ? 'いつものお届け先' : `お届け先 ${index + 1}`, value: savedAddressText(entry.address) }))" />
                </InsetSection>
            </template>
        </AppPanel>

        <AppPanel
            v-if="canOperate && !mergedInto"
            title="同じお客さんかもしれない記録"
            description="名前と電話番号が同じで、メールアドレスが違う記録です。同じお客さんなら、このお客さんにまとめられます（自動ではまとめません）。"
            data-test="customer-similar"
        >
            <AppNotice v-if="merging.message.value" tone="success" data-test="merge-done">{{ merging.message.value }}</AppNotice>
            <AppNotice v-if="merging.error.value" tone="error" data-test="merge-error">{{ merging.error.value }}</AppNotice>
            <AppNotice v-if="similarError" tone="error">{{ similarError }}</AppNotice>
            <LoadingText v-else-if="similar === null" />
            <AppText v-else-if="similar.length === 0" tone="soft" data-test="similar-none">名前と電話番号が同じで、メールアドレスが違う記録はありません。</AppText>
            <AppTable v-else label="同じお客さんかもしれない記録" stack>
                <thead>
                    <tr><th>記録</th><th>会員</th><th class="num">注文の数</th><th class="num">合計</th><th>最後の注文</th><th><span class="sr-only">操作</span></th></tr>
                </thead>
                <tbody>
                    <tr v-for="record in similar" :key="record.id" data-test="similar-row" :data-customer="record.id">
                        <td>
                            <CellTitle :label="customerNumber(record.id)" :sub="`初回 ${formatDate(record.created_at)}`" :to="{ name: 'customer', params: { tenant, customer: record.id } }" />
                        </td>
                        <td data-label="会員"><StatusBadge :tone="record.member ? 'accent' : 'neutral'">{{ record.member ? '会員' : 'ゲスト' }}</StatusBadge></td>
                        <NumCell :value="record.orders" data-label="注文の数" />
                        <NumCell :value="formatAmount(record.sales)" strong data-label="合計" />
                        <td data-label="最後の注文"><AppText as="span" tone="muted" nowrap>{{ formatDate(record.last_order_at) }}</AppText></td>
                        <td>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <ConfirmButton
                                    label="この記録をまとめる"
                                    confirm-label="まとめる"
                                    variant="secondary"
                                    :disabled="merging.pending.value !== null"
                                    :loading="merging.pending.value === `merge-${record.id}`"
                                    test="merge"
                                    @confirm="merge(record)"
                                >
                                    記録 {{ customerNumber(record.id) }} の注文を、このお客さんの注文の数と一覧に加えます。注文そのものは書き換えず、あとで「分け直す」で元に戻せます。
                                </ConfirmButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </AppTable>
        </AppPanel>

        <AppPanel
            v-if="detail.merged.length > 0 || unmerging.message.value"
            title="まとめた記録"
            description="このお客さんにまとめた、メールアドレスの違う記録です。注文は元の記録のままで、注文の数と一覧にだけいっしょに表示しています。"
            data-test="customer-merged"
        >
            <AppNotice v-if="unmerging.message.value" tone="success" data-test="unmerge-done">{{ unmerging.message.value }}</AppNotice>
            <AppNotice v-if="unmerging.error.value" tone="error" data-test="unmerge-error">{{ unmerging.error.value }}</AppNotice>
            <AppTable v-if="detail.merged.length > 0" label="まとめた記録" stack>
                <thead>
                    <tr><th>記録</th><th class="num">注文</th><th><span class="sr-only">操作</span></th></tr>
                </thead>
                <tbody>
                    <tr v-for="record in detail.merged" :key="record.id" data-test="merged-row" :data-customer="record.id">
                        <td>
                            <CellTitle :label="customerNumber(record.id)" :sub="`まとめた日 ${formatDate(record.merged_at)}`" :to="{ name: 'customer', params: { tenant, customer: record.id } }" />
                        </td>
                        <NumCell :value="record.orders" data-label="注文" />
                        <td>
                            <div v-if="canOperate" class="flex flex-wrap items-center justify-end gap-2">
                                <ConfirmButton
                                    label="分け直す"
                                    variant="secondary"
                                    :disabled="unmerging.pending.value !== null"
                                    :loading="unmerging.pending.value === `unmerge-${record.id}`"
                                    test="unmerge"
                                    @confirm="unmerge(record)"
                                >
                                    記録 {{ customerNumber(record.id) }} を、元の別のお客さんに戻します。注文はそのままです。
                                </ConfirmButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </AppTable>
        </AppPanel>
    </template>
</template>
