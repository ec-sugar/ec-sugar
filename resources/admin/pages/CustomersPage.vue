<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../api.js';
import { customerNumber, formatAmount, formatDate } from '../format.js';
import { useListQuery } from '../listQuery.js';
import { crumbsFor, titleOf } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { tenantName } from '../tenants.js';
import AppPager from '../components/ui/AppPager.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import CellTitle from '../components/ui/CellTitle.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import FilterSelect from '../components/ui/FilterSelect.vue';
import ListFilters from '../components/ui/ListFilters.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import NumCell from '../components/ui/NumCell.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import SortHeader from '../components/ui/SortHeader.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';
import AppButton from '../components/ui/AppButton.vue';
import AppIcon from '../components/ui/AppIcon.vue';

// お客さんの一覧（ADR 0044 の 4。一覧の型）。件数と金額だけで、名前・メールは出さない（API も返さない。1 人の画面の「名前と連絡先を表示する」から）。
// 会員かで絞る・並べ替え（どれも多い順・新しい順だけ。サーバーの CustomerController::index と同じ候補）・ページ送り。条件は URL に持つ（listQuery.js）。
// 数は払われた注文だけ（ホームの売上と同じ決まり）。まとめた記録の注文は、まとめた先の数に足してある
const SORT_OPTIONS = [
    { value: '-last_order_at', label: '最後の注文の新しい順' },
    { value: '-orders', label: '注文の数の多い順' },
    { value: '-sales', label: '合計の高い順' },
    { value: '-created_at', label: '初回の日付の新しい順' },
];
const DEFAULT_SORT = '-last_order_at';
// 絞り込みの値（URL の status）→ API の member
const MEMBER_FILTERS = { member: '1', guest: '0' };
const props = defineProps({ tenant: { type: String, required: true } });
const { route, page, status, sort, filtered, clear, go } = useListQuery({
    statuses: Object.keys(MEMBER_FILTERS),
    sorts: SORT_OPTIONS.map((option) => option.value.slice(1)),
    defaultSort: DEFAULT_SORT,
});
// サーバーが受けるのは多い順だけ。URL に「-」の無い並びが来ても既定に戻す
const order = computed({
    get: () => (SORT_OPTIONS.some((option) => option.value === sort.value) ? sort.value : DEFAULT_SORT),
    set: (value) => {
        sort.value = value;
    },
});
const customers = ref(null);
const meta = ref(null);
const counts = ref(null);
const error = ref(null);

const lastPage = computed(() => (meta.value ? Math.max(1, Math.ceil(meta.value.total / meta.value.per_page)) : 1));
const crumbs = computed(() => crumbsFor('customers', props.tenant, tenantName(props.tenant)));
// まだ 1 人もいない（絞り込んでいない）。表の代わりに、注文が入ると並ぶことを書く
const noneYet = computed(() => counts.value !== null && counts.value.all === 0 && !filtered.value);

// 条件を続けて変えたとき、先に送った古い問い合わせの答えが後から届いても使わない
watch(() => [props.tenant, route.query], async (_now, _before, onCleanup) => {
    let stale = false;
    onCleanup(() => { stale = true; });
    error.value = null;
    const params = new URLSearchParams({ page: String(page.value), sort: order.value });

    if (status.value) {
        params.set('member', MEMBER_FILTERS[status.value]);
    }

    try {
        const result = await api('GET', `/api/tenants/${encodeURIComponent(props.tenant)}/customers?${params}`);

        if (stale) {
            return;
        }

        customers.value = result.customers;
        meta.value = result.meta;
        counts.value = result.counts;
    } catch (e) {
        if (!stale) {
            error.value = e;
        }
    }
}, { immediate: true, deep: true });
</script>

<template>
    <PageHeader :help="helpLink('customers', tenant)" :title="titleOf('customers')" :crumbs="crumbs" />
    <ErrorState v-if="error" :error="error" />

    <AppPanel v-else>
        <EmptyState v-if="noneYet" icon="customer" title="まだお客さんはいません" data-test="customers-empty">
            ショップで注文が入ると、注文のメールアドレスごとに 1 人として、ここに並びます。
        </EmptyState>
        <template v-else>
            <ListFilters label="お客さんの絞り込み" :total="meta?.total ?? null" :filtered="filtered" @clear="clear">
                <template #search>
                    <AppText tone="soft" size="sm">注文のメールアドレスごとに 1 人として数えます。注文の数と合計は、支払い済みの注文だけです。名前とメールアドレスは、お客さんごとの画面で表示します。</AppText>
                </template>
                <FilterSelect v-model="status" label="会員" data-test="member-filter">
                    <option value="">すべて（{{ counts?.all ?? 0 }}）</option>
                    <option value="member">会員（{{ counts?.members ?? 0 }}）</option>
                    <option value="guest">ゲスト（{{ counts ? counts.all - counts.members : 0 }}）</option>
                </FilterSelect>
                <FilterSelect v-model="order" label="並べ替え" data-test="sort-select">
                    <option v-for="option in SORT_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                </FilterSelect>
            </ListFilters>

            <LoadingText v-if="customers === null" />
            <EmptyState v-else-if="customers.length === 0" icon="search" title="条件に合うお客さんがいません" data-test="no-match">
                会員の絞り込みを変えるか、条件を解除してください。
                <template #action>
                    <AppButton data-test="empty-clear-filters" @click="clear"><AppIcon name="x" />条件を解除</AppButton>
                </template>
            </EmptyState>
            <AppTable v-else label="お客さんの一覧" stack>
                <thead>
                    <tr>
                        <SortHeader v-model:sort="order" field="created_at" label="お客さん" descending-only />
                        <th>会員</th>
                        <SortHeader v-model:sort="order" field="orders" label="注文の数" numeric descending-only />
                        <SortHeader v-model:sort="order" field="sales" label="合計" numeric descending-only />
                        <SortHeader v-model:sort="order" field="last_order_at" label="最後の注文" descending-only />
                        <th class="num">まとめた記録</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="customer in customers" :key="customer.id" data-test="customer-row" :data-customer="customer.id">
                        <td>
                            <CellTitle
                                :label="customerNumber(customer.id)"
                                :sub="`初回 ${formatDate(customer.created_at)}`"
                                :to="{ name: 'customer', params: { tenant, customer: customer.id } }"
                            />
                        </td>
                        <td data-label="会員">
                            <StatusBadge :tone="customer.member ? 'accent' : 'neutral'" data-test="customer-member">{{ customer.member ? '会員' : 'ゲスト' }}</StatusBadge>
                        </td>
                        <NumCell :value="customer.orders" data-label="注文の数" data-test="customer-orders" />
                        <NumCell :value="formatAmount(customer.sales)" strong data-label="合計" data-test="customer-sales" />
                        <td data-label="最後の注文"><AppText as="span" tone="muted" nowrap>{{ formatDate(customer.last_order_at) }}</AppText></td>
                        <NumCell :value="customer.merged_count" soft data-label="まとめた記録" data-test="customer-merged" />
                    </tr>
                </tbody>
            </AppTable>
            <AppPager v-if="customers.length > 0" :page="page" :last-page="lastPage" :total="meta.total" :count="false" @go="go" />
        </template>
    </AppPanel>
</template>
