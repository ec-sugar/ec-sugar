<script setup>
import { computed, ref, watch } from 'vue';
import { api, describe, downloadGet } from '../api.js';
import { formatAmount, formatRate, STATUS_LABELS, STATUS_TONES } from '../format.js';
import { useListQuery, useSearchShortcut } from '../listQuery.js';
import { crumbsFor, tabsFor, titleOf } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { useSampleRemoval } from '../samples.js';
import { session } from '../session.js';
import { canInTenant, tenantName } from '../tenants.js';
import SampleRemovalNotices from '../components/SampleRemovalNotices.vue';
import SampleRemoveButton from '../components/SampleRemoveButton.vue';
import AppButton from '../components/ui/AppButton.vue';
import AppIcon from '../components/ui/AppIcon.vue';
import AppNotice from '../components/ui/AppNotice.vue';
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
import SearchField from '../components/ui/SearchField.vue';
import SortHeader from '../components/ui/SortHeader.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';

// 商品の一覧（一覧の型）。上に絞り込みの箱（言葉・状態・並べ替え・件数）、表は狭い画面で札の並びに。
// 条件は URL に持つ（listQuery.js）。まだ 1 つも無ければ真ん中に「商品を登録する」（右上には出さない）、絞り込んで 0 件なら「条件を外す」
const PER_PAGE = 50;
// 並べ替えの候補（サーバーの ListListingsRequest::SORTS と同じ名前）
const SORT_OPTIONS = [
    { value: '-updated_at', label: '更新日の新しい順' },
    { value: 'title', label: '名前の順' },
    { value: 'unit_amount', label: '価格の安い順' },
    { value: '-unit_amount', label: '価格の高い順' },
    { value: '-stock', label: '在庫の多い順' },
    { value: 'stock', label: '在庫の少ない順' },
];
const props = defineProps({ tenant: { type: String, required: true } });
const { route, q, page, status, sort, filtered, submitSearch, clear, go } = useListQuery({
    statuses: Object.keys(STATUS_LABELS),
    sorts: ['updated_at', 'title', 'unit_amount', 'stock'],
    defaultSort: '-updated_at',
});
const listings = ref(null);
const meta = ref(null);
const counts = ref(null);
const error = ref(null);
const search = ref(null);
// 一覧を読み直す印（サンプルを消した後に、同じ条件で読み直す）
const reloads = ref(0);
// 残っているサンプルの数（ホームと同じ GET …/setup の samples.remaining。閲覧の段階から読める）。読めなければ帯を出さないだけ
const samples = ref(null);
const isOwner = computed(() => session.operator?.role === 'owner');
// 帯はサンプルの商品が残っている間だけ（カテゴリや写真だけが残ったときは、ホームの開設の手順から消す）
const sampleListings = computed(() => samples.value?.listings ?? 0);
// サンプルをまとめて消す（オーナーだけ。ホームと同じ流れと文）。消したら残りの数を答えから受け、一覧を読み直す
const sampleRemoval = useSampleRemoval(() => props.tenant, {
    after: (result) => {
        samples.value = result.samples ?? null;
        reloads.value += 1;
    },
});

const lastPage = computed(() => (meta.value ? Math.max(1, Math.ceil(meta.value.total / meta.value.per_page)) : 1));
const name = computed(() => tenantName(props.tenant));
const canAdd = computed(() => canInTenant(props.tenant, 'operate'));
// まだ 1 つも無い（絞り込んでいない・どの状態も 0）。このときは表の代わりに、最初の 1 つを登録する案内を出す
const noneYet = computed(() => counts.value !== null && counts.value.all === 0 && !filtered.value);
// 見出しの右上の「商品を登録する」は、商品があるとき（絞り込んで 0 件も含む）だけ。まだ 1 つも無いときは空の案内の真ん中のボタンだけにする
// （同じボタンを 2 つ並べない）。読み込む前は出さず（空の店で一瞬出て消えないように）、読めなかったときは出す
const headerAdd = computed(() => canAdd.value && (counts.value === null ? error.value !== null : !noneYet.value));
const statusLabel = (value) => `${STATUS_LABELS[value]}（${counts.value?.[value] ?? 0}）`;
// 商品の CSV（docs/design/product-csv.md）。書き出しも取り込みも運用の段階から（見た目だけ。サーバーも 403 で断る）
const exporting = ref(false);
const exportError = ref(null);

async function exportCsv() {
    exporting.value = true;
    exportError.value = null;

    try {
        await downloadGet(`/api/tenants/${encodeURIComponent(props.tenant)}/listings-csv`, 'products.csv');
    } catch (e) {
        exportError.value = describe(e);
    } finally {
        exporting.value = false;
    }
}

// サンプルの残りの数は店ごとに 1 回だけ読む（絞り込みのたびには読まない）。店を替えたら前の店の答えは使わない
watch(() => props.tenant, async (tenant, _before, onCleanup) => {
    let stale = false;
    onCleanup(() => { stale = true; });
    samples.value = null;
    sampleRemoval.reset();

    try {
        const setup = await api('GET', `/api/tenants/${encodeURIComponent(tenant)}/setup`);

        if (!stale) {
            samples.value = setup.samples?.remaining ?? null;
        }
    } catch {
        // 帯は補助なので、読めなくても一覧は止めない
    }
}, { immediate: true });

// 条件を続けて変えたとき、先に送った古い問い合わせの答えが後から届いても使わない（後の答えを上書きしない）
watch(() => [props.tenant, route.query, reloads.value], async (_now, _before, onCleanup) => {
    let stale = false;
    onCleanup(() => { stale = true; });
    error.value = null;
    const params = new URLSearchParams({ page: String(page.value), per_page: String(PER_PAGE), sort: sort.value });

    if (status.value) {
        params.set('status', status.value);
    }

    if (route.query.q) {
        params.set('q', String(route.query.q));
    }

    try {
        const result = await api('GET', `/api/tenants/${encodeURIComponent(props.tenant)}/listings?${params}`);

        if (stale) {
            return;
        }

        listings.value = result.listings;
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
    <PageHeader :help="helpLink('listings', tenant)" :title="titleOf('listings')" :crumbs="crumbsFor('listings', tenant, name)" :tabs="tabsFor('listings', tenant)">
        <template #actions>
            <template v-if="canAdd">
                <AppButton :loading="exporting" data-test="export-csv" @click="exportCsv"><AppIcon name="download" />CSV で書き出す</AppButton>
                <AppButton :to="{ name: 'listing-import', params: { tenant } }" data-test="import-csv"><AppIcon name="upload" />CSV で取り込む</AppButton>
            </template>
            <!-- 登録は運用の段階から（見た目だけ。サーバーも 403 で断る）。まだ 1 つも無いときは空の案内のボタンだけ（headerAdd） -->
            <AppButton v-if="headerAdd" variant="primary" :to="{ name: 'listing-new', params: { tenant } }" data-test="new-listing">
                <AppIcon name="plus" />商品を登録する
            </AppButton>
        </template>
    </PageHeader>
    <AppNotice v-if="exportError" tone="error" data-test="export-error">{{ exportError }}</AppNotice>
    <!-- 入れたときのサンプルが残っている間だけ。自分の商品を登録したら、ここからもまとめて消せる（ボタンはオーナーにだけ） -->
    <AppNotice v-if="sampleListings > 0" tone="info" :live="false" data-test="samples-banner">
        <span>サンプルの商品が {{ sampleListings }} 点あります。ご自分の商品を登録したら、まとめて削除できます。</span>
        <div v-if="isOwner" class="flex flex-wrap items-center gap-2">
            <SampleRemoveButton :removal="sampleRemoval" label="サンプルをまとめて削除" />
        </div>
        <span v-else data-test="samples-owner-only">削除できるのはオーナーだけです。</span>
    </AppNotice>
    <SampleRemovalNotices :removal="sampleRemoval" />
    <ErrorState v-if="error" :error="error" />

    <AppPanel v-else>
        <EmptyState v-if="noneYet" icon="box" title="まだ商品がありません" data-test="listings-empty">
            最初の商品を登録しましょう。名前と価格を入力して公開すると、ショップに並びます。
            <template v-if="canAdd" #action>
                <AppButton variant="primary" :to="{ name: 'listing-new', params: { tenant } }" data-test="empty-new-listing">
                    <AppIcon name="plus" />商品を登録する
                </AppButton>
            </template>
            <template v-else #action>
                <AppText as="span" tone="soft">登録できるのは「運用」以上の権限のある人だけです。</AppText>
            </template>
        </EmptyState>
        <template v-else>
            <ListFilters label="商品の絞り込み" :total="meta?.total ?? null" :filtered="filtered" @clear="clear">
                <template #search>
                    <SearchField ref="search" v-model="q" label="商品名・品番で探す" shortcut="/" wide data-test="listing-search" @submit="submitSearch" />
                </template>
                <FilterSelect v-model="status" label="状態" data-test="status-filter">
                    <option value="">すべて（{{ counts?.all ?? 0 }}）</option>
                    <option v-for="(label, value) in STATUS_LABELS" :key="value" :value="value">{{ statusLabel(value) }}</option>
                </FilterSelect>
                <FilterSelect v-model="sort" label="並べ替え" data-test="sort-select">
                    <option v-for="option in SORT_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                </FilterSelect>
            </ListFilters>

            <!-- 絞り込みの箱は読み込みの間も出す（打ち始められる。前の答えが遅れて届いても、後の条件の答えを上書きしない） -->
            <LoadingText v-if="listings === null" />
            <EmptyState v-else-if="listings.length === 0" icon="search" title="条件に合うものがありません" data-test="no-match">
                検索する言葉や状態を変えるか、条件を解除してください。
                <template #action>
                    <AppButton data-test="empty-clear-filters" @click="clear"><AppIcon name="x" />条件を解除</AppButton>
                </template>
            </EmptyState>
            <AppTable v-else label="商品の一覧" stack>
                <thead>
                    <tr>
                        <SortHeader v-model:sort="sort" field="title" label="商品" />
                        <th>状態</th>
                        <SortHeader v-model:sort="sort" field="unit_amount" label="価格" numeric />
                        <th class="num">税率</th>
                        <SortHeader v-model:sort="sort" field="stock" label="在庫" numeric first-descending />
                        <th class="num">確保済み</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="listing in listings" :key="listing.id" data-test="listing-row">
                        <td><CellTitle :label="listing.title" :sub="listing.sku" :to="{ name: 'listing', params: { tenant, listing: listing.id } }" /></td>
                        <td data-label="状態"><StatusBadge :tone="STATUS_TONES[listing.status]">{{ STATUS_LABELS[listing.status] ?? listing.status }}</StatusBadge></td>
                        <NumCell :value="formatAmount(listing.unit_amount, listing.currency)" data-label="価格" />
                        <NumCell :value="formatRate(listing.tax_rate_bp)" soft data-label="税率" data-test="listing-tax-rate" />
                        <NumCell :value="listing.stock.quantity" strong data-label="在庫" />
                        <NumCell :value="listing.stock.reserved" soft data-label="確保済み" />
                    </tr>
                </tbody>
            </AppTable>
            <AppPager v-if="(listings?.length ?? 0) > 0" :page="page" :last-page="lastPage" :total="meta.total" :count="false" @go="go" />
        </template>
    </AppPanel>
</template>
