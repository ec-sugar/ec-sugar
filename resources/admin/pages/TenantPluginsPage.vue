<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../api.js';
import { loadAppPages } from '../appPages.js';
import { useListQuery, useSearchShortcut } from '../listQuery.js';
import { useOperation } from '../operation.js';
import { crumbsFor, titleOf } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { canInTenant, tenantName } from '../tenants.js';
import AppButton from '../components/ui/AppButton.vue';
import AppIcon from '../components/ui/AppIcon.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppText from '../components/ui/AppText.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import FilterSelect from '../components/ui/FilterSelect.vue';
import ListFilters from '../components/ui/ListFilters.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import PluginCard from '../components/ui/PluginCard.vue';
import SearchField from '../components/ui/SearchField.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';

// 店の App の画面（ADR 0025。で目的ごとの札の並びに）。入っている App を目的ごとのまとまりに札で並べる
// （名前・一言の説明・この店で使うか・設定がそろっているか）。目的の言葉と並びはサーバーが返す（核の AppCategory。画面に写さない）。
// 上に絞り込みの箱（言葉で探す・使っている App だけ）。条件は URL に持つ（listQuery.js）。App は多くても数十なので、絞り込みはこの画面の中で行う。
// 札を押すと App ごとの設定の画面（TenantPluginPage）へ。使う・使わないは札のボタンで、運用の段階でパスキーで確認してから（サーバーの再確認）。
// 札のボタンは主な操作の色にしない（primary は 1 画面に 1 つ。札が並ぶので「使う」は secondary、「使わない」は ghost）
const props = defineProps({ tenant: { type: String, required: true } });
const STATES = ['enabled', 'disabled'];
// 目的の絵（知らない目的は差し込みの絵）
const CATEGORY_ICONS = {
    analytics: 'chart',
    marketing: 'megaphone',
    page: 'layout',
    selling: 'repeat',
    products: 'box',
    payment: 'card',
    shipping: 'truck',
    customers: 'users',
    operations: 'store',
    other: 'plug',
};
const plugins = ref(null);
const categories = ref([]);
const loadError = ref(null);
const search = ref(null);
const { pending, error, message, run } = useOperation();
// sort は使わない（並びは目的の順・名前の順で決まっている）
const { route, q, status, filtered, submitSearch, clear } = useListQuery({ statuses: STATES, sorts: ['name'], defaultSort: 'name' });

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/plugins`);
const crumbs = computed(() => crumbsFor('tenant-plugins', props.tenant, tenantName(props.tenant)));
const canEdit = computed(() => canInTenant(props.tenant, 'operate'));
const labelOf = computed(() => Object.fromEntries(categories.value.map((c) => [c.key, c.label])));

function pageOf(plugin) {
    return { name: 'tenant-plugin', params: { tenant: props.tenant, plugin: plugin.name } };
}

// 言葉は名前・説明・目的の名前のどれかに含まれるか（大文字と小文字を分けない）
const words = computed(() => String(route.query.q ?? '').trim().toLowerCase());
const matching = computed(() => (plugins.value ?? []).filter((plugin) => {
    if (status.value === 'enabled' && !plugin.enabled) return false;
    if (status.value === 'disabled' && plugin.enabled) return false;
    if (words.value === '') return true;

    return [plugin.title, plugin.description, labelOf.value[plugin.category] ?? '', plugin.name].some((text) => String(text).toLowerCase().includes(words.value));
}));
// 目的ごとのまとまり（核の順。App の無いまとまりは出さない。知らない目的は「そのほか」へ）
const groups = computed(() => {
    const known = new Set(categories.value.map((c) => c.key));

    return categories.value
        .map((c) => ({ ...c, plugins: matching.value.filter((p) => (known.has(p.category) ? p.category : 'other') === c.key) }))
        .filter((group) => group.plugins.length > 0);
});
const counts = computed(() => {
    const all = plugins.value ?? [];
    const enabled = all.filter((p) => p.enabled).length;

    return { all: all.length, enabled, disabled: all.length - enabled };
});

// 設定がそろっているか（設定の無い App は出さない）。使っているのにそろっていなければ、ショップでは動いていないことを添える
function settingsState(plugin) {
    if (plugin.settings === null) return null;
    if (plugin.settings.complete) return { tone: 'ok', label: '設定済み' };

    return plugin.enabled ? { tone: 'warn', label: '未設定（動いていません）' } : { tone: 'neutral', label: '未設定' };
}

watch(() => props.tenant, async () => {
    plugins.value = null;
    loadError.value = null;

    try {
        fill(await api('GET', base.value));
    } catch (e) {
        loadError.value = e;
    }
}, { immediate: true });

function fill(result) {
    categories.value = result.categories ?? [];
    plugins.value = result.plugins;
}

function toggle(plugin) {
    const enabled = !plugin.enabled;

    return run(plugin.name, () => api('PUT', `${base.value}/${encodeURIComponent(plugin.name)}`, { enabled }), {
        done: `「${plugin.title}」を${enabled ? '使い始めました' : '使わないようにしました'}。`,
        // App の画面（ADR 0051）を持つ App なら、メニューの項目が出る・消える
        then: async (result) => {
            fill(result);
            await loadAppPages(props.tenant, { refresh: true });
        },
    });
}

useSearchShortcut(search);
</script>

<template>
    <PageHeader :help="helpLink('tenant-plugins', tenant)" :title="titleOf('tenant-plugins')" :crumbs="crumbs" />
    <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
    <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>
    <ErrorState v-if="loadError" :error="loadError" />
    <LoadingText v-else-if="plugins === null" />

    <AppPanel v-else-if="plugins.length === 0">
        <EmptyState icon="plug" title="App はインストールされていません" data-test="plugins-empty">インストールした App は、ここに目的ごとに並びます。</EmptyState>
    </AppPanel>

    <template v-else>
        <AppPanel title="この店で使う App" description="目的ごとに並べています。「使わない」にした App は、この店では動きません（App の中のデータは残ります）。カードを押すと、ID などを設定する画面へ移ります。">
            <ListFilters label="App の絞り込み" :total="matching.length" :filtered="filtered" @clear="clear">
                <template #search>
                    <SearchField ref="search" v-model="q" label="App の名前や目的で探す" shortcut="/" wide data-test="plugin-search" @submit="submitSearch" />
                </template>
                <FilterSelect v-model="status" label="この店では" data-test="plugin-state-filter">
                    <option value="">すべて（{{ counts.all }}）</option>
                    <option value="enabled">使っている App だけ（{{ counts.enabled }}）</option>
                    <option value="disabled">使っていない App（{{ counts.disabled }}）</option>
                </FilterSelect>
            </ListFilters>
            <AppText v-if="!canEdit" tone="soft" size="sm" data-test="plugins-read-only">使う・使わないを切り替えられるのは、「運用」以上の権限のある人だけです。</AppText>
        </AppPanel>

        <AppPanel v-if="groups.length === 0">
            <EmptyState icon="search" :title="status === 'enabled' && words === '' ? 'この店で使っている App はまだありません' : '条件に合う App がありません'" data-test="plugins-no-match">
                <template v-if="status === 'enabled' && words === ''">使いたい App のカードの「使う」を押すと、ここに表示されます。</template>
                <template v-else>検索する言葉を変えるか、条件を解除してください。</template>
                <template #action>
                    <AppButton data-test="plugins-clear-filters" @click="clear"><AppIcon name="x" />条件を解除</AppButton>
                </template>
            </EmptyState>
        </AppPanel>

        <AppPanel v-for="group in groups" :key="group.key" :title="group.label" :description="group.description" data-test="plugin-group" :data-category="group.key">
            <template #actions>
                <AppText as="span" tone="soft" size="sm" nowrap data-test="plugin-group-count">{{ group.plugins.length }} 個</AppText>
            </template>
            <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" :aria-label="group.label">
                <li v-for="plugin in group.plugins" :key="plugin.name" class="flex min-w-0">
                    <PluginCard
                        :title="plugin.title"
                        :description="plugin.description"
                        :to="pageOf(plugin)"
                        :icon="CATEGORY_ICONS[plugin.category] ?? 'plug'"
                        class="w-full"
                        data-test="tenant-plugin-row"
                        :data-plugin="plugin.name"
                    >
                        <template #status>
                            <StatusBadge :tone="plugin.enabled ? 'ok' : 'neutral'" data-test="tenant-plugin-state">{{ plugin.enabled ? '使う' : '使わない' }}</StatusBadge>
                            <StatusBadge v-if="settingsState(plugin)" :tone="settingsState(plugin).tone" data-test="tenant-plugin-settings-state">{{ settingsState(plugin).label }}</StatusBadge>
                        </template>
                        <template v-if="canEdit" #actions>
                            <AppButton
                                size="sm"
                                :variant="plugin.enabled ? 'ghost' : 'secondary'"
                                :disabled="pending !== null"
                                :loading="pending === plugin.name"
                                :aria-label="`「${plugin.title}」を${plugin.enabled ? '使わない' : '使う'}`"
                                data-test="tenant-plugin-toggle"
                                @click="toggle(plugin)"
                            >{{ plugin.enabled ? '使わない' : '使う' }}</AppButton>
                        </template>
                    </PluginCard>
                </li>
            </ul>
        </AppPanel>
    </template>
</template>
