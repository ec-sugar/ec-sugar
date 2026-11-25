<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api.js';
import { appPageTitle, cellText, isNumeric } from '../appPages.js';
import { crumbsFor, titleOf } from '../navigation.js';
import { appHelpLink } from '../help/index.js';
import { useOperation } from '../operation.js';
import { canInTenant, tenantName } from '../tenants.js';
import AppExportForm from './app/AppExportForm.vue';
import AppImportForm from './app/AppImportForm.vue';
import AppLookupForm from './app/AppLookupForm.vue';
import AppRecordForm from './app/AppRecordForm.vue';
import AppButton from '../components/ui/AppButton.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPageValue from '../components/ui/AppPageValue.vue';
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
import SegmentedTabs from '../components/ui/SegmentedTabs.vue';
import TextLink from '../components/ui/TextLink.vue';

// App の画面の一覧（ADR 0051。定期便の一覧など）。中身（題・絞り込みと件数・列・行）は App が渡し、描くのは核の部品だけ（App の画面のコードは読み込まない）。
// 絞り込みはタブ（SegmentedTabs。7 個を超えたら選択に替える）、表は列の順、行の名前（最初の列）から 1 件の画面へ。ページ送り。
// 絞り込みとページは URL に持つ（?filter=・?page=。形の合わない値は使わない。どの絞り込みがあるかはサーバーが確認し、知らなければ 404）。
// 値の形ごとの描き方は AppPageValue（文字として差し込む。リンクは核の経路の名前から作る）。
// 足す欄を持つ App（よくある質問など）は、見出しの右に足すボタン（運用の段階から）と、押すと開く足す欄（核の部品 AppRecordForm）。
// 足したら欄を閉じて一覧を読み直す。1 件の画面で消して戻ってきた時は「消しました」（戻り先の印は画面の移動の state に置く）。
// CSV の書き出し・取り込みの口を持つ App（配送の CSV など）は、見出しの右にそのボタン（運用の段階から）と、押すと開く欄（AppExportForm・AppImportForm）。
// 取り込むと確認の記録ができるので、その 1 件の画面へ移る（中身を見てから操作を押す）。
// 「読む・探す」の欄を持つ App（チケットの受付）は、一覧の上にその欄（AppLookupForm。QR を読む・番号を入れる。運用の段階から）
const props = defineProps({
    tenant: { type: String, required: true },
    plugin: { type: String, required: true },
});
const route = useRoute();
const router = useRouter();
const data = ref(null);
const error = ref(null);
const adding = ref(false);
// 開いている書き出し・取り込みの欄（'export'・'import'・null）
const filePanel = ref(null);
const creating = useOperation();
const formRef = ref(null);
// 一覧を読み直す合図（足した後）
const reloads = ref(0);
const removed = ref(window.history.state?.appRemoved === true);
const canOperate = computed(() => canInTenant(props.tenant, 'operate'));

// 名前の形はサーバーの確認と同じ（app/Admin/Http/Controllers/Api/AppPageController.php）
const FILTER = /^[a-z][a-z0-9_]{0,31}$/;
const RECORD_ID = /^[0-9a-z]{26}$/;
// タブにする絞り込みの数の上限（SegmentedTabs は 7 個まで。docs/design/admin-ui.md）
const MAX_TABS = 7;
// 行の名前（1 件の画面へのリンク）にできる列の形。状態の札とリンクは、それ自体が行の名前にならないので「開く」の列を足す
const TITLE_TYPES = ['text', 'number', 'money', 'date', 'datetime'];

const filterQuery = computed(() => (typeof route.query.filter === 'string' && FILTER.test(route.query.filter) ? route.query.filter : null));
const page = computed(() => {
    const n = Number.parseInt(String(route.query.page ?? '1'), 10);

    return Number.isSafeInteger(n) && n >= 1 && n <= 10000 ? n : 1;
});

const title = computed(() => data.value?.title ?? appPageTitle(props.tenant, props.plugin) ?? titleOf('app-page'));
const crumbs = computed(() => crumbsFor('app-page', props.tenant, tenantName(props.tenant), null, { plugin: props.plugin, title: title.value }));
const help = computed(() => appHelpLink(props.plugin, 'app-page', props.tenant));
const tabs = computed(() => (data.value?.filters ?? []).map((filter) => ({ value: filter.key, label: filter.label, count: filter.count })));
const current = computed(() => data.value?.filters.find((filter) => filter.key === data.value.filter) ?? null);
const lastPage = computed(() => (data.value ? Math.max(1, Math.ceil(data.value.meta.total / data.value.meta.per_page)) : 1));
// どの絞り込みも 0 件（まだ 1 件も無い）。絞り込みの箱も表も出さず、空の案内だけ
const noneYet = computed(() => data.value !== null && data.value.filters.every((filter) => filter.count === 0));
const titleColumn = computed(() => {
    const first = data.value?.columns[0] ?? null;

    return first !== null && TITLE_TYPES.includes(first.type) ? first : null;
});

function navigate(changes) {
    const query = { ...route.query, ...changes };
    router.push({ query: Object.fromEntries(Object.entries(query).filter(([, v]) => v !== undefined && v !== '')) });
}

function choose(filter) {
    navigate({ filter, page: undefined });
}

function go(n) {
    navigate({ page: n === 1 ? undefined : String(n) });
}

async function create(values) {
    await creating.run('create', () => api('POST', `/api/tenants/${encodeURIComponent(props.tenant)}/apps/${encodeURIComponent(props.plugin)}/records`, { values }), {
        then: () => {
            adding.value = false;
            removed.value = false;
            formRef.value?.reset();
            reloads.value += 1;
        },
        done: '追加しました。',
    });
}

function toggleFile(kind) {
    filePanel.value = filePanel.value === kind ? null : kind;
}

function openImported(id) {
    const record = typeof id === 'string' ? id.toLowerCase() : '';

    if (RECORD_ID.test(record)) {
        router.push({ name: 'app-record', params: { tenant: props.tenant, plugin: props.plugin, record } });
    }
}

function recordTo(row) {
    const id = typeof row.id === 'string' ? row.id.toLowerCase() : '';

    return RECORD_ID.test(id) ? { name: 'app-record', params: { tenant: props.tenant, plugin: props.plugin, record: id } } : null;
}

// 条件を続けて変えたとき、先に送った古い問い合わせの答えが後から届いても使わない。別の App・別の店に移ったら、読み終えるまで前の表を出さない
watch(() => [props.tenant, props.plugin, filterQuery.value, page.value, reloads.value], async ([tenant, plugin], before, onCleanup) => {
    let stale = false;
    onCleanup(() => { stale = true; });
    error.value = null;

    if (before === undefined || before[0] !== tenant || before[1] !== plugin) {
        data.value = null;
        adding.value = false;
        filePanel.value = null;
    }

    const params = new URLSearchParams({ page: String(page.value) });

    if (filterQuery.value !== null) {
        params.set('filter', filterQuery.value);
    }

    try {
        const result = await api('GET', `/api/tenants/${encodeURIComponent(tenant)}/apps/${encodeURIComponent(plugin)}/records?${params}`);

        if (!stale) {
            data.value = result;
        }
    } catch (e) {
        if (!stale) {
            error.value = e;
        }
    }
}, { immediate: true });
</script>

<template>
    <PageHeader :help="help" :title="title" :crumbs="crumbs">
        <template v-if="(data?.form || data?.export || data?.import) && canOperate" #actions>
            <AppButton v-if="data.export" :aria-expanded="filePanel === 'export' ? 'true' : 'false'" data-test="app-export-open" @click="toggleFile('export')">{{ data.export.label }}</AppButton>
            <AppButton v-if="data.import" :variant="data.form ? undefined : 'primary'" :aria-expanded="filePanel === 'import' ? 'true' : 'false'" data-test="app-import-open" @click="toggleFile('import')">{{ data.import.label }}</AppButton>
            <AppButton v-if="data.form" variant="primary" :aria-expanded="adding ? 'true' : 'false'" data-test="app-create-open" @click="adding = !adding">{{ data.form.label }}</AppButton>
        </template>
    </PageHeader>
    <AppNotice v-if="removed" tone="success" data-test="app-removed">削除しました。</AppNotice>
    <AppNotice v-if="creating.message.value" tone="success" data-test="app-create-done">{{ creating.message.value }}</AppNotice>
    <ErrorState v-if="error" :error="error" />

    <AppPanel v-if="!error && canOperate && data?.lookup" :title="data.lookup.label" data-test="app-lookup-panel">
        <AppLookupForm :tenant="tenant" :plugin="plugin" :lookup="data.lookup" />
    </AppPanel>

    <AppPanel v-if="!error && adding && data?.form" :title="data.form.label" data-test="app-create">
        <AppNotice v-if="creating.error.value" tone="error">{{ creating.error.value }}</AppNotice>
        <AppRecordForm ref="formRef" :form="data.form" :tenant="tenant" submit-label="追加" :errors="creating.errors.value" :pending="creating.pending.value !== null" test="app-create-form" @submit="create" />
    </AppPanel>

    <AppPanel v-if="!error && canOperate && filePanel === 'export' && data?.export" :title="data.export.label" data-test="app-export">
        <AppExportForm :tenant="tenant" :plugin="plugin" :form="data.export" @exported="reloads += 1" />
    </AppPanel>

    <AppPanel v-if="!error && canOperate && filePanel === 'import' && data?.import" :title="data.import.label" data-test="app-import">
        <AppImportForm :tenant="tenant" :plugin="plugin" :form="data.import" @created="openImported" />
    </AppPanel>

    <AppPanel v-if="!error">
        <LoadingText v-if="data === null" />
        <EmptyState v-else-if="noneYet" icon="list" :title="`まだ${title}はありません`" data-test="app-records-empty">
            <template v-if="data.form">「{{ data.form.label }}」から追加すると、ここに並びます。</template>
            <template v-else-if="data.import">「{{ data.import.label }}」から取り込むと、ここに並びます。</template>
            <template v-else>お客さんが使い始めると、ここに並びます。</template>
        </EmptyState>
        <template v-else>
            <ListFilters :label="`${title}の絞り込み`" :total="data.meta.total">
                <template #search>
                    <SegmentedTabs v-if="tabs.length <= MAX_TABS" :model-value="data.filter" :items="tabs" :label="`${title}の状態`" @update:model-value="choose" />
                    <FilterSelect v-else :model-value="data.filter" label="状態" data-test="app-filter" @update:model-value="choose">
                        <option v-for="tab in tabs" :key="tab.value" :value="tab.value">{{ tab.label }}（{{ tab.count }}）</option>
                    </FilterSelect>
                </template>
            </ListFilters>

            <EmptyState v-if="data.rows.length === 0" icon="list" :title="`「${current?.label ?? ''}」の${title}はありません`" data-test="app-records-none">
                ほかのタブを選ぶと、その状態の{{ title }}が並びます。
            </EmptyState>
            <AppTable v-else :label="`${title}の一覧`" stack>
                <thead>
                    <tr>
                        <th v-for="column in data.columns" :key="column.key" :class="isNumeric(column.type) ? 'num' : null">{{ column.label }}</th>
                        <th v-if="titleColumn === null"><span class="sr-only">開く</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in data.rows" :key="row.id" data-test="app-row" :data-record="row.id">
                        <template v-for="(column, index) in data.columns" :key="column.key">
                            <td v-if="index === 0 && titleColumn !== null" :data-test="`app-cell-${column.key}`">
                                <CellTitle :label="cellText(column.type, row.cells[column.key])" :to="recordTo(row)" />
                            </td>
                            <NumCell v-else-if="isNumeric(column.type)" :value="cellText(column.type, row.cells[column.key])" :data-label="column.label" :data-test="`app-cell-${column.key}`" />
                            <td v-else :data-label="column.label" :data-test="`app-cell-${column.key}`">
                                <AppText v-if="column.type === 'date' || column.type === 'datetime'" as="span" tone="muted" nowrap>{{ cellText(column.type, row.cells[column.key]) }}</AppText>
                                <AppPageValue v-else :type="column.type" :value="row.cells[column.key]" :tenant="tenant" />
                            </td>
                        </template>
                        <td v-if="titleColumn === null">
                            <TextLink v-if="recordTo(row)" :to="recordTo(row)" data-test="app-row-open">開く</TextLink>
                        </td>
                    </tr>
                </tbody>
            </AppTable>
            <AppPager v-if="data.rows.length > 0" :page="data.meta.page" :last-page="lastPage" :total="data.meta.total" :count="false" @go="go" />
        </template>
    </AppPanel>
</template>
