<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { api, describe } from '../../api.js';
import { errorsOf, firstError } from '../../format.js';
import { crumbsFor, titleOf } from '../../navigation.js';
import { helpLink } from '../../help/index.js';
import { canInTenant, tenantName } from '../../tenants.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppField from '../../components/ui/AppField.vue';
import AppInput from '../../components/ui/AppInput.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppTable from '../../components/ui/AppTable.vue';
import AppText from '../../components/ui/AppText.vue';
import ConfirmButton from '../../components/ui/ConfirmButton.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import FormActions from '../../components/ui/FormActions.vue';
import LoadingText from '../../components/ui/LoadingText.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import StatusBadge from '../../components/ui/StatusBadge.vue';
import PhotoPicker from '../../components/sections/PhotoPicker.vue';
import SeoFields from '../../components/SeoFields.vue';
import SettingsLayout from '../../components/SettingsLayout.vue';

// 設定 › SEO（ADR 0061）: トップの題と説明・共有の既定の写真・古い URL からの転送。
// 商品・カテゴリ・ページの題と説明は、それぞれの画面の「検索と共有」で入れる。形の検査と転送の守り（外へ飛ばさない・ループ・上限）はサーバー
const props = defineProps({ tenant: { type: String, required: true } });

const data = ref(null);
const media = ref([]);
const loadError = ref(null);
const busy = ref(false);
const error = ref(null);
const message = ref(null);
const draft = reactive({ home_title: '', home_description: '', share_image_id: null });
const errors = ref({});
const redirect = reactive({ from: '', to: '' });
const redirectErrors = ref({});

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}`);
const canEdit = computed(() => canInTenant(props.tenant, 'operate'));
const crumbs = computed(() => crumbsFor('settings-seo', props.tenant, tenantName(props.tenant)));
const redirects = computed(() => data.value?.redirects ?? []);
const dirty = computed(() => data.value !== null && (
    (data.value.settings.home_title ?? '') !== draft.home_title
    || (data.value.settings.home_description ?? '') !== draft.home_description
    || (data.value.settings.share_image_id ?? null) !== draft.share_image_id
));

function receive(next) {
    data.value = next;
    Object.assign(draft, {
        home_title: next.settings.home_title ?? '',
        home_description: next.settings.home_description ?? '',
        share_image_id: next.settings.share_image_id ?? null,
    });
}

async function load() {
    loadError.value = null;

    try {
        const [seo, photos] = await Promise.all([api('GET', `${base.value}/seo`), api('GET', `${base.value}/shop-media?usage=none`)]);
        media.value = photos.items;
        receive(seo);
    } catch (e) {
        loadError.value = e;
    }
}

watch(() => props.tenant, load, { immediate: true });

async function act(action, done, fieldErrors) {
    busy.value = true;
    error.value = null;
    message.value = null;
    fieldErrors.value = {};

    try {
        const next = await action();
        message.value = done;

        return next;
    } catch (e) {
        fieldErrors.value = errorsOf(e);
        error.value = Object.keys(fieldErrors.value).length > 0 ? null : describe(e);

        return null;
    } finally {
        busy.value = false;
    }
}

async function save() {
    const next = await act(() => api('PUT', `${base.value}/seo`, { ...draft }), '検索と共有の設定を保存しました。', errors);

    if (next) {
        receive(next);
    }
}

async function addRedirect() {
    const next = await act(() => api('POST', `${base.value}/seo/redirects`, { ...redirect }), '転送を追加しました。', redirectErrors);

    if (next) {
        // 書きかけのトップの題と説明は残す
        data.value = { ...next, settings: data.value.settings };
        Object.assign(redirect, { from: '', to: '' });
    }
}

async function removeRedirect(row) {
    const next = await act(() => api('DELETE', `${base.value}/seo/redirects/${row.id}`), '転送を削除しました。', redirectErrors);

    if (next) {
        data.value = { ...next, settings: data.value.settings };
    }
}

function uploaded(item) {
    media.value = [item, ...media.value];
}
</script>

<template>
    <SettingsLayout :tenant="tenant">
        <PageHeader :help="helpLink('settings-seo', tenant)" :title="titleOf('settings-seo')" :crumbs="crumbs" />
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>

        <ErrorState v-if="loadError" :error="loadError" />
        <LoadingText v-else-if="data === null" />
        <template v-else>
            <AppPanel
                as="form"
                title="トップのページ"
                description="Google などの検索結果と、LINE・X でショップの URL を共有した時に表示されるタイトルと説明です。商品・カテゴリ・ページのタイトルと説明は、それぞれの画面の「検索と共有」で入力します。"
                @submit.prevent="save"
            >
                <SeoFields
                    v-model:title="draft.home_title"
                    v-model:description="draft.home_description"
                    :fallback-title="data.shop_name"
                    :fallback-description="`${data.shop_name}のオンラインストアです。`"
                    :shop-name="data.shop_name"
                    :url="data.shop_url"
                    :suffix="false"
                    auto-note="空欄なら「（店の名前）のオンラインストアです。」になります。説明の無いページの説明にも使います。"
                    title-field="home_title"
                    description-field="home_description"
                    :errors="errors"
                    :disabled="!canEdit"
                    test="home-seo"
                />
                <AppField as="div" label="共有の写真" layout="row" optional hint="LINE・X などで共有した時に表示される写真（横 1200 × 縦 630 くらいが目安）。商品の画面では、その商品の 1 枚目の写真が優先されます" :error="firstError(errors, 'share_image_id')">
                    <PhotoPicker v-model="draft.share_image_id" label="共有の写真" :media="media" :base="`${base}/shop-media`" :disabled="!canEdit" test="share-image" @uploaded="uploaded" />
                </AppField>
                <AppText v-if="!canEdit" size="sm" tone="soft" data-test="settings-read-only">設定を変更できるのは「運用」以上の権限のある人だけです。</AppText>
                <FormActions v-else sticky>
                    <template v-if="dirty" #meta><span data-test="settings-unsaved">保存していない変更があります</span></template>
                    <AppButton type="submit" variant="primary" :disabled="busy" :loading="busy" data-test="save-seo">保存する</AppButton>
                </FormActions>
            </AppPanel>

            <AppPanel
                title="古い URL からの転送"
                :description="`前のショップの URL（/item/123 など）を開いた人と検索エンジンを、このショップの新しい URL へ移動させます（301 の転送）。古い URL のページがショップに無い時だけ転送します。カテゴリとページの URL を変えた時は自動で追加されます。${data.limits.redirects.toLocaleString()} 件まで。`"
            >
                <form v-if="canEdit" class="flex flex-col gap-4" data-test="redirect-form" @submit.prevent="addRedirect">
                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <AppField label="古い URL" hint="/ から始まるパス（前のショップの URL をそのまま貼っても、パスの部分だけを使います）" :error="firstError(redirectErrors, 'from')">
                            <AppInput v-model="redirect.from" :maxlength="data.limits.path" required autocomplete="off" placeholder="/item/123" data-test="redirect-from" />
                        </AppField>
                        <AppField label="新しい URL" hint="このショップ内のパスを / から入力します（ほかのサイトへは転送できません）" :error="firstError(redirectErrors, 'to')">
                            <AppInput v-model="redirect.to" :maxlength="data.limits.path" required autocomplete="off" placeholder="/products/…" data-test="redirect-to" />
                        </AppField>
                    </div>
                    <div class="flex justify-end">
                        <AppButton type="submit" :disabled="busy" data-test="add-redirect">転送を追加</AppButton>
                    </div>
                </form>
                <AppText v-if="redirects.length === 0" tone="soft" data-test="redirects-empty">まだ転送はありません。</AppText>
                <AppTable v-else label="転送の一覧" stack>
                    <thead>
                        <tr><th>古い URL</th><th>新しい URL</th><th>追加の方法</th><th v-if="canEdit"><span class="sr-only">操作</span></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in redirects" :key="row.id" data-test="redirect-row">
                            <td data-label="古い URL"><AppText as="span" size="sm" break-all>{{ row.from }}</AppText></td>
                            <td data-label="新しい URL"><AppText as="span" size="sm" break-all>{{ row.to }}</AppText></td>
                            <td data-label="追加の方法"><StatusBadge :tone="row.source === 'auto' ? 'accent' : 'neutral'">{{ row.source === 'auto' ? 'URL を変えた時に自動' : '手動で追加' }}</StatusBadge></td>
                            <td v-if="canEdit">
                                <div class="flex justify-end">
                                    <ConfirmButton label="削除" :disabled="busy" :aria-label="`${row.from} の転送を削除`" test="redirect-delete" @confirm="removeRedirect(row)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </AppTable>
            </AppPanel>
        </template>
    </SettingsLayout>
</template>
