<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { api, describe } from '../api.js';
import { flatten, descendantIds } from '../categories.js';
import { errorsOf, firstError } from '../format.js';
import { crumbsFor, tabsFor, titleOf } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { canInTenant, tenantName } from '../tenants.js';
import AppButton from '../components/ui/AppButton.vue';
import AppField from '../components/ui/AppField.vue';
import AppInput from '../components/ui/AppInput.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppSelect from '../components/ui/AppSelect.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import CellTitle from '../components/ui/CellTitle.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import FormActions from '../components/ui/FormActions.vue';
import InsetSection from '../components/ui/InsetSection.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import NumCell from '../components/ui/NumCell.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import SeoFields from '../components/SeoFields.vue';

// 商品カテゴリ。木は 3 段まで。形の検査はサーバー（CategoryService）が持ち、ここは誤りをそのまま見せる
const props = defineProps({ tenant: { type: String, required: true } });

const tree = ref(null);
const maxDepth = ref(3);
const error = ref(null);
const loadError = ref(null);
const message = ref(null);
const busy = ref(false);
const form = reactive({ name: '', slug: '', parent_id: '' });
const formErrors = ref({});
const editing = ref(null);
// 検索の結果に出す題と説明（ADR 0061。空なら自動）。slug を変えると、古い URL からの転送が自動で入る
const edit = reactive({ name: '', slug: '', parent_id: '', seo_title: '', seo_description: '' });
const editErrors = ref({});

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/categories`);
// 変えるのは運用の段階から（閲覧だけの人にはボタンを出さない。見た目だけで、サーバーも 403 で断る）
const canEdit = computed(() => canInTenant(props.tenant, 'operate'));
const crumbs = computed(() => crumbsFor('categories', props.tenant, tenantName(props.tenant)));
const rows = computed(() => (tree.value ? flatten(tree.value) : []));
// 親にできるのは、深さに余裕があるカテゴリ
const parentOptions = computed(() => rows.value.filter((row) => row.depth + 1 < maxDepth.value));

function editParentOptions(row) {
    const blocked = new Set([row.id, ...descendantIds(row)]);

    return parentOptions.value.filter((option) => !blocked.has(option.id));
}

function label(row) {
    return `${'　'.repeat(row.depth)}${row.name}`;
}

async function run(action, done) {
    busy.value = true;
    error.value = null;
    message.value = null;

    try {
        const data = await action();
        tree.value = data.categories;
        maxDepth.value = data.max_depth;
        message.value = done;

        return true;
    } catch (e) {
        error.value = Object.keys(errorsOf(e)).length > 0 ? Object.values(errorsOf(e)).flat()[0] : describe(e);

        return false;
    } finally {
        busy.value = false;
    }
}

async function load() {
    loadError.value = null;
    busy.value = true;

    try {
        const data = await api('GET', base.value);
        tree.value = data.categories;
        maxDepth.value = data.max_depth;
    } catch (e) {
        loadError.value = e;
    } finally {
        busy.value = false;
    }
}

watch(() => props.tenant, load, { immediate: true });

async function create() {
    formErrors.value = {};
    busy.value = true;
    error.value = null;
    message.value = null;

    try {
        const data = await api('POST', base.value, { name: form.name, slug: form.slug, parent_id: form.parent_id || null });
        tree.value = data.categories;
        Object.assign(form, { name: '', slug: '' });
        message.value = 'カテゴリを追加しました。';
    } catch (e) {
        formErrors.value = errorsOf(e);
        error.value = Object.keys(formErrors.value).length > 0 ? null : describe(e);
    } finally {
        busy.value = false;
    }
}

function startEdit(row) {
    editing.value = row.id;
    editErrors.value = {};
    Object.assign(edit, { name: row.name, slug: row.slug, parent_id: row.parentId ?? '', seo_title: row.seo_title ?? '', seo_description: row.seo_description ?? '' });
}

async function saveEdit(row) {
    editErrors.value = {};
    busy.value = true;
    error.value = null;
    message.value = null;

    try {
        let data = await api('PATCH', `${base.value}/${row.id}`, { name: edit.name, slug: edit.slug, seo_title: edit.seo_title, seo_description: edit.seo_description });

        if ((edit.parent_id || null) !== row.parentId) {
            data = await api('POST', `${base.value}/${row.id}/move`, { parent_id: edit.parent_id || null });
        }

        tree.value = data.categories;
        editing.value = null;
        message.value = '保存しました。';
    } catch (e) {
        editErrors.value = errorsOf(e);
        error.value = Object.keys(editErrors.value).length > 0 ? null : describe(e);
    } finally {
        busy.value = false;
    }
}

function move(row, step) {
    const siblings = rows.value.filter((r) => r.parentId === row.parentId).map((r) => r.id);
    const i = siblings.indexOf(row.id);
    [siblings[i], siblings[i + step]] = [siblings[i + step], siblings[i]];
    run(() => api('POST', `${base.value}/order`, { parent_id: row.parentId, ids: siblings }), '並べ替えました。');
}

async function remove(row) {
    await run(() => api('DELETE', `${base.value}/${row.id}`), 'カテゴリを削除しました。');
}
</script>

<template>
    <PageHeader :help="helpLink('categories', tenant)" :title="titleOf('categories')" :crumbs="crumbs" :tabs="tabsFor('categories', tenant)" />
    <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
    <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>

    <ErrorState v-if="loadError" :error="loadError" />
    <LoadingText v-else-if="tree === null && !error" />
    <template v-else-if="tree">
        <AppText v-if="!canEdit" size="sm" tone="soft" data-test="categories-read-only">カテゴリを変更できるのは「運用」以上の権限のある人だけです。</AppText>
        <AppPanel v-else as="form" title="カテゴリを追加" :description="`${maxDepth} 階層まで作れます。slug は、ショップの URL に使う英小文字・数字・ハイフンの名前です（例: knit、tops-and-shirts）。`" @submit.prevent="create">
            <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
                <AppField label="名前" :error="firstError(formErrors, 'name')">
                    <AppInput v-model="form.name" maxlength="40" required data-test="category-name" />
                </AppField>
                <AppField label="slug" :error="firstError(formErrors, 'slug')">
                    <AppInput v-model="form.slug" maxlength="60" required pattern="[a-z0-9]+(-[a-z0-9]+)*" autocomplete="off" data-test="category-slug" />
                </AppField>
                <AppField label="親" :error="firstError(formErrors, 'parent_id')">
                    <AppSelect v-model="form.parent_id" data-test="category-parent">
                        <option value="">なし（いちばん上の階層）</option>
                        <option v-for="option in parentOptions" :key="option.id" :value="option.id">{{ label(option) }}</option>
                    </AppSelect>
                </AppField>
            </div>
            <FormActions>
                <AppButton type="submit" variant="primary" :disabled="busy" data-test="add-category">追加</AppButton>
            </FormActions>
        </AppPanel>

        <AppPanel title="カテゴリの階層">
            <AppText v-if="rows.length === 0" tone="soft">まだカテゴリがありません。</AppText>
            <AppTable v-else label="カテゴリの階層">
                <thead>
                    <tr><th>名前</th><th>slug</th><th class="num">商品</th><th v-if="canEdit"><span class="sr-only">操作</span></th></tr>
                </thead>
                <tbody>
                    <template v-for="row in rows" :key="row.id">
                        <tr data-test="category-row">
                            <td :class="['', 'pl-8', 'pl-14'][row.depth]"><CellTitle :label="row.name" /></td>
                            <td>{{ row.slug }}</td>
                            <NumCell :value="row.listings" />
                            <td v-if="canEdit">
                                <div class="flex flex-wrap justify-end gap-1">
                                    <AppButton size="sm" variant="ghost" :disabled="busy || row.index === 0" :aria-label="`${row.name}を上へ`" data-test="category-up" @click="move(row, -1)">上へ</AppButton>
                                    <AppButton size="sm" variant="ghost" :disabled="busy || row.index === row.siblings - 1" :aria-label="`${row.name}を下へ`" data-test="category-down" @click="move(row, 1)">下へ</AppButton>
                                    <AppButton size="sm" :disabled="busy" data-test="category-edit" @click="startEdit(row)">編集</AppButton>
                                    <ConfirmButton label="削除" :disabled="busy || row.children.length > 0" :aria-label="`${row.name}を削除`" test="category-delete" confirm-test="category-confirm-delete" @confirm="remove(row)" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="editing === row.id">
                            <td colspan="4">
                                <InsetSection as="form" :title="`${row.name}の編集`" @submit.prevent="saveEdit(row)">
                                    <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
                                        <AppField label="名前" :error="firstError(editErrors, 'name')">
                                            <AppInput v-model="edit.name" maxlength="40" required data-test="edit-name" />
                                        </AppField>
                                        <AppField label="slug" :error="firstError(editErrors, 'slug')">
                                            <AppInput v-model="edit.slug" maxlength="60" required data-test="edit-slug" />
                                        </AppField>
                                        <AppField label="親" :error="firstError(editErrors, 'parent_id')">
                                            <AppSelect v-model="edit.parent_id" data-test="edit-parent">
                                                <option value="">なし（いちばん上の階層）</option>
                                                <option v-for="option in editParentOptions(row)" :key="option.id" :value="option.id">{{ label(option) }}</option>
                                            </AppSelect>
                                        </AppField>
                                    </div>
                                    <AppText v-if="edit.slug !== row.slug" size="sm" tone="soft" data-test="slug-redirect-note">slug を変えると、前の URL を開いた人と検索エンジンは、新しい URL へ自動で移動します（設定の「SEO」の転送に追加されます）。</AppText>
                                    <SeoFields
                                        v-model:title="edit.seo_title"
                                        v-model:description="edit.seo_description"
                                        :fallback-title="edit.name"
                                        :fallback-description="`${tenantName(tenant)}の${edit.name}の一覧です。`"
                                        :shop-name="tenantName(tenant)"
                                        auto-note="空欄なら「（店の名前）の（カテゴリの名前）の一覧です。」になります。"
                                        :errors="editErrors"
                                        test="category-seo"
                                    />
                                    <div class="flex justify-end gap-2">
                                        <AppButton variant="ghost" @click="editing = null">やめる</AppButton>
                                        <AppButton type="submit" variant="primary" :disabled="busy" data-test="save-category">保存する</AppButton>
                                    </div>
                                </InsetSection>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </AppTable>
        </AppPanel>
    </template>
</template>
