<script setup>
import { computed, ref, watch } from 'vue';
import { api, describe, downloadGet } from '../api.js';
import { formatAmount, formatDay } from '../format.js';
import { crumbsFor, titleOf } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { tenantName } from '../tenants.js';
import AppButton from '../components/ui/AppButton.vue';
import AppField from '../components/ui/AppField.vue';
import AppIcon from '../components/ui/AppIcon.vue';
import AppInput from '../components/ui/AppInput.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import CellTitle from '../components/ui/CellTitle.vue';
import DailyBars from '../components/ui/DailyBars.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import NumCell from '../components/ui/NumCell.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import SegmentedTabs from '../components/ui/SegmentedTabs.vue';
import StatCard from '../components/ui/StatCard.vue';

// 売上の集計（ADR 0060）。お金の段階から（API も 403 で断る）。
// 上に期間（今日・今週・今月・先月・期間を選ぶ。日本の時刻で区切る）→ 数のカード → 日ごとの棒 → よく売れた商品 → お客さん → CSV。
// 金額は台帳から、お金が動いた日で数える（入金は払った日、返金は返した日）。注文の数は払いが確かになった日。文はここで作り、サーバーは数だけを返す
const props = defineProps({ tenant: { type: String, required: true } });
const PRESETS = [
    { value: 'today', label: '今日' },
    { value: 'week', label: '今週' },
    { value: 'month', label: '今月' },
    { value: 'last_month', label: '先月' },
    { value: 'custom', label: '期間を選ぶ' },
];
const BY = [
    { value: 'by_amount', label: '金額の多い順' },
    { value: 'by_quantity', label: '数量の多い順' },
];

const preset = ref('month');
const from = ref('');
const to = ref('');
// 期間を選ぶときは「表示する」を押してから読む（日付を入れている途中で読まない）
const applied = ref({ preset: 'month', from: '', to: '' });
const data = ref(null);
const error = ref(null);
const formError = ref(null);
const by = ref('by_amount');
const exporting = ref(null);
const exportError = ref(null);

const name = computed(() => tenantName(props.tenant));
const totals = computed(() => data.value?.totals ?? null);
const products = computed(() => data.value?.products?.[by.value] ?? []);
const rangeLabel = computed(() => (data.value ? (data.value.period.from === data.value.period.to
    ? formatDay(data.value.period.from)
    : `${formatDay(data.value.period.from)} 〜 ${formatDay(data.value.period.to)}`) : ''));

function query() {
    const params = new URLSearchParams({ preset: applied.value.preset });

    if (applied.value.preset === 'custom') {
        params.set('from', applied.value.from);
        params.set('to', applied.value.to);
    }

    return params;
}

watch(preset, (value) => {
    if (value !== 'custom') {
        applied.value = { preset: value, from: '', to: '' };
    }
});

function applyCustom() {
    formError.value = null;

    if (!from.value || !to.value) {
        formError.value = '開始日と終了日を入力してください。';

        return;
    }

    applied.value = { preset: 'custom', from: from.value, to: to.value };
}

// 期間を続けて変えたとき、先に送った古い問い合わせの答えが後から届いても使わない
watch(() => [props.tenant, applied.value], async (_now, _before, onCleanup) => {
    let stale = false;
    onCleanup(() => { stale = true; });
    error.value = null;
    formError.value = null;

    try {
        const result = await api('GET', `/api/tenants/${encodeURIComponent(props.tenant)}/sales?${query()}`);

        if (!stale) {
            data.value = result;
        }
    } catch (e) {
        if (stale) {
            return;
        }

        // 期間の誤り（長すぎる・先の日）は欄の下に出し、前の数は残す
        if (e.status === 422) {
            formError.value = describe(e);
        } else {
            error.value = e;
        }
    }
}, { immediate: true, deep: true });

async function exportCsv(kind) {
    exporting.value = kind;
    exportError.value = null;
    const params = query();
    params.set('kind', kind);

    try {
        await downloadGet(`/api/tenants/${encodeURIComponent(props.tenant)}/sales/csv?${params}`, `sales-${kind}.csv`);
    } catch (e) {
        exportError.value = describe(e);
    } finally {
        exporting.value = null;
    }
}
</script>

<template>
    <PageHeader :help="helpLink('sales', tenant)" :title="titleOf('sales')" :crumbs="crumbsFor('sales', tenant, name)">
        <template #actions>
            <AppButton :loading="exporting === 'daily'" :disabled="data === null" data-test="sales-csv-daily" @click="exportCsv('daily')"><AppIcon name="download" />日ごとの CSV</AppButton>
            <AppButton :loading="exporting === 'products'" :disabled="data === null" data-test="sales-csv-products" @click="exportCsv('products')"><AppIcon name="download" />商品ごとの CSV</AppButton>
        </template>
    </PageHeader>
    <AppNotice v-if="exportError" tone="error" data-test="sales-export-error">{{ exportError }}</AppNotice>

    <AppPanel title="期間" :description="rangeLabel ? `${rangeLabel}（日本時間で日付を区切ります）` : '日本時間で日付を区切ります'" data-test="sales-period">
        <SegmentedTabs v-model="preset" :items="PRESETS" label="期間" />
        <form v-if="preset === 'custom'" class="flex flex-wrap items-end gap-4" data-test="sales-custom" @submit.prevent="applyCustom">
            <AppField label="開始日">
                <AppInput v-model="from" type="date" data-test="sales-from" />
            </AppField>
            <AppField label="終了日" hint="366 日まで・今日まで">
                <AppInput v-model="to" type="date" data-test="sales-to" />
            </AppField>
            <AppButton type="submit" variant="primary" data-test="sales-apply">表示する</AppButton>
        </form>
        <AppNotice v-if="formError" tone="error" data-test="sales-period-error">{{ formError }}</AppNotice>
    </AppPanel>

    <ErrorState v-if="error" :error="error" />
    <LoadingText v-else-if="data === null" />

    <template v-else>
        <section aria-label="期間の数" class="grid grid-cols-2 gap-4 xl:grid-cols-3" data-test="sales-totals">
            <StatCard label="売上（税込み）" :value="formatAmount(totals.sales)" :note="`入金 ${formatAmount(totals.gross)} − 返金 ${formatAmount(totals.refunds)}`" value-test="sales-total" />
            <StatCard label="注文の数" :value="`${totals.orders} 件`" :note="totals.cancelled_orders > 0 ? `うち取消・返金 ${totals.cancelled_orders} 件` : '支払いが確定した注文'" value-test="sales-orders" />
            <StatCard label="平均注文額" :value="formatAmount(totals.average)" note="入金 ÷ 注文の数" value-test="sales-average" />
            <StatCard label="返金" :value="formatAmount(totals.refunds)" note="この期間に返金した額" value-test="sales-refunds" />
            <StatCard label="クーポンの値引き" :value="formatAmount(totals.discounts)" note="送料無料の分も含みます" value-test="sales-discounts" />
            <StatCard label="送料" :value="formatAmount(totals.shipping)" note="お客さんからいただいた送料" value-test="sales-shipping" />
            <!-- ポイント（ADR 0068）。使ったポイントはこの期間の注文の分、まだ使われていないポイントは今の数（期間によらない） -->
            <template v-if="totals.points_used > 0 || data.points.outstanding > 0">
                <StatCard label="使われたポイント" :value="formatAmount(totals.points_used)" note="注文で値引きした額（1 ポイント = 1 円）" value-test="sales-points-used" />
                <StatCard label="まだ使われていないポイント" :value="formatAmount(data.points.outstanding)" :note="`現時点の値。会員 ${data.points.holders} 人の残高の合計`" value-test="sales-points-outstanding" />
            </template>
        </section>
        <AppText v-if="totals.test_orders > 0" size="sm" tone="muted" data-test="sales-test-orders">テストの注文 {{ totals.test_orders }} 件は含んでいません。</AppText>

        <AppPanel title="日ごとの売上" description="入金 − 返金。返金が入金より多い日は、線だけで表示します" data-test="sales-daily">
            <DailyBars :items="data.daily" :currency="data.currency" label="日ごとの売上" />
        </AppPanel>

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            <AppPanel title="よく売れた商品" description="取り消した注文の商品は含みません。金額は、オプションと値引きを含まない額です" class="lg:col-span-2" data-test="sales-products">
                <template #actions>
                    <SegmentedTabs v-model="by" :items="BY" label="並べ方" />
                </template>
                <EmptyState v-if="products.length === 0" icon="box" title="この期間に売れた商品はありません" data-test="sales-products-empty" />
                <AppTable v-else label="よく売れた商品" stack>
                    <thead>
                        <tr>
                            <th>商品</th>
                            <th class="num">数量</th>
                            <th class="num">金額</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="product in products" :key="product.listing_id ?? product.title" data-test="sales-product">
                            <td><CellTitle :label="product.title" :to="product.listing_id ? { name: 'listing', params: { tenant, listing: product.listing_id } } : null" /></td>
                            <td data-label="数量"><NumCell :value="product.quantity" /></td>
                            <td data-label="金額"><NumCell :value="formatAmount(product.amount)" strong /></td>
                        </tr>
                    </tbody>
                </AppTable>
            </AppPanel>
            <AppPanel title="お客さん" description="この期間に注文したお客さん（同じお客さんをまとめた記録は 1 人）" data-test="sales-customers">
                <div class="grid grid-cols-1 gap-3">
                    <StatCard size="sm" label="新しいお客さん" :value="`${data.customers.new} 人`" note="この期間に初めて注文した" value-test="sales-new-customers" />
                    <StatCard size="sm" label="リピーター" :value="`${data.customers.returning} 人`" note="前にも注文したことがある" value-test="sales-returning-customers" />
                    <StatCard v-if="data.customers.guests > 0" size="sm" label="お客さんの記録の無い注文" :value="`${data.customers.guests} 件`" value-test="sales-guests" />
                </div>
            </AppPanel>
        </div>
    </template>
</template>
