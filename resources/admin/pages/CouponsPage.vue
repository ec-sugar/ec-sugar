<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../api.js';
import {
    COUPON_METHOD_LABELS, COUPON_METHOD_TONES, COUPON_STATE_LABELS, COUPON_STATE_TONES, conditionsSummary, couponTitle, discountLabel, periodLabel, usageLabel,
} from '../coupons.js';
import { useListQuery, useSearchShortcut } from '../listQuery.js';
import { crumbsFor, titleOf } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { canInTenant, tenantName } from '../tenants.js';
import AppButton from '../components/ui/AppButton.vue';
import AppIcon from '../components/ui/AppIcon.vue';
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
import PageHeader from '../components/ui/PageHeader.vue';
import SearchField from '../components/ui/SearchField.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';

// クーポンと自動の割引の一覧（一覧の型。ADR 0054 の区切り 2・ADR 0065）。上に絞り込みの箱（コード・メモ・名前の言葉と、使っている・止めている）、
// 表は種類の札（クーポン・自動）・コードか名前・値引き・条件の要約・使われた数／上限・期間・今使えるかの札。新しく作った順。条件は URL に持つ（listQuery.js）
const PER_PAGE = 50;
const STATUS_FILTERS = { active: '有効', paused: '停止中' };
const props = defineProps({ tenant: { type: String, required: true } });
const { route, q, page, status, filtered, submitSearch, clear, go } = useListQuery({
    statuses: Object.keys(STATUS_FILTERS),
    sorts: [],
    defaultSort: '',
});
const coupons = ref(null);
const meta = ref(null);
const counts = ref(null);
const error = ref(null);
const search = ref(null);

const lastPage = computed(() => (meta.value ? Math.max(1, Math.ceil(meta.value.total / meta.value.per_page)) : 1));
const name = computed(() => tenantName(props.tenant));
const canAdd = computed(() => canInTenant(props.tenant, 'operate'));
const noneYet = computed(() => counts.value !== null && counts.value.all === 0 && !filtered.value);
// 右上の「クーポンを作る」は 1 件以上あるとき（まだ無いときは空の案内の真ん中のボタンだけ。一覧の型）
const headerAdd = computed(() => canAdd.value && (counts.value === null ? error.value !== null : !noneYet.value));
const statusLabel = (value) => `${STATUS_FILTERS[value]}（${counts.value?.[value] ?? 0}）`;

// 条件を続けて変えたとき、先に送った古い問い合わせの答えが後から届いても使わない
watch(() => [props.tenant, route.query], async (_now, _before, onCleanup) => {
    let stale = false;
    onCleanup(() => { stale = true; });
    error.value = null;
    const params = new URLSearchParams({ page: String(page.value), per_page: String(PER_PAGE) });

    if (status.value) {
        params.set('status', status.value);
    }

    if (route.query.q) {
        params.set('q', String(route.query.q));
    }

    try {
        const result = await api('GET', `/api/tenants/${encodeURIComponent(props.tenant)}/coupons?${params}`);

        if (stale) {
            return;
        }

        coupons.value = result.coupons;
        meta.value = result.meta;
        counts.value = result.counts;
    } catch (e) {
        if (!stale) {
            error.value = e;
        }
    }
}, { immediate: true, deep: true });

useSearchShortcut(search);
</script>

<template>
    <PageHeader :help="helpLink('coupons', tenant)" :title="titleOf('coupons')" :crumbs="crumbsFor('coupons', tenant, name)">
        <template #actions>
            <template v-if="headerAdd">
                <AppButton :to="{ name: 'coupon-new', params: { tenant }, query: { method: 'automatic' } }" data-test="new-automatic-discount">
                    <AppIcon name="plus" />自動の割引を作る
                </AppButton>
                <AppButton variant="primary" :to="{ name: 'coupon-new', params: { tenant } }" data-test="new-coupon">
                    <AppIcon name="plus" />クーポンを作る
                </AppButton>
            </template>
        </template>
    </PageHeader>
    <ErrorState v-if="error" :error="error" />

    <AppPanel v-else>
        <EmptyState v-if="noneYet" icon="ticket" title="まだクーポンがありません" data-test="coupons-empty">
            コードを入力すると値引きになるクーポンと、コード無しで条件に合うカートに適用される自動の割引（全品 10%・まとめ買いなど）を作れます。金額・割合・送料無料から選び、期間や回数を設定できます。
            <template v-if="canAdd" #action>
                <AppButton variant="primary" :to="{ name: 'coupon-new', params: { tenant } }" data-test="empty-new-coupon">
                    <AppIcon name="plus" />クーポンを作る
                </AppButton>
                <AppButton :to="{ name: 'coupon-new', params: { tenant }, query: { method: 'automatic' } }" data-test="empty-new-automatic">
                    <AppIcon name="plus" />自動の割引を作る
                </AppButton>
            </template>
            <template v-else #action>
                <AppText as="span" tone="soft">作成できるのは「運用」以上の権限のある人だけです。</AppText>
            </template>
        </EmptyState>
        <template v-else>
            <ListFilters label="クーポンの絞り込み" :total="meta?.total ?? null" :filtered="filtered" @clear="clear">
                <template #search>
                    <SearchField ref="search" v-model="q" label="コード・メモ・名前で探す" shortcut="/" wide data-test="coupon-search" @submit="submitSearch" />
                </template>
                <FilterSelect v-model="status" label="状態" data-test="coupon-status-filter">
                    <option value="">すべて（{{ counts?.all ?? 0 }}）</option>
                    <option v-for="(label, value) in STATUS_FILTERS" :key="value" :value="value">{{ statusLabel(value) }}</option>
                </FilterSelect>
            </ListFilters>

            <LoadingText v-if="coupons === null" />
            <EmptyState v-else-if="coupons.length === 0" icon="search" title="条件に合うものがありません" data-test="no-match">
                検索する言葉や状態を変えるか、条件を解除してください。
                <template #action>
                    <AppButton data-test="empty-clear-filters" @click="clear"><AppIcon name="x" />条件を解除</AppButton>
                </template>
            </EmptyState>
            <AppTable v-else label="クーポンの一覧" stack>
                <thead>
                    <tr>
                        <th>コード・名前</th>
                        <th>種類</th>
                        <th>値引き</th>
                        <th>条件</th>
                        <th class="num">使われた数</th>
                        <th>期間</th>
                        <th>状態</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="coupon in coupons" :key="coupon.id" data-test="coupon-row">
                        <td><CellTitle :label="couponTitle(coupon)" :sub="coupon.name" :to="{ name: 'coupon', params: { tenant, coupon: coupon.id } }" /></td>
                        <td data-label="種類"><StatusBadge :tone="COUPON_METHOD_TONES[coupon.method]" data-test="coupon-method">{{ COUPON_METHOD_LABELS[coupon.method] ?? coupon.method }}</StatusBadge></td>
                        <td data-label="値引き">{{ discountLabel(coupon) }}</td>
                        <td data-label="条件">{{ conditionsSummary(coupon) }}</td>
                        <td class="num" data-label="使われた数">{{ usageLabel(coupon) }}</td>
                        <td data-label="期間">{{ periodLabel(coupon) }}</td>
                        <td data-label="状態"><StatusBadge :tone="COUPON_STATE_TONES[coupon.state]">{{ COUPON_STATE_LABELS[coupon.state] ?? coupon.state }}</StatusBadge></td>
                    </tr>
                </tbody>
            </AppTable>
            <AppPager v-if="(coupons?.length ?? 0) > 0" :page="page" :last-page="lastPage" :total="meta.total" :count="false" @go="go" />
        </template>
    </AppPanel>
</template>
