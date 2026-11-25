<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../api.js';
import { loadAppPages } from '../appPages.js';
import { firstError, formatDate } from '../format.js';
import { useOperation } from '../operation.js';
import { crumbsFor, titleOf } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { canInTenant, tenantName } from '../tenants.js';
import { session } from '../session.js';
import AppButton from '../components/ui/AppButton.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppText from '../components/ui/AppText.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import FormActions from '../components/ui/FormActions.vue';
import InsetSection from '../components/ui/InsetSection.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import SettingFields from '../components/ui/SettingFields.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';

// 店のプラグインの 1 つ（ADR 0025・0034）。この店で使うかどうかと、App の設定。一覧は TenantPluginsPage。
// 使う・使わないも設定も、運用の段階で、パスキーで確認してから（サーバーの再確認）。
// 設定は「使わない」のままでも入れて保存できる（ID を先に入れてから使えるように）。動くのは「使う」にしてから。
// 欄はマニフェストの宣言から作る（文字・切り替え・選ぶ）。値はサーバーが宣言どおりに確認する。
// 秘密の項目は中身を受け取らない（入っているかだけ）。空のまま保存すれば今の値のまま
// 読むのは一覧と同じ API（1 つだけを返す API は足さない。入っているプラグインは多くても数十）
// 止めた App のうち、データを消す口を持つものは「この App のデータを消す」を出す（ADR 0092。オーナーだけ。サーバーも RequireOwner と直前の再確認で断る）
const props = defineProps({
    tenant: { type: String, required: true },
    plugin: { type: String, required: true },
});
// 読んだプラグイン。null は読み込み中、false は入っていない（知らない名前）
const current = ref(null);
const loadError = ref(null);
// この店が同意の帯を出すか（出さない店では、同意の種類のある App もショップを開いたときから動く）
const consentBanner = ref(false);
// 入力中の値（key => value）
const draft = ref({});
const { pending, error, errors, message, run } = useOperation();

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/plugins`);
const path = computed(() => `${base.value}/${encodeURIComponent(props.plugin)}`);
const title = computed(() => (current.value ? current.value.title : titleOf('tenant-plugin')));
const crumbs = computed(() => crumbsFor('tenant-plugin', props.tenant, tenantName(props.tenant), title.value));
const canEdit = computed(() => canInTenant(props.tenant, 'operate'));
// データを消す欄を出すか: 止めていて、口を持つ App で、オーナー（ボタンを隠すのは見た目のためだけ）
const canPurge = computed(() => Boolean(current.value && !current.value.enabled && current.value.purgeable && session.operator?.role === 'owner'));
// 欄の並び（宣言は「名前 => 宣言」で来る。欄を描くのは核の部品 SettingFields で、商品の画面の App の欄と同じ）
const fields = computed(() => (current.value?.settings ? Object.entries(current.value.settings.schema).map(([key, spec]) => ({ key, ...spec })) : []));
// 写真の欄（ロゴなど）があれば、ショップの写真を読む（選ぶ・上げるは核の写真の欄の部品）
const mediaBase = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/shop-media`);
const media = ref([]);

watch(fields, async (now) => {
    if (!now.some((spec) => spec.type === 'media') || media.value.length > 0) {
        return;
    }

    try {
        media.value = (await api('GET', mediaBase.value)).items;
    } catch {
        // 写真が読めなくても、ほかの欄は使える（選ぶ欄に写真が並ばないだけ）
    }
});
const listRoute = computed(() => ({ name: 'tenant-plugins', params: { tenant: props.tenant } }));
const PAGE_LABELS = { general: '通常のページ', purchase: '購入完了のページ' };
// 主な操作（primary）は 1 画面に 1 つ: 設定のあるプラグインは「設定を保存する」、無いものは「使う」

function fill(result) {
    const list = result.plugins;
    consentBanner.value = result.consent_banner === true;
    const plugin = list.find((p) => p.name === props.plugin) ?? false;
    current.value = plugin;
    draft.value = plugin && plugin.settings
        ? Object.fromEntries(Object.entries(plugin.settings.schema).map(([key, spec]) => [key, spec.secret ? '' : (spec.type === 'toggle' ? (plugin.settings.values[key] ? 'on' : 'off') : (plugin.settings.values[key] ?? ''))]))
        : {};
}

watch(() => [props.tenant, props.plugin], async () => {
    current.value = null;
    loadError.value = null;

    try {
        fill(await api('GET', base.value));
    } catch (e) {
        loadError.value = e;
    }
}, { immediate: true });

function toggle() {
    const plugin = current.value;
    const enabled = !plugin.enabled;

    return run('toggle', () => api('PUT', path.value, { enabled }), {
        done: `「${plugin.title}」を${enabled ? '使い始めました' : '使わないようにしました'}。`,
        // App の画面（ADR 0051）を持つ App なら、メニューの項目が出る・消える
        then: async (result) => {
            fill(result);
            await loadAppPages(props.tenant, { refresh: true });
        },
    });
}

function save() {
    const plugin = current.value;
    const values = Object.fromEntries(Object.entries(plugin.settings.schema).map(([key, spec]) => {
        const value = draft.value[key];
        return [key, spec.type === 'toggle' ? value === 'on' : (value === '' ? null : value)];
    }));

    return run('settings', () => api('PUT', `${path.value}/settings`, { values }), {
        done: `「${plugin.title}」の設定を保存しました。`,
        then: (result) => fill(result),
    });
}

// この店の App のデータを消す（ADR 0092）。元に戻せない。サーバーがパスキーの再確認を求めたら、useOperation が確認してから送り直す
function purge() {
    const plugin = current.value;

    return run('purge', () => api('DELETE', `${path.value}/data`), {
        done: (result) => `「${plugin.title}」のデータを削除しました（${result.purged.total} 件${result.purged.files > 0 ? `・ファイル ${result.purged.files} 個` : ''}）。${result.purged.leftover ? '削除しきれなかったファイルがあります。もう一度削除すると、残りを削除します。' : ''}`,
        then: (result) => fill(result),
    });
}

// ショップのどこで、どんなときに動くか（核の口の宣言から）
const summary = computed(() => {
    if (!current.value) {
        return null;
    }

    const pages = current.value.app.pages.map((p) => PAGE_LABELS[p] ?? p).join('・');
    const consent = current.value.app.consent.map((c) => c.label).join('・');
    // App が足すセクション（ADR 0040）
    const sections = (current.value.app.sections ?? []).map((label) => `「${label}」`).join('・');
    const parts = [];
    if (pages) parts.push(`ショップの${pages}で動きます（注文の入力・確認やご注文の状況の画面では動きません）`);
    if (sections) parts.push(`ページの編集の画面で${sections}のセクションを追加できます`);
    if (consent && consentBanner.value) {
        parts.push(`この店は同意バナーを表示するので、お客さんが「${consent}」に同意したときだけ動きます`);
    } else if (consent) {
        parts.push('この店は同意バナーを表示しないので、ショップを開いたときから動きます（設定の「同意バナー」で変更できます）');
    }
    return parts.length > 0 ? `${parts.join('。')}。` : null;
});
</script>

<template>
    <PageHeader :help="helpLink('tenant-plugin', tenant)" :title="title" :crumbs="crumbs" />
    <ErrorState v-if="loadError" :error="loadError" />
    <LoadingText v-else-if="current === null" />
    <ErrorState v-else-if="current === false" :status="404" title="App が見つかりません" data-test="plugin-not-found">
        この名前の App はインストールされていません。アドレスを確認するか、一覧から選んでください。
        <template #action>
            <AppButton :to="listRoute">App の一覧へ</AppButton>
        </template>
    </ErrorState>

    <template v-else>
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>
        <AppNotice v-if="!current.enabled" :live="false" data-test="plugin-disabled-notice">この店では使っていません。「使う」にするまで動きません。</AppNotice>

        <AppPanel title="この店では" :description="current.description" data-test="plugin-state-panel">
            <template v-if="canEdit" #actions>
                <AppButton
                    size="sm"
                    :variant="current.enabled || current.settings ? 'secondary' : 'primary'"
                    :disabled="pending !== null"
                    :loading="pending === 'toggle'"
                    data-test="tenant-plugin-toggle"
                    @click="toggle"
                >{{ current.enabled ? '使わない' : '使う' }}</AppButton>
            </template>
            <div class="flex flex-wrap items-center gap-3">
                <StatusBadge :tone="current.enabled ? 'ok' : 'neutral'" data-test="tenant-plugin-state">{{ current.enabled ? '使う' : '使わない' }}</StatusBadge>
                <AppText as="span" tone="soft">{{ current.changed_at ? `切り替えた日時: ${formatDate(current.changed_at)}` : 'まだ切り替えていません' }}</AppText>
            </div>
            <AppText v-if="summary" tone="soft">{{ summary }}</AppText>
            <AppText v-if="!canEdit" tone="soft">切り替えと設定の保存ができるのは、「運用」以上の権限のある人だけです。</AppText>
        </AppPanel>

        <AppPanel v-if="canPurge" title="この App のデータ" description="この店で、この App が持っているデータ（App の画面の記録・アップロードしたファイル・App の設定）をすべて削除します。" data-test="plugin-purge-panel">
            <template #actions>
                <ConfirmButton
                    label="この App のデータを削除"
                    confirm-label="削除"
                    :disabled="pending !== null"
                    :loading="pending === 'purge'"
                    test="plugin-purge"
                    @confirm="purge"
                >元に戻せません。後でもう一度「使う」にしても、削除したデータは戻りません。</ConfirmButton>
            </template>
            <AppText tone="soft">削除できるのは「使わない」にしている間だけです。お客さんがまだ受け取れる物（ダウンロードのリンクなど）が残っているときは削除できません。</AppText>
        </AppPanel>

        <AppPanel title="設定" data-test="plugin-settings">
            <EmptyState v-if="!current.settings" title="この App には設定がありません">「使う」にするだけで動きます。</EmptyState>
            <form v-else class="flex flex-col gap-6" @submit.prevent="save">
                <SettingFields
                    v-model="draft"
                    :fields="fields"
                    :errors="errors"
                    :secrets="current.settings.secrets"
                    :disabled="!canEdit"
                    :media="media"
                    :media-base="mediaBase"
                    test-prefix="plugin-setting"
                    @uploaded="(item) => { media = [item, ...media]; }"
                />
                <InsetSection v-if="current.app.disclosure.length > 0" title="外部への送信（ショップの「外部送信について」に表示されます）">
                    <AppText v-for="(d, i) in current.app.disclosure" :key="i" tone="soft">{{ d.company }}: {{ d.data }}（{{ d.purpose }}）</AppText>
                </InsetSection>
                <AppNotice v-if="firstError(errors, 'values')" tone="error">{{ firstError(errors, 'values') }}</AppNotice>
                <FormActions v-if="canEdit">
                    <template #meta>{{ current.settings.changed_at ? `最後の変更: ${formatDate(current.settings.changed_at)}` : 'まだ保存していません' }}</template>
                    <AppButton type="submit" variant="primary" :disabled="pending !== null" :loading="pending === 'settings'" data-test="plugin-settings-save">設定を保存する</AppButton>
                </FormActions>
            </form>
        </AppPanel>
    </template>
</template>
