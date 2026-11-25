<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { api, describe } from '../api.js';
import { errorsOf, firstError } from '../format.js';
import { crumbsFor, tabsFor, titleOf } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { canInTenant, tenantName } from '../tenants.js';
import AppButton from '../components/ui/AppButton.vue';
import AppCheckbox from '../components/ui/AppCheckbox.vue';
import AppField from '../components/ui/AppField.vue';
import AppIcon from '../components/ui/AppIcon.vue';
import AppInput from '../components/ui/AppInput.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import CellTitle from '../components/ui/CellTitle.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import FormActions from '../components/ui/FormActions.vue';
import InsetSection from '../components/ui/InsetSection.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import PageTemplatePicker from '../components/sections/PageTemplatePicker.vue';
import SeoFields from '../components/SeoFields.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';

// 自由なページ（ADR 0039 区切り 3）: 「私たちについて」・キャンペーン・お問い合わせの案内など、店の人が作るページの一覧。
// 作る・題と URL とメニューに載せるか・公開と非公開・並べ替え・ゴミ箱へ（戻せる）・ゴミ箱から完全に消す（確認してから）。中身（セクションの並び）はページの編集の画面で作る。
// 形の検査（URL の形・予約語・重なり）はサーバー（ShopPageService）が持ち、ここは誤りをそのまま見せる。変えるのは運用の段階から
// 作るときにページの雛形を選べる（白紙も）。雛形を選ぶと、ショップで使っているテーマの下書きにセクションの並びが入る（公開はしない）
const props = defineProps({ tenant: { type: String, required: true } });

const URL_PATTERN = '[a-z0-9]+(-[a-z0-9]+)*';

const data = ref(null);
const loadError = ref(null);
const error = ref(null);
const message = ref(null);
const busy = ref(false);
const form = reactive({ title: '', slug: '', in_nav: true, template: null });
const formErrors = ref({});
const editing = ref(null);
// 検索の結果に出す題と説明（ADR 0061。空なら自動）
const edit = reactive({ title: '', slug: '', in_nav: false, seo_title: '', seo_description: '' });
const editErrors = ref({});

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/shop-pages`);
const canEdit = computed(() => canInTenant(props.tenant, 'operate'));
const crumbs = computed(() => crumbsFor('shop-pages', props.tenant, tenantName(props.tenant)));
const pages = computed(() => data.value?.pages ?? []);
const templates = computed(() => data.value?.templates ?? []);
const trash = computed(() => data.value?.trash ?? []);
const full = computed(() => data.value !== null && pages.value.length + trash.value.length >= data.value.max_pages);

async function run(action, done) {
    busy.value = true;
    error.value = null;
    message.value = null;

    try {
        data.value = await action();
        message.value = done;

        return true;
    } catch (e) {
        const errors = errorsOf(e);
        error.value = Object.keys(errors).length > 0 ? Object.values(errors).flat()[0] : describe(e);

        return false;
    } finally {
        busy.value = false;
    }
}

async function load() {
    loadError.value = null;

    try {
        data.value = await api('GET', base.value);
    } catch (e) {
        loadError.value = e;
    }
}

watch(() => props.tenant, load, { immediate: true });

async function create() {
    formErrors.value = {};
    busy.value = true;
    error.value = null;
    message.value = null;

    try {
        data.value = await api('POST', base.value, { title: form.title, slug: form.slug, in_nav: form.in_nav, template: form.template });
        message.value = form.template === null
            ? 'ページを作りました（まだ下書きです）。「内容を編集」からセクションを追加し、公開してください。'
            : 'ページを作りました（まだ下書きです）。雛形のセクションが入っています。「内容を編集」で写真と文を差し替えてから、公開してください。';
        Object.assign(form, { title: '', slug: '', template: null });
    } catch (e) {
        formErrors.value = errorsOf(e);
        // 題と URL の誤りは欄の下に。ほか（雛形の中身が置けないなど）は上の通知に
        const other = Object.entries(formErrors.value).filter(([key]) => key !== 'title' && key !== 'slug').flatMap(([, list]) => list);
        error.value = Object.keys(formErrors.value).length === 0 ? describe(e) : (other[0] ?? null);
    } finally {
        busy.value = false;
    }
}

function startEdit(row) {
    editing.value = row.id;
    editErrors.value = {};
    Object.assign(edit, { title: row.title, slug: row.slug, in_nav: row.in_nav, seo_title: row.seo_title ?? '', seo_description: row.seo_description ?? '' });
}

async function saveEdit(row) {
    editErrors.value = {};
    busy.value = true;
    error.value = null;
    message.value = null;

    try {
        data.value = await api('PATCH', `${base.value}/${row.id}`, { title: edit.title, slug: edit.slug, in_nav: edit.in_nav, seo_title: edit.seo_title, seo_description: edit.seo_description });
        editing.value = null;
        message.value = '保存しました。';
    } catch (e) {
        editErrors.value = errorsOf(e);
        error.value = Object.keys(editErrors.value).length > 0 ? null : describe(e);
    } finally {
        busy.value = false;
    }
}

function setStatus(row, status) {
    run(() => api('PATCH', `${base.value}/${row.id}`, { status }), status === 'published' ? '公開しました。ショップに表示されています。' : '非公開にしました。ショップには表示されません。');
}

function move(index, step) {
    const ids = pages.value.map((p) => p.id);
    [ids[index], ids[index + step]] = [ids[index + step], ids[index]];
    run(() => api('POST', `${base.value}/order`, { ids }), '並べ替えました。');
}

function remove(row) {
    run(() => api('DELETE', `${base.value}/${row.id}`), `「${row.title}」をゴミ箱に入れました。`);
}

function purge(row) {
    run(() => api('POST', `${base.value}/${row.id}/purge`), `「${row.title}」を完全に削除しました。`);
}

function restore(row) {
    run(() => api('POST', `${base.value}/${row.id}/restore`), `「${row.title}」をゴミ箱から戻しました（非公開のページはショップに表示されません）。`);
}
</script>

<template>
    <PageHeader :help="helpLink('shop-pages', tenant)" :title="titleOf('shop-pages')" :crumbs="crumbs" :tabs="tabsFor('shop-pages', tenant)" />
    <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
    <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>

    <ErrorState v-if="loadError" :error="loadError" />
    <LoadingText v-else-if="data === null" />
    <template v-else>
        <AppText v-if="!canEdit" size="sm" tone="soft" data-test="pages-read-only">ページを変更できるのは「運用」以上の権限のある人だけです。</AppText>
        <AppPanel
            v-else
            as="form"
            title="ページを作る"
            description="「私たちについて」・キャンペーン・お問い合わせの案内などのページを作れます。内容は、ページを作った後にセクションを追加して作ります。"
            @submit.prevent="create"
        >
            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <AppField label="タイトル" hint="ページの見出しと、メニューに表示される名前" :error="firstError(formErrors, 'title')">
                    <AppInput v-model="form.title" maxlength="60" required data-test="page-title" />
                </AppField>
                <AppField label="URL" hint="ショップの /pages/ のあとに続く部分。英小文字・数字・ハイフン（例: about、spring-sale）" :error="firstError(formErrors, 'slug')">
                    <AppInput v-model="form.slug" maxlength="60" required :pattern="URL_PATTERN" autocomplete="off" data-test="page-slug" />
                </AppField>
            </div>
            <AppCheckbox v-model="form.in_nav" label="ショップのメニューに載せる（公開すると表示されます）" test="page-in-nav" />
            <PageTemplatePicker
                v-model="form.template"
                :templates="templates"
                blank
                :disabled="busy"
                title="雛形"
                hint="選ぶと、おすすめの並びでセクションが入ります。写真と文を差し替えるだけで 1 ページになります。後からセクションの追加・削除もできます。"
            />
            <FormActions>
                <AppButton type="submit" variant="primary" :disabled="busy || full" data-test="add-page"><AppIcon name="plus" />作成</AppButton>
            </FormActions>
            <AppText v-if="full" size="sm" tone="soft">ページは {{ data.max_pages }} 個まで作れます（ゴミ箱のページも数えます）。</AppText>
        </AppPanel>

        <AppPanel title="ページの一覧" description="公開したページだけがショップに表示されます。並び順はメニューの順です。">
            <EmptyState v-if="pages.length === 0" icon="file" title="まだページがありません" data-test="pages-empty">「お店について」「よくある質問」などのページを、上の「ページを作る」から作れます。</EmptyState>
            <AppTable v-else label="ページの一覧">
                <thead>
                    <tr><th>タイトル</th><th>URL</th><th>メニュー</th><th>状態</th><th><span class="sr-only">操作</span></th></tr>
                </thead>
                <tbody>
                    <template v-for="(row, index) in pages" :key="row.id">
                        <tr data-test="page-row" :data-page-slug="row.slug">
                            <td><CellTitle :label="row.title" /></td>
                            <td><AppText size="sm" mono>/pages/{{ row.slug }}</AppText></td>
                            <td><AppText size="sm" tone="soft">{{ row.in_nav ? '載せる' : '載せない' }}</AppText></td>
                            <td>
                                <StatusBadge :tone="row.status === 'published' ? 'ok' : 'neutral'" data-test="page-status">{{ row.status === 'published' ? '公開中' : '下書き' }}</StatusBadge>
                            </td>
                            <td>
                                <div class="flex flex-wrap justify-end gap-1">
                                    <AppButton size="sm" variant="primary" :to="{ name: 'theme-page', params: { tenant, page: row.name } }" data-test="edit-page-content">内容を編集</AppButton>
                                    <AppButton v-if="row.status === 'published' && row.url" size="sm" variant="ghost" :href="row.url" :aria-label="`${row.title}をショップで見る`"><AppIcon name="external" />見る</AppButton>
                                    <template v-if="canEdit">
                                        <AppButton v-if="row.status === 'published'" size="sm" :disabled="busy" data-test="unpublish-page" @click="setStatus(row, 'draft')">非公開にする</AppButton>
                                        <AppButton v-else size="sm" :disabled="busy" data-test="publish-page-row" @click="setStatus(row, 'published')">公開する</AppButton>
                                        <AppButton size="sm" variant="ghost" :disabled="busy || index === 0" :aria-label="`${row.title}を上へ`" data-test="page-up" @click="move(index, -1)">上へ</AppButton>
                                        <AppButton size="sm" variant="ghost" :disabled="busy || index === pages.length - 1" :aria-label="`${row.title}を下へ`" data-test="page-down" @click="move(index, 1)">下へ</AppButton>
                                        <AppButton size="sm" :disabled="busy" data-test="page-edit" @click="startEdit(row)">タイトルと URL</AppButton>
                                        <ConfirmButton label="ゴミ箱へ" :disabled="busy" :aria-label="`${row.title}をゴミ箱へ`" test="page-trash" @confirm="remove(row)">ショップに表示されなくなります。ゴミ箱から戻せます。</ConfirmButton>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="editing === row.id">
                            <td colspan="5">
                                <InsetSection as="form" :title="`${row.title}のタイトルと URL`" @submit.prevent="saveEdit(row)">
                                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                                        <AppField label="タイトル" :error="firstError(editErrors, 'title')">
                                            <AppInput v-model="edit.title" maxlength="60" required data-test="edit-page-title" />
                                        </AppField>
                                        <AppField label="URL" hint="変えると、前の URL を開いた人は新しい URL へ自動で移動します（設定の「SEO」の転送に追加されます）" :error="firstError(editErrors, 'slug')">
                                            <AppInput v-model="edit.slug" maxlength="60" required :pattern="URL_PATTERN" autocomplete="off" data-test="edit-page-slug" />
                                        </AppField>
                                    </div>
                                    <AppCheckbox v-model="edit.in_nav" label="ショップのメニューに載せる" test="edit-page-in-nav" />
                                    <SeoFields
                                        v-model:title="edit.seo_title"
                                        v-model:description="edit.seo_description"
                                        :fallback-title="edit.title"
                                        :shop-name="tenantName(tenant)"
                                        :url="row.url"
                                        auto-note="空欄なら、設定の「SEO」のトップの説明を使います。"
                                        :errors="editErrors"
                                        test="page-seo"
                                    />
                                    <div class="flex justify-end gap-2">
                                        <AppButton variant="ghost" @click="editing = null">やめる</AppButton>
                                        <AppButton type="submit" variant="primary" :disabled="busy" data-test="save-page">保存する</AppButton>
                                    </div>
                                </InsetSection>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </AppTable>
        </AppPanel>

        <AppPanel v-if="trash.length > 0" title="ゴミ箱" description="ゴミ箱のページはショップに表示されません。戻すと、前の内容のまま一覧の最後に戻ります。完全に削除すると元に戻せませんが、作れるページの数に空きができます。">
            <AppTable label="ゴミ箱のページ">
                <thead>
                    <tr><th>タイトル</th><th>URL</th><th><span class="sr-only">操作</span></th></tr>
                </thead>
                <tbody>
                    <tr v-for="row in trash" :key="row.id" data-test="trash-row">
                        <td><CellTitle :label="row.title" /></td>
                        <td><AppText size="sm" mono>/pages/{{ row.slug }}</AppText></td>
                        <td>
                            <div v-if="canEdit" class="flex justify-end gap-1">
                                <AppButton size="sm" :disabled="busy" data-test="page-restore" @click="restore(row)">戻す</AppButton>
                                <ConfirmButton label="完全に削除" :disabled="busy" :aria-label="`${row.title}を完全に削除`" test="page-purge" @confirm="purge(row)">
                                    元に戻せません。すべてのテーマで作ったこのページの内容も削除されます。
                                </ConfirmButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </AppTable>
        </AppPanel>
    </template>
</template>
