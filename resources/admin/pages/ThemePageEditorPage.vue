<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRoute } from 'vue-router';
import { api, ApiError, describe } from '../api.js';
import { flatten } from '../categories.js';
import { errorsOf } from '../format.js';
import { canInTenant, storefrontUrl, tenantName } from '../tenants.js';
import AddSectionPicker from '../components/sections/AddSectionPicker.vue';
import PagePreview from '../components/sections/PagePreview.vue';
import PageSectionList from '../components/sections/PageSectionList.vue';
import RebuildFromTemplate from '../components/sections/RebuildFromTemplate.vue';
import SectionSettings from '../components/sections/SectionSettings.vue';
import ThemeStylePanel from '../components/sections/ThemeStylePanel.vue';
import AppButton from '../components/ui/AppButton.vue';
import AppIcon from '../components/ui/AppIcon.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppText from '../components/ui/AppText.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import EditorLayout from '../components/ui/EditorLayout.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import SegmentedTabs from '../components/ui/SegmentedTabs.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';

// ページの編集の画面（ADR 0039 区切り 2。見本の A＝左にセクションの一覧・真ん中にプレビューの形）。メニューを出さず画面の全体を使う。
// 左にセクションの一覧（足す・並べ替え・隠す・セクションの中のブロック）、真ん中にショップと同じ描き方の本物のプレビュー（押すとそのセクションを選ぶ）、右に選んだセクションの設定。
// - 下書きは変えて少し待つと自動で保存する（並びを丸ごと PUT …/pages/home/draft。足したセクションは ID を付けずに送り、答えの ID を写す）。
//   保存のたびにプレビューを読み直す。先に別の操作で保存されていたら 409 で止め、読み込み直すまで保存しない
// - 「公開する」は確認してから（保存していない変更があれば先に保存）。公開はバージョンごと（すべてのページ）
// - 決まった並びのテーマ（page.fixed。今の公式テーマ）では、足す・消す・並べ替えを出さない（値と隠す・出すだけ）
// - 変えるのは運用の段階から（閲覧だけの人には欄を押せなくし、保存も公開もしない。守るのはサーバー）
// - プレビューはショップのホストの署名つきのリンク（30 分）。切れる前に作り直す。枠を許す CSP はこの画面の殻にだけ付く（router.js で文書ごと読み直して入る）
// - 「雛形から作り直す」: 選んだ雛形の並びに置き換える（POST …/template。確認してから。下書きだけで、公開するまでショップは変わらない）。
//   保存していない変更は置き換えるので送らず、送っている途中の保存を待ってから最新の改訂で作り直す
// - ページはトップ（home）か自由なページ（page-{ID}。ADR 0039 区切り 3）。自由なページの「公開する」は、そのページも公開の状態にする。
//   戻る先は自由なページなら「ページ」の一覧、トップなら「テーマの中身」
// - 左の欄の上に「セクション」「見た目」の札（ADR 0097。見た目を宣言したテーマだけ）。見た目（色の組・主な色・字の組・角の丸み）は変えて少し待つと
//   下書きに保存し（PUT …/themes/{テーマ}/style/draft。セクションの保存と同じ順番待ちで、改訂を順に進める）、プレビューの CSS だけを読み直させる（ページは読み直さない）。
//   リンクの期限が近ければ枠ごと読み直す。読みにくいかもしれない組み合わせはサーバーの通知を出す（止めない）。公開はセクションといっしょ（バージョンごと）
const props = defineProps({
    tenant: { type: String, required: true },
    page: { type: String, required: true },
});
const route = useRoute();

const THEME_SHAPE = /^[a-z][a-z0-9-]{1,31}$/;
const SAVE_AFTER_MS = 800;
const RENEW_BEFORE_MS = 3 * 60 * 1000;

const loaded = ref(null);
const loadError = ref(null);
const sections = ref([]);
const selection = ref({ key: null, block: null });
const snapshot = ref('');
const context = ref({ media: [], categories: [], listings: [], mediaBase: '', htmlBase: '' });
const errors = ref({});
const error = ref(null);
const message = ref(null);
const conflict = ref(false);
const saving = ref(false);
const saveFailed = ref(false);
const publishing = ref(false);
const rebuilding = ref(false);
const device = ref('desktop');
const preview = ref(null);
const reload = ref(0);
// 見た目（ADR 0097）: { declaration, values, warnings, saved }（見た目を宣言しないテーマは declaration が null）
const look = ref(null);
const lookErrors = ref({});
const lookSignal = ref(null);
const tab = ref('sections');
const tabs = [{ value: 'sections', label: 'セクション' }, { value: 'look', label: '見た目' }];
let serial = 0;
let timer = null;
let lookTimer = null;

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}`);
const themeName = ref(null);
const pageApi = computed(() => `${base.value}/themes/${themeName.value}/pages/${encodeURIComponent(props.page)}`);
const free = computed(() => loaded.value?.page.free === true);
const canEdit = computed(() => canInTenant(props.tenant, 'operate'));
const fixed = computed(() => loaded.value?.page.fixed === true);
const schema = computed(() => loaded.value?.schema ?? {});
const selectedIndex = computed(() => sections.value.findIndex((s) => s.key === selection.value.key));
const selected = computed(() => sections.value[selectedIndex.value] ?? null);
// 自由なページは公開しているときだけ、そのページを開く（下書きのページはショップでは見つからない）
const shopUrl = computed(() => {
    const shopPage = loaded.value?.shop_page;

    return shopPage ? (shopPage.status === 'published' ? shopPage.url : null) : storefrontUrl(props.tenant);
});
const devices = [{ value: 'desktop', label: 'パソコン' }, { value: 'mobile', label: 'スマホ' }];

const emptyValues = (fields) => Object.fromEntries(Object.entries(fields ?? {}).map(([key, field]) => [key, field.type === 'categories' ? [] : null]));

// サーバーのセクションを、編集の形（欄をそろえ、並べ替えのための鍵を付ける）に
function local(section) {
    const spec = schema.value[section.type] ?? { fields: {}, blocks: {} };

    return {
        key: section.id ?? `new-${++serial}`,
        id: section.id ?? null,
        type: section.type,
        pattern: section.pattern,
        hidden: section.hidden ?? false,
        values: { ...emptyValues(spec.fields), ...(section.values ?? {}) },
        items: (section.items ?? []).map((item) => ({ ...item })),
        blocks: (section.blocks ?? []).map((block) => ({
            key: `block-${++serial}`,
            type: block.type,
            values: { ...emptyValues(spec.blocks?.[block.type]?.fields), ...(block.values ?? {}) },
        })),
    };
}

// 送る形（鍵は送らない。ID の無いセクションは足したセクション）
function payload() {
    return sections.value.map((s) => ({
        ...(s.id ? { id: s.id } : {}),
        type: s.type,
        pattern: s.pattern,
        hidden: s.hidden,
        values: s.values,
        items: s.items,
        blocks: s.blocks.map((b) => ({ type: b.type, values: b.values })),
    }));
}

const dirty = computed(() => loaded.value !== null && JSON.stringify(payload()) !== snapshot.value);
const lookDirty = computed(() => look.value?.declaration != null && JSON.stringify(look.value.values) !== look.value.saved);

const state = computed(() => {
    const version = loaded.value?.version;

    if (conflict.value) return { tone: 'danger', label: '保存を停止中' };
    if (saving.value) return { tone: 'neutral', label: '保存中…' };
    if (saveFailed.value) return { tone: 'danger', label: '保存できませんでした' };
    if (dirty.value || lookDirty.value) return { tone: 'neutral', label: '保存していない変更' };
    if (!version || version.published_at === null) return { tone: 'warn', label: '下書き（まだ公開していません）' };

    return version.unpublished_changes ? { tone: 'warn', label: '下書き（公開していない変更あり）' } : { tone: 'ok', label: '公開中の内容と同じ' };
});

function fill(data) {
    loaded.value = data;
    const keep = selectedIndex.value;
    sections.value = data.sections.map(local);
    snapshot.value = JSON.stringify(payload());
    const next = sections.value[Math.max(0, Math.min(keep, sections.value.length - 1))] ?? null;
    selection.value = { key: next?.key ?? null, block: null };
}

async function issuePreview() {
    try {
        preview.value = await api('POST', `${pageApi.value}/preview`);
    } catch (e) {
        preview.value = null;
        error.value = describe(e);
    }
}

// 保存のたびに読み直す。リンクの期限が近ければ作り直す
async function refreshPreview() {
    if (!preview.value || new Date(preview.value.expires_at).getTime() - Date.now() < RENEW_BEFORE_MS) {
        await issuePreview();
    }

    reload.value++;
}

async function load() {
    loadError.value = null;
    error.value = null;
    conflict.value = false;
    saveFailed.value = false;
    errors.value = {};

    try {
        // 編集するテーマ（?theme=。無ければショップで使っているテーマ）
        const asked = typeof route.query.theme === 'string' && THEME_SHAPE.test(route.query.theme) ? route.query.theme : null;
        if (asked === null) {
            const settings = await api('GET', `${base.value}/shop-settings`);
            themeName.value = settings.theme.chosen ?? settings.theme.default;
        } else {
            themeName.value = asked;
        }

        const [data, media, categories, listings, style] = await Promise.all([
            api('GET', pageApi.value),
            api('GET', `${base.value}/shop-media`),
            api('GET', `${base.value}/categories`),
            api('GET', `${base.value}/listings?status=active&per_page=100&sort=title`),
            api('GET', `${base.value}/themes/${themeName.value}/style`),
        ]);
        context.value = { media: media.items, categories: flatten(categories.categories), listings: listings.listings, mediaBase: `${base.value}/shop-media`, htmlBase: `${base.value}/section-html` };
        fill(data);
        clearTimeout(lookTimer);
        look.value = { declaration: style.style, values: style.values, warnings: style.warnings, saved: JSON.stringify(style.values) };
        lookErrors.value = {};
        await refreshPreview();
    } catch (e) {
        if (loaded.value === null) {
            loadError.value = e;
        } else {
            error.value = describe(e);
        }
    }
}

watch(() => [props.tenant, props.page], load, { immediate: true });

function failed(e) {
    if (e instanceof ApiError && e.status === 409) {
        conflict.value = true;
        error.value = e.body?.message ?? describe(e);

        return;
    }

    errors.value = errorsOf(e);
    error.value = Object.keys(errors.value).length > 0 ? '入力を確認してください。誤りのあるセクションに赤いマークを付けています。' : describe(e);
}

// 送っている途中の保存（公開・画面を離れる時は、これを待ってから最新の改訂で続ける）
let inflight = null;

// 下書きに保存する。送っている途中なら、それを待ってから（まだ変えた分があれば）続けて保存する。
// 送った後に変えた分は、答えで上書きしない（足したセクションの ID だけを写す）
function save() {
    if (inflight) {
        return inflight.then(() => (dirty.value ? save() : undefined));
    }

    inflight = send().finally(() => {
        inflight = null;
    });

    return inflight;
}

async function send() {
    if (!canEdit.value || conflict.value || !loaded.value) {
        return;
    }

    const sent = payload();
    const keys = sections.value.map((s) => s.key);
    const version = loaded.value.version;
    saving.value = true;
    saveFailed.value = false;

    try {
        const data = await api('PUT', `${pageApi.value}/draft`, { version_id: version?.id ?? null, revision: version?.revision ?? 0, sections: sent });
        loaded.value = { ...loaded.value, version: data.version };
        data.sections.forEach((saved, i) => {
            const section = sections.value.find((s) => s.key === keys[i]);
            if (section && !section.id) section.id = saved.id;
        });
        // 保存した形を覚える（payload と同じく id を先頭に。送った後に変えていなければ「保存していない変更」は無くなる）
        snapshot.value = JSON.stringify(sent.map(({ id, ...rest }, i) => ({ id: data.sections[i]?.id ?? id, ...rest })));
        errors.value = {};
        error.value = null;
        await refreshPreview();
    } catch (e) {
        saveFailed.value = true;
        failed(e);
    } finally {
        saving.value = false;
    }
}

// 見た目（ADR 0097）を下書きに保存する。セクションの保存と同じ順番待ち（inflight）に並べ、送っている途中なら待ってから最新の改訂で送る
function saveLook() {
    if (inflight) {
        return inflight.then(() => (lookDirty.value ? saveLook() : undefined));
    }

    inflight = sendLook().finally(() => {
        inflight = null;
    });

    return inflight;
}

async function sendLook() {
    if (!canEdit.value || conflict.value || !loaded.value || !lookDirty.value) {
        return;
    }

    const sent = { ...look.value.values };
    const version = loaded.value.version;
    saving.value = true;
    saveFailed.value = false;

    try {
        const data = await api('PUT', `${base.value}/themes/${themeName.value}/style/draft`, { version_id: version?.id ?? null, revision: version?.revision ?? 0, style: sent });
        loaded.value = { ...loaded.value, version: data.version };
        // 送った後に変えた分は上書きしない（保存した形だけを覚える）
        look.value = { ...look.value, warnings: data.warnings, saved: JSON.stringify(data.values) };
        lookErrors.value = {};
        error.value = null;

        // リンクの期限が近ければ枠ごと読み直す。ほかは枠の中へ通知して CSS だけを読み直させる
        const renew = !preview.value || new Date(preview.value.expires_at).getTime() - Date.now() < RENEW_BEFORE_MS;
        if (renew) await refreshPreview();
        else lookSignal.value = { revision: data.version.revision, font: data.values.font, radius: data.values.radius };
    } catch (e) {
        saveFailed.value = true;

        if (e instanceof ApiError && e.status === 422) {
            lookErrors.value = errorsOf(e);
            error.value = '見た目の値を確認してください。';
        } else {
            failed(e);
        }
    } finally {
        saving.value = false;
    }
}

function changeLook(values) {
    look.value = { ...look.value, values };

    if (canEdit.value && !conflict.value) {
        clearTimeout(lookTimer);
        lookTimer = setTimeout(() => (publishing.value ? changeLook(look.value.values) : saveLook()), SAVE_AFTER_MS / 2);
    }
}

function schedule() {
    clearTimeout(timer);
    timer = setTimeout(() => {
        // 公開している間は保存しない（公開の後に続ける。改訂がずれて 409 にならないように）
        if (publishing.value) {
            schedule();
        } else if (dirty.value) {
            save();
        }
    }, SAVE_AFTER_MS);
}

// 変えたら少し待って自動で保存する（打っている間は待つ）
watch(() => JSON.stringify(payload()), () => {
    if (dirty.value && canEdit.value && !conflict.value) {
        schedule();
    }
});

onBeforeUnmount(() => [timer, lookTimer].forEach(clearTimeout));

// 画面を離れる時は、待っている分と送っている途中の分を保存しきってから。保存できなければ確認する
async function flush() {
    clearTimeout(timer);
    clearTimeout(lookTimer);

    if (inflight) {
        await inflight;
    }

    if (dirty.value && canEdit.value && !conflict.value) {
        await save();
    }

    if (lookDirty.value && canEdit.value && !conflict.value) {
        await saveLook();
    }

    return (!dirty.value && !lookDirty.value) || !canEdit.value;
}

onBeforeRouteLeave(async () => (await flush()) || window.confirm('保存できていない変更があります。この画面を離れますか？'));

// 同じ経路のまま別の店へ移るときは、文書ごと読み直す（枠を許す CSP はその店の殻にだけ付くため）
onBeforeRouteUpdate((to, from) => {
    if (to.params.tenant === from.params.tenant) {
        return true;
    }

    window.location.assign(to.fullPath);

    return false;
});

// タブを閉じる・読み直す時に保存していない変更があれば、ブラウザの確認を出す
function warnUnsaved(event) {
    if ((dirty.value || lookDirty.value || inflight) && canEdit.value) {
        event.preventDefault();
    }
}

onMounted(() => window.addEventListener('beforeunload', warnUnsaved));
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnUnsaved));

async function publish() {
    publishing.value = true;
    message.value = null;
    error.value = null;

    try {
        // 待っている保存は今すぐ、送っている途中の保存は待ってから（最新の改訂で公開する）。見た目も先に保存する
        clearTimeout(timer);
        clearTimeout(lookTimer);
        if (inflight) {
            await inflight;
        }
        if (lookDirty.value) {
            await saveLook();
        }
        if (saveFailed.value || conflict.value || lookDirty.value) return;
        // 保存の間にさらに打った分も入れる（数回まで。打ち続けていれば公開しない）
        for (let i = 0; i < 3 && (dirty.value || !loaded.value.version) && !saveFailed.value && !conflict.value; i++) {
            await save();
        }
        if (saveFailed.value || conflict.value || dirty.value) return;

        const version = loaded.value.version;
        const data = await api('POST', `${pageApi.value}/publish`, { version_id: version.id, revision: version.revision });
        loaded.value = { ...loaded.value, version: data.version };
        if (data.shop_page) loaded.value.shop_page = data.shop_page;
        message.value = '公開しました。ショップに反映されています。';
    } catch (e) {
        failed(e);
    } finally {
        publishing.value = false;
    }
}

// 雛形から作り直す。待っている保存は捨てる（置き換えるので）。送っている途中の保存は待ってから、最新の改訂で
async function applyTemplate(name) {
    rebuilding.value = true;
    message.value = null;
    error.value = null;

    try {
        clearTimeout(timer);
        if (inflight) {
            await inflight;
        }
        if (conflict.value) return;

        const version = loaded.value.version;
        const data = await api('POST', `${pageApi.value}/template`, { version_id: version?.id ?? null, revision: version?.revision ?? 0, template: name });
        selection.value = { key: null, block: null };
        fill(data);
        errors.value = {};
        saveFailed.value = false;
        message.value = '雛形の並びに作り直しました（下書き）。写真と文を差し替えてから、公開してください。';
        await refreshPreview();
    } catch (e) {
        failed(e);
    } finally {
        rebuilding.value = false;
    }
}

// セクションを足す（選んでいるセクションの後ろへ。何も選んでいなければ最後へ）。
// テーマが見本（example・example_blocks）を宣言していれば、その値とブロックを入れて出す（写しにして、見本そのものは変えない）
function addSection({ type, pattern }) {
    const spec = schema.value[type] ?? {};
    const example = JSON.parse(JSON.stringify({ values: spec.example?.values ?? {}, items: spec.example?.items ?? [], blocks: spec.example_blocks ?? [] }));
    const section = local({ type, pattern, hidden: false, ...example });
    const at = selectedIndex.value < 0 ? sections.value.length : selectedIndex.value + 1;
    const next = [...sections.value];
    next.splice(at, 0, section);
    sections.value = next;
    selection.value = { key: section.key, block: null };
}

function removeSelected() {
    const index = selectedIndex.value;
    sections.value = sections.value.filter((_, i) => i !== index);
    const next = sections.value[Math.min(index, sections.value.length - 1)] ?? null;
    selection.value = { key: next?.key ?? null, block: null };
}

// セクションの試し見。「＋ セクションを足す」で指した種類とパターンを、足す位置（選んでいるセクションの後ろ）に仮に出す。
// 枠の中には保存してあるセクションしか無いので、位置は「前にあるセクションの ID（近い順）」と「後ろにあるセクションの ID（近い順）」で伝える（隠したセクション・まだ保存していないセクションは枠の中に無いので、枠の中の部品が見つかるものを使う）
const trying = ref(null);
const trial = computed(() => {
    if (trying.value === null) {
        return null;
    }

    const at = selectedIndex.value < 0 ? sections.value.length : selectedIndex.value + 1;
    const ids = (list) => list.filter((s) => s.id && !s.hidden).map((s) => s.id);

    return { ...trying.value, after: ids(sections.value.slice(0, at)).reverse(), before: ids(sections.value.slice(at)) };
});

// プレビューで押したセクションを選ぶ
function selectFromPreview(id) {
    const section = sections.value.find((s) => s.id === id);
    if (section) selection.value = { key: section.key, block: null };
}

function uploaded(item) {
    context.value.media = [item, ...context.value.media];
}
</script>

<template>
    <ErrorState v-if="loadError" :error="loadError" />
    <LoadingText v-else-if="loaded === null" />
    <EditorLayout
        v-else
        :title="`${loaded.page.label}の編集`"
        :subtitle="`${tenantName(tenant)}・テーマ「${loaded.theme.label}」${loaded.theme.active ? '' : '（ショップでは使っていません）'}`"
        start-label="セクションの並び"
        end-label="選んだセクションの設定"
    >
        <template #back>
            <AppButton v-if="free" size="sm" variant="ghost" :to="{ name: 'shop-pages', params: { tenant } }" data-test="editor-back"><AppIcon name="arrow-left" />ページの一覧に戻る</AppButton>
            <AppButton v-else size="sm" variant="ghost" :to="{ name: 'theme-content', params: { tenant } }" data-test="editor-back"><AppIcon name="arrow-left" />デザインに戻る</AppButton>
        </template>
        <template #bar>
            <StatusBadge :tone="state.tone" data-test="draft-state">{{ state.label }}</StatusBadge>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <SegmentedTabs v-model="device" :items="devices" label="プレビューの幅" />
                <AppButton v-if="shopUrl" size="sm" :href="shopUrl" data-test="open-storefront"><AppIcon name="external" />ショップで見る</AppButton>
                <!-- 公開した後（改訂が変わった後）は問いを閉じた形に戻す -->
                <ConfirmButton
                    v-if="canEdit"
                    :key="`publish-${loaded.version?.published_at ?? 'none'}`"
                    label="公開する"
                    confirm-label="公開する"
                    variant="primary"
                    size="sm"
                    :disabled="publishing || saving || conflict || (!dirty && !lookDirty && loaded.version?.unpublished_changes === false && loaded.shop_page?.status !== 'draft')"
                    :loading="publishing"
                    test="publish-page"
                    @confirm="publish"
                >
                    このテーマのすべてのページの下書きを、ショップに公開します。<template v-if="free">このページも公開します（URL: /pages/{{ loaded.shop_page?.slug }}）。</template>
                </ConfirmButton>
            </div>
        </template>

        <template #notices>
            <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
            <AppNotice v-if="error" :tone="conflict ? 'warning' : 'error'">
                <span>{{ error }}</span>
                <div v-if="conflict">
                    <AppButton size="sm" data-test="reload-page" @click="load">最新の内容を読み込み直す（保存していない変更は消えます）</AppButton>
                </div>
            </AppNotice>
            <AppNotice v-if="!canEdit" tone="info" :live="false">閲覧のみです。内容を変更できるのは「運用」以上の権限のある人だけです。</AppNotice>
            <AppNotice v-else-if="fixed" tone="info" :live="false">このテーマはセクションの並びが決まっています。変えられるのは、各セクションの内容と、表示・非表示だけです。</AppNotice>
        </template>

        <template #start>
            <SegmentedTabs v-if="look?.declaration" v-model="tab" :items="tabs" label="左の欄の表示" />
            <ThemeStylePanel
                v-if="tab === 'look' && look?.declaration"
                :model-value="look.values"
                :declaration="look.declaration"
                :warnings="look.warnings"
                :errors="lookErrors"
                :disabled="!canEdit || conflict"
                @update:model-value="changeLook"
            />
            <template v-else>
                <PageSectionList v-model:sections="sections" v-model:selection="selection" :schema="schema" :fixed="fixed" :disabled="!canEdit || conflict || rebuilding" :errors="errors" />
                <AddSectionPicker v-if="!fixed && canEdit" :schema="schema" :page="loaded.page" :sections="sections" :disabled="conflict || rebuilding" @add="addSection" @try="trying = $event" />
                <RebuildFromTemplate v-if="!fixed && canEdit" :templates="loaded.templates ?? []" :count="sections.length" :disabled="conflict" :busy="rebuilding" @apply="applyTemplate" />
            </template>
        </template>

        <PagePreview :url="preview?.url ?? null" :reload="reload" :device="device" :selected="selected?.id ?? null" :trial="trial" :look="lookSignal" :title="`${loaded.page.label}のプレビュー（下書き）`" @select="selectFromPreview" />
        <AppText size="xs" tone="soft">下書きのプレビューです。セクションを押すと、そのセクションを選びます。リンクとボタンはここでは動きません。</AppText>

        <template #end>
            <template v-if="selected">
                <SectionSettings
                    :key="selected.key"
                    v-model="sections[selectedIndex]"
                    :spec="schema[selected.type]"
                    :index="selectedIndex"
                    :block="selection.block"
                    :errors="errors"
                    :context="context"
                    :disabled="!canEdit || conflict"
                    @uploaded="uploaded"
                />
                <div v-if="!fixed && canEdit && selection.block === null" class="flex flex-wrap items-center gap-2">
                    <!-- 問いを開いた形は、選んでいるセクションとその位置に結びつける。部品を使い回すと、消した後に次に選ばれたセクション（フッターなど）の
                         「消す」が開いたまま残り、もう 1 回押すと続けて消えた。セクションを選び替える・消す・並べ替えると、閉じた形から作り直す -->
                    <ConfirmButton :key="`remove-${selected.key}-${selectedIndex}`" label="このセクションを削除" confirm-label="削除" :disabled="conflict" test="remove-section" @confirm="removeSelected">
                        セクションの内容も一緒に削除されます。
                    </ConfirmButton>
                </div>
            </template>
            <EmptyState v-else title="セクションを選んでください">左の一覧か、中央のプレビューでセクションを押すと、ここに設定が表示されます。</EmptyState>
        </template>
    </EditorLayout>
</template>
