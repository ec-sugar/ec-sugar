<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../api.js';
import { formatAmount, formatDate, ORDER_STATUS_LABELS, ORDER_STATUS_TONES, REFUND_STATE_LABELS, REFUND_STATE_TONES, shortId, TRANSACTION_STATUS_LABELS, TRANSACTION_STATUS_TONES } from '../format.js';
import { useListQuery, useSearchShortcut } from '../listQuery.js';
import { crumbsFor, titleOf } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { canInTenant, storefrontUrl, tenantName } from '../tenants.js';
import AppButton from '../components/ui/AppButton.vue';
import AppCheckbox from '../components/ui/AppCheckbox.vue';
import AppIcon from '../components/ui/AppIcon.vue';
import AppPager from '../components/ui/AppPager.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import CellTitle from '../components/ui/CellTitle.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import FormActions from '../components/ui/FormActions.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import FilterSelect from '../components/ui/FilterSelect.vue';
import ListFilters from '../components/ui/ListFilters.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import NumCell from '../components/ui/NumCell.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import SearchField from '../components/ui/SearchField.vue';
import SortHeader from '../components/ui/SortHeader.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';

// 注文の一覧（一覧の型。コードと API の名前は Transaction のまま）。個人情報は出さない（お客さんは「いるかどうか」だけ）。
// 上に絞り込みの箱（注文番号・商品名で探す・対応の状況で絞る・並べ替え・件数）、表は狭い画面で札の並びに。条件は URL に持つ（listQuery.js）。
// まだ 1 件も無ければ「ショップを開く」、絞り込んで 0 件なら「条件を外す」
const PER_PAGE = 50;
// 並べ替えの候補（サーバーの ListTransactionsRequest::SORTS と同じ名前）
const SORT_OPTIONS = [
    { value: '-created_at', label: '新しい順' },
    { value: 'created_at', label: '古い順' },
    { value: '-total_amount', label: '合計の高い順' },
    { value: 'total_amount', label: '合計の安い順' },
];
const props = defineProps({ tenant: { type: String, required: true } });
// URL の status は物販の対応の状況（入金待ち・入金済み…）。API には order_status で渡す
const { route, q, page, status, sort, filtered: listFiltered, navigate, submitSearch, go } = useListQuery({
    statuses: Object.keys(ORDER_STATUS_LABELS),
    sorts: ['created_at', 'total_amount'],
    defaultSort: '-created_at',
});
// お支払いで絞る（銀行振込の入金待ち・代引きで入金を確認していない。ADR 0074。サーバーの TransactionController::PAYMENT_FILTERS と同じ名前）
const PAYMENT_FILTERS = { bank_awaiting: '入金待ち（振込）', cod_unpaid: '代引き（未入金）' };
const payment = computed({
    get: () => (Object.hasOwn(PAYMENT_FILTERS, String(route.query.payment ?? '')) ? String(route.query.payment) : ''),
    set: (value) => navigate({ payment: value || undefined, page: undefined }),
});
const paymentCounts = ref(null);
const filtered = computed(() => listFiltered.value || payment.value !== '');
// 条件を外す（言葉・状態・お支払い・ページ。並べ替えは残す）
function clear() {
    q.value = '';
    navigate({ q: undefined, status: undefined, payment: undefined, page: undefined });
}
const paymentLabel = (value) => `${PAYMENT_FILTERS[value]}（${paymentCounts.value?.[value] ?? 0}）`;
const transactions = ref(null);
const meta = ref(null);
const counts = ref(null);
const error = ref(null);
const search = ref(null);

const lastPage = computed(() => (meta.value ? Math.max(1, Math.ceil(meta.value.total / meta.value.per_page)) : 1));
const crumbs = computed(() => crumbsFor('transactions', props.tenant, tenantName(props.tenant)));
const shop = computed(() => storefrontUrl(props.tenant));
// まだ 1 件も無い（絞り込んでいない・どの状況も 0）。表の代わりに、ショップを開く案内を出す
const noneYet = computed(() => counts.value !== null && counts.value.all === 0 && !filtered.value);
const statusLabel = (value) => `${ORDER_STATUS_LABELS[value]}（${counts.value?.[value] ?? 0}）`;

// 条件を続けて変えたとき、先に送った古い問い合わせの答えが後から届いても使わない（後の答えを上書きしない）
watch(() => [props.tenant, route.query], async (_now, _before, onCleanup) => {
    let stale = false;
    onCleanup(() => { stale = true; });
    error.value = null;
    const params = new URLSearchParams({ page: String(page.value), per_page: String(PER_PAGE), sort: sort.value });

    if (status.value) {
        params.set('order_status', status.value);
    }

    if (route.query.q) {
        params.set('q', String(route.query.q));
    }

    if (payment.value) {
        params.set('payment', payment.value);
    }

    try {
        const result = await api('GET', `/api/tenants/${encodeURIComponent(props.tenant)}/transactions?${params}`);

        if (stale) {
            return;
        }

        transactions.value = result.transactions;
        meta.value = result.meta;
        // 件数は物販ではない取引も数える「すべて」と、物販の対応の状況ごと
        counts.value = { ...result.order_counts, all: result.counts.all };
        paymentCounts.value = result.payment_counts ?? null;
    } catch (e) {
        if (!stale) {
            error.value = e;
        }
    }
}, { immediate: true, deep: true });

useSearchShortcut(search);

// App の注文の書類のうち、まとめて出せるもの（納品書など）。注文を選んで 1 つのページに続けて開く（新しいタブ）。
// 届け先や名前が載るので、運用の段階の人にだけ出す（守るのはサーバー）。選べるのは物販の注文だけ、このページの中だけ
const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}`);
const documents = ref([]);
const selected = ref([]);
const bulkDocuments = computed(() => (canInTenant(props.tenant, 'operate') ? documents.value.filter((doc) => doc.bulk) : []));
const selectable = computed(() => (transactions.value ?? []).filter((transaction) => transaction.order_status).map((transaction) => transaction.id));
const allSelected = computed({
    get: () => selectable.value.length > 0 && selectable.value.every((id) => selected.value.includes(id)),
    set: (on) => { selected.value = on ? [...selectable.value] : []; },
});
const bulkLinks = computed(() => bulkDocuments.value.map((doc) => ({
    ...doc,
    href: `${base.value}/apps/${encodeURIComponent(doc.plugin)}/documents/${encodeURIComponent(doc.key)}?ids=${selected.value.map(encodeURIComponent).join(',')}`,
})));

function choose(id, on) {
    selected.value = on ? [...new Set([...selected.value, id])] : selected.value.filter((value) => value !== id);
}

// 一覧が変わったら選び直す（ほかのページの注文を混ぜない）
watch(transactions, () => { selected.value = []; });

watch(() => props.tenant, async () => {
    documents.value = [];

    try {
        documents.value = (await api('GET', `${base.value}/order-documents`)).documents;
    } catch {
        // 書類の欄が出ないだけ（一覧は開ける）
    }
}, { immediate: true });
</script>

<template>
    <PageHeader :help="helpLink('transactions', tenant)" :title="titleOf('transactions')" :crumbs="crumbs" />
    <ErrorState v-if="error" :error="error" />

    <AppPanel v-else>
        <EmptyState v-if="noneYet" icon="receipt" title="まだ注文はありません" data-test="orders-empty">
            ショップで注文が入ると、ここに並びます。ショップを開いて、お客さんからどう見えるかを確認しましょう。
            <template v-if="shop" #action>
                <AppButton variant="primary" :href="shop" data-test="empty-open-storefront"><AppIcon name="external" />ショップを開く</AppButton>
            </template>
        </EmptyState>
        <template v-else>
            <ListFilters label="注文の絞り込み" :total="meta?.total ?? null" :filtered="filtered" @clear="clear">
                <template #search>
                    <SearchField ref="search" v-model="q" label="注文番号・商品名で探す" shortcut="/" wide data-test="transaction-search" @submit="submitSearch" />
                </template>
                <FilterSelect v-model="status" label="状態" data-test="status-filter">
                    <option value="">すべて（{{ counts?.all ?? 0 }}）</option>
                    <option v-for="(label, value) in ORDER_STATUS_LABELS" :key="value" :value="value">{{ statusLabel(value) }}</option>
                </FilterSelect>
                <FilterSelect v-model="payment" label="お支払い" data-test="payment-filter">
                    <option value="">すべて</option>
                    <option v-for="(label, value) in PAYMENT_FILTERS" :key="value" :value="value">{{ paymentLabel(value) }}</option>
                </FilterSelect>
                <FilterSelect v-model="sort" label="並べ替え" data-test="sort-select">
                    <option v-for="option in SORT_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                </FilterSelect>
            </ListFilters>

            <!-- 絞り込みの箱は読み込みの間も出す（打ち始められる。前の答えが遅れて届いても、後の条件の答えを上書きしない） -->
            <LoadingText v-if="transactions === null" />
            <EmptyState v-else-if="transactions.length === 0" icon="search" title="条件に合うものがありません" data-test="no-match">
                注文番号・商品名や状態を変えるか、条件を解除してください。
                <template #action>
                    <AppButton data-test="empty-clear-filters" @click="clear"><AppIcon name="x" />条件を解除</AppButton>
                </template>
            </EmptyState>
            <FormActions v-if="bulkDocuments.length > 0 && selected.length > 0 && transactions?.length" data-test="bulk-documents">
                <template #meta>{{ selected.length }} 件を選んでいます。発送を待つ注文を選んで、まとめて印刷できます。</template>
                <AppButton v-for="doc in bulkLinks" :key="`${doc.plugin}-${doc.key}`" size="sm" :href="doc.href" :data-test="`bulk-document-${doc.key}`"><AppIcon name="file" />{{ doc.label }}をまとめて開く</AppButton>
            </FormActions>
            <AppTable v-if="transactions?.length" label="注文の一覧" stack>
                <thead>
                    <tr>
                        <th v-if="bulkDocuments.length > 0"><AppCheckbox v-model="allSelected" label="すべて" test="select-all-orders" /></th>
                        <SortHeader v-model:sort="sort" field="created_at" label="注文" first-descending />
                        <th>状態</th>
                        <th>販売者</th>
                        <th class="num">明細</th>
                        <SortHeader v-model:sort="sort" field="total_amount" label="合計" numeric first-descending />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="transaction in transactions" :key="transaction.id" data-test="transaction-row">
                        <td v-if="bulkDocuments.length > 0" data-label="選ぶ">
                            <AppCheckbox :model-value="selected.includes(transaction.id)" label="選ぶ" :disabled="!transaction.order_status" test="select-order" @update:model-value="(on) => choose(transaction.id, on)" />
                        </td>
                        <td>
                            <CellTitle
                                :label="shortId(transaction.id)"
                                :sub="`${formatDate(transaction.created_at)}${transaction.has_customer ? '・顧客あり' : ''}`"
                                :to="{ name: 'transaction', params: { tenant, transaction: transaction.id } }"
                            />
                        </td>
                        <!-- 物販は対応の状況（入金待ち・入金済み・発送済み…）、ほかの取引は進み具合。札は丸の色と文字で -->
                        <td data-label="状態">
                            <StatusBadge v-if="transaction.order_status" :tone="ORDER_STATUS_TONES[transaction.order_status]">{{ ORDER_STATUS_LABELS[transaction.order_status] ?? transaction.order_status }}</StatusBadge>
                            <StatusBadge v-else :tone="TRANSACTION_STATUS_TONES[transaction.status]">{{ TRANSACTION_STATUS_LABELS[transaction.status] ?? transaction.status }}</StatusBadge>
                            <StatusBadge v-if="transaction.refund_state" :tone="REFUND_STATE_TONES[transaction.refund_state]" data-test="refund-state-badge">{{ REFUND_STATE_LABELS[transaction.refund_state] }}</StatusBadge>
                        </td>
                        <td data-label="販売者"><AppText as="span" tone="muted">{{ transaction.seller?.name ?? '—' }}</AppText></td>
                        <NumCell :value="transaction.lines_count" soft data-label="明細" />
                        <NumCell :value="formatAmount(transaction.total_amount, transaction.currency)" strong data-label="合計" />
                    </tr>
                </tbody>
            </AppTable>
            <AppPager v-if="transactions?.length > 0" :page="page" :last-page="lastPage" :total="meta.total" :count="false" @go="go" />
        </template>
    </AppPanel>
</template>
