<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { api, describe } from '../../api.js';
import { errorsOf, firstError } from '../../format.js';
import { crumbsFor, titleOf } from '../../navigation.js';
import { helpLink } from '../../help/index.js';
import { canInTenant, tenantName } from '../../tenants.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppCheckbox from '../../components/ui/AppCheckbox.vue';
import AppField from '../../components/ui/AppField.vue';
import AppIcon from '../../components/ui/AppIcon.vue';
import AppInput from '../../components/ui/AppInput.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppSelect from '../../components/ui/AppSelect.vue';
import AppTable from '../../components/ui/AppTable.vue';
import AppText from '../../components/ui/AppText.vue';
import CellTitle from '../../components/ui/CellTitle.vue';
import ConfirmButton from '../../components/ui/ConfirmButton.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import FormActions from '../../components/ui/FormActions.vue';
import LoadingText from '../../components/ui/LoadingText.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import StatusBadge from '../../components/ui/StatusBadge.vue';
import TagInput from '../../components/ui/TagInput.vue';
import SettingsLayout from '../../components/SettingsLayout.vue';

// 設定 › 注文の項目（ADR 0066）。店が注文の入力に足す欄（のしの有無・用途・ご要望など）を足す・直す・並べ替える・消す。
// 種類は 1 行の文・複数行の文・選ぶ・チェック。同時に使う欄は 10 個まで。注文に使われた欄は消せず、「使わない」にだけできる（過去の注文は当時の名前で残る）。
// 読むのは閲覧から、変えるのは運用から（サーバーも 403 で断る）。形の検査と上限はサーバー
const props = defineProps({ tenant: { type: String, required: true } });

const EMPTY = { name: '', description: '', type: 'text', options: [], required: false, enabled: true };
const data = ref(null);
const loadError = ref(null);
const busy = ref(false);
const error = ref(null);
const message = ref(null);
const errors = ref({});
// 直している欄の ID（null は足す）
const editing = ref(null);
const draft = reactive({ ...EMPTY, options: [] });

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/order-fields`);
const canEdit = computed(() => canInTenant(props.tenant, 'operate'));
const crumbs = computed(() => crumbsFor('settings-order-fields', props.tenant, tenantName(props.tenant)));
const fields = computed(() => data.value?.fields ?? []);
const enabledCount = computed(() => fields.value.filter((f) => f.enabled).length);
const typeLabel = (type) => data.value?.types.find((t) => t.value === type)?.label ?? type;

function reset() {
    editing.value = null;
    Object.assign(draft, { ...EMPTY, options: [] });
    errors.value = {};
}

async function load() {
    loadError.value = null;
    data.value = null;

    try {
        data.value = await api('GET', base.value);
        reset();
    } catch (e) {
        loadError.value = e;
    }
}

watch(() => props.tenant, load, { immediate: true });

async function act(action, done) {
    busy.value = true;
    error.value = null;
    message.value = null;
    errors.value = {};

    try {
        data.value = await action();
        message.value = done;

        return true;
    } catch (e) {
        errors.value = errorsOf(e);
        error.value = Object.keys(errors.value).length > 0 ? null : describe(e);

        return false;
    } finally {
        busy.value = false;
    }
}

// 送る形（選択肢は選ぶ欄だけ）
function body() {
    return {
        name: draft.name,
        description: draft.description === '' ? null : draft.description,
        type: draft.type,
        options: draft.type === 'select' ? [...draft.options] : [],
        required: draft.required,
        enabled: draft.enabled,
    };
}

async function save() {
    const id = editing.value;
    const ok = await act(
        () => (id === null ? api('POST', base.value, body()) : api('PUT', `${base.value}/${encodeURIComponent(id)}`, body())),
        id === null ? `「${draft.name}」を追加しました。` : `「${draft.name}」を保存しました。`,
    );

    if (ok) {
        reset();
    }
}

function edit(field) {
    editing.value = field.id;
    Object.assign(draft, {
        name: field.name,
        description: field.description ?? '',
        type: field.type,
        options: [...field.options],
        required: field.required,
        enabled: field.enabled,
    });
    errors.value = {};
}

async function remove(field) {
    await act(() => api('DELETE', `${base.value}/${encodeURIComponent(field.id)}`), `「${field.name}」を削除しました。`);

    if (editing.value === field.id) {
        reset();
    }
}

// 1 つ上・下へ（全部の欄の並びを送る）
async function move(index, step) {
    const ids = fields.value.map((f) => f.id);
    const [moved] = ids.splice(index, 1);
    ids.splice(index + step, 0, moved);
    await act(() => api('POST', `${base.value}/order`, { ids }), '並べ替えました。');
}

// 選択肢の誤りは、並びのどれか（options.3 など）にも付く
function optionsError() {
    const key = Object.keys(errors.value).find((k) => k === 'options' || k.startsWith('options.'));

    return key ? firstError(errors.value, key) : null;
}
</script>

<template>
    <SettingsLayout :tenant="tenant">
        <PageHeader :help="helpLink('settings-order-fields', tenant)" :title="titleOf('settings-order-fields')" :crumbs="crumbs" />
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>
        <AppNotice v-if="firstError(errors, 'order_field')" tone="error" data-test="order-field-delete-error">{{ firstError(errors, 'order_field') }}</AppNotice>

        <ErrorState v-if="loadError" :error="loadError" />
        <LoadingText v-else-if="data === null" />
        <template v-else>
            <AppPanel
                title="注文の項目"
                :description="`お客さんが注文の入力で回答する欄です（のしの有無・用途・ご要望など）。回答は、管理画面の注文の画面に表示されます（自由記述の回答は「個人情報」を表示したときだけ）。お客さんへのメールと「ご注文の状況」の画面には、選択・チェックの回答だけが表示されます。同時に使える項目は ${data.limits.enabled} 個まで（今 ${enabledCount} 個）。`"
            >
                <AppText v-if="fields.length === 0" tone="soft" data-test="order-fields-empty">まだ項目はありません。下の「項目を追加」から追加すると、注文の入力の画面に表示されます。</AppText>
                <AppTable v-else label="注文の項目の一覧" stack>
                    <thead>
                        <tr><th>項目</th><th>種類</th><th>状態</th><th v-if="canEdit"><span class="sr-only">操作</span></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(field, index) in fields" :key="field.id" data-test="order-field-row">
                            <td data-label="項目" data-test="order-field-name"><CellTitle :label="field.name" :sub="field.description" /></td>
                            <td data-label="種類">
                                <div class="flex flex-col gap-1">
                                    <AppText as="span" size="sm">{{ typeLabel(field.type) }}</AppText>
                                    <AppText v-if="field.type === 'select'" as="span" size="xs" tone="soft" break-all>{{ field.options.join('・') }}</AppText>
                                </div>
                            </td>
                            <td data-label="状態">
                                <div class="flex flex-wrap gap-2">
                                    <StatusBadge :tone="field.enabled ? 'ok' : 'neutral'" data-test="order-field-state">{{ field.enabled ? '使う' : '使わない' }}</StatusBadge>
                                    <StatusBadge v-if="field.required" tone="accent">必須</StatusBadge>
                                    <StatusBadge v-if="field.used" tone="neutral">回答あり</StatusBadge>
                                </div>
                            </td>
                            <td v-if="canEdit">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    <AppButton size="icon" variant="ghost" :disabled="busy || index === 0" :aria-label="`${field.name} を 1 つ上へ`" data-test="order-field-up" @click="move(index, -1)"><AppIcon name="chevron-up" /></AppButton>
                                    <AppButton size="icon" variant="ghost" :disabled="busy || index === fields.length - 1" :aria-label="`${field.name} を 1 つ下へ`" data-test="order-field-down" @click="move(index, 1)"><AppIcon name="chevron-down" /></AppButton>
                                    <AppButton size="sm" :disabled="busy" data-test="order-field-edit" @click="edit(field)">編集</AppButton>
                                    <ConfirmButton v-if="!field.used" label="削除" :disabled="busy" :aria-label="`${field.name} を削除`" test="order-field-delete" @confirm="remove(field)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </AppTable>
                <AppText v-if="fields.some((f) => f.used)" size="xs" tone="soft">注文で回答された項目は削除できません。お客さんの画面に表示しないときは、「編集」で「使う」をオフにしてください（過去の注文には、その時の名前で残ります）。</AppText>
            </AppPanel>

            <AppPanel
                v-if="canEdit"
                as="form"
                :title="editing === null ? '項目を追加' : '項目を編集'"
                description="名前と種類を決めます。名前を後で変えても、過去の注文にはその時の名前で表示されます。"
                data-test="order-field-form"
                @submit.prevent="save"
            >
                <AppField layout="row" label="名前" :hint="`お客さんに表示する名前です（${data.limits.name} 文字まで。例: のし・ご用途・ご要望）。`" :error="firstError(errors, 'name')">
                    <AppInput v-model="draft.name" :maxlength="data.limits.name" required autocomplete="off" data-test="order-field-name-input" />
                </AppField>
                <AppField layout="row" label="説明" optional :hint="`欄の下に表示する一言です（${data.limits.description} 文字まで）。`" :error="firstError(errors, 'description')">
                    <AppInput v-model="draft.description" :maxlength="data.limits.description" autocomplete="off" data-test="order-field-description-input" />
                </AppField>
                <AppField layout="row" label="種類" hint="「1 行のテキスト」は 200 文字まで、「複数行のテキスト」は 1,000 文字まで入力できます。「選択式」は用意した選択肢から 1 つ、「チェックボックス」は「はい／いいえ」で答えます。" :error="firstError(errors, 'type')">
                    <AppSelect v-model="draft.type" data-test="order-field-type">
                        <option v-for="type in data.types" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </AppSelect>
                </AppField>
                <!-- 札の入力は名前を自分で出すので、項目の枠（AppField）で包まない -->
                <TagInput
                    v-if="draft.type === 'select'"
                    v-model="draft.options"
                    label="選択肢"
                    :hint="`1 つずつ入力して Enter で追加します（${data.limits.options} 個まで、1 つ ${data.limits.option} 文字まで）。`"
                    placeholder="お祝い"
                    :error="optionsError()"
                    :maxlength="data.limits.option"
                    :max="data.limits.options"
                    test="order-field-options"
                />
                <AppField as="div" layout="row" label="回答の設定" :error="firstError(errors, 'required') ?? firstError(errors, 'enabled')">
                    <div class="flex flex-col">
                        <AppCheckbox v-model="draft.required" :label="draft.type === 'checkbox' ? '必須（チェックを入れないと注文できない）' : '必須（回答しないと注文できない）'" test="order-field-required" />
                        <AppCheckbox v-model="draft.enabled" label="使う（注文の入力の画面に表示する）" test="order-field-enabled" />
                    </div>
                </AppField>
                <FormActions sticky>
                    <AppButton v-if="editing !== null" variant="ghost" :disabled="busy" data-test="order-field-cancel" @click="reset">やめる</AppButton>
                    <AppButton type="submit" variant="primary" :disabled="busy" :loading="busy" data-test="save-order-field">{{ editing === null ? '項目を追加' : '項目を保存する' }}</AppButton>
                </FormActions>
            </AppPanel>
            <AppText v-else size="sm" tone="soft" data-test="settings-read-only">項目を変更できるのは「運用」以上の権限のある人だけです。</AppText>
        </template>
    </SettingsLayout>
</template>
