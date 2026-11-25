<script setup>
import { computed, ref, watch } from 'vue';
import { api, describe } from '../../api.js';
import { errorsOf, firstError } from '../../format.js';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import InsetSection from '../../components/ui/InsetSection.vue';
import SettingFields from '../../components/ui/SettingFields.vue';
import ListingAppFiles from './ListingAppFiles.vue';

// 商品の画面の「App」の節（ADR 0050）: この店で使っている App のうち、商品ごとの欄を持つもの（定期便の「定期でも買える・周期・値引き」など）。
// 欄は App が宣言した核の型（文・切り替え・選ぶ）で、描くのは核の部品（SettingFields）。App の画面のコードは読み込まない。
// 保存は商品の画面の下の「保存する」でまとめて: 変えた App ごとに 1 つの手順（step）を親へ渡す。App の欄は商品のバージョンを進めない。
// 欄を持つ App が無い店・まだ作っていない商品（ID が無い）では、節を出さない（visible が偽）。
// ファイルを置く App（files。App「ダウンロード販売」の売るファイル）は、欄の下にファイルの並びと「ファイルを上げる」を出す（すぐ反映。ListingAppFiles）
const props = defineProps({
    tenantBase: { type: String, required: true },
    listing: { type: Object, default: null },
    // 閲覧だけの人（運用より下）は値を見るだけ（見た目だけ。サーバーも 403 で断る）
    disabled: { type: Boolean, default: false },
});

// 読んだ App の欄（null は読み込み中か、まだ商品が無い）
const apps = ref(null);
const loadError = ref(null);
// App の名前 => 入力中の値 / 読んだ（保存した）ときの値 / 誤り
const drafts = ref({});
const loaded = ref({});
const errors = ref({});

function draftOf(app) {
    return Object.fromEntries(app.fields.map((spec) => {
        const value = app.values[spec.key];

        return [spec.key, spec.type === 'toggle' ? (value ? 'on' : 'off') : (value ?? '')];
    }));
}

function valuesOf(app) {
    const draft = drafts.value[app.plugin];

    return Object.fromEntries(app.fields.map((spec) => [spec.key, spec.type === 'toggle' ? draft[spec.key] === 'on' : (draft[spec.key] === '' ? null : draft[spec.key])]));
}

// ファイルを上げた・消した後: その App のファイルの並びだけを置き換える（入力中の欄の値はそのまま）
function setFiles(app) {
    if (app) {
        apps.value = (apps.value ?? []).map((a) => (a.plugin === app.plugin ? { ...a, files: app.files, note: app.note } : a));
    }
}

// 1 つの App を、サーバーの値にそろえる（読み込み・保存の後）
function setApp(app) {
    apps.value = (apps.value ?? []).map((a) => (a.plugin === app.plugin ? app : a));
    drafts.value = { ...drafts.value, [app.plugin]: draftOf(app) };
    loaded.value = { ...loaded.value, [app.plugin]: draftOf(app) };
}

watch(() => props.listing?.id ?? null, async (id) => {
    apps.value = null;
    loadError.value = null;
    errors.value = {};

    if (id === null) {
        return;
    }

    try {
        const list = (await api('GET', `${props.tenantBase}/listings/${encodeURIComponent(id)}/apps`)).apps;
        apps.value = list;
        drafts.value = Object.fromEntries(list.map((app) => [app.plugin, draftOf(app)]));
        loaded.value = Object.fromEntries(list.map((app) => [app.plugin, draftOf(app)]));
    } catch (e) {
        loadError.value = describe(e);
    }
}, { immediate: true });

const changedApps = computed(() => (apps.value ?? []).filter((app) => JSON.stringify(drafts.value[app.plugin]) !== JSON.stringify(loaded.value[app.plugin])));
const dirty = computed(() => changedApps.value.length > 0);
const visible = computed(() => loadError.value !== null || (apps.value?.length ?? 0) > 0);

// 保存の手順（親の「保存する」から呼ぶ）。誤り（422）は欄の下に出して、投げ直す（親が止めて通知する）
function steps() {
    return changedApps.value.map((app) => ({
        label: app.title,
        section: 'listing-apps',
        run: async (listing) => {
            errors.value = { ...errors.value, [app.plugin]: {} };

            try {
                setApp((await api('PUT', `${props.tenantBase}/listings/${encodeURIComponent(listing.id)}/apps/${encodeURIComponent(app.plugin)}`, { values: valuesOf(app) })).app);
            } catch (e) {
                errors.value = { ...errors.value, [app.plugin]: errorsOf(e) };
                throw e;
            }

            // 商品は変わらない（バージョンも進まない）
            return listing;
        },
    }));
}

defineExpose({ dirty, steps, visible });
</script>

<template>
    <AppPanel v-if="visible" title="App" description="この店で使っている App の、この商品の設定です。" data-test="listing-apps">
        <AppNotice v-if="loadError" tone="error">App の欄を読み込めませんでした。{{ loadError }}</AppNotice>
        <InsetSection v-for="app in apps ?? []" :key="app.plugin" :title="app.title" :data-plugin="app.plugin" data-test="listing-app">
            <AppNotice v-if="app.note" tone="info" data-test="listing-app-note">{{ app.note }}</AppNotice>
            <SettingFields
                v-model="drafts[app.plugin]"
                :fields="app.fields"
                :errors="errors[app.plugin] ?? {}"
                :disabled="disabled"
                :toggle-labels="['はい', 'いいえ']"
                :test-prefix="`app-${app.plugin}`"
            />
            <AppNotice v-if="firstError(errors[app.plugin] ?? {}, 'values')" tone="error">{{ firstError(errors[app.plugin] ?? {}, 'values') }}</AppNotice>
            <ListingAppFiles v-if="app.files && listing?.id" :tenant-base="tenantBase" :listing-id="listing.id" :app="app" :disabled="disabled" @update:app="setFiles" />
        </InsetSection>
    </AppPanel>
</template>
