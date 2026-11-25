<script setup>
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, ApiError } from '../api.js';
import { appPageTitle, cellText, isNumeric } from '../appPages.js';
import { useOperation } from '../operation.js';
import { crumbsFor, titleOf } from '../navigation.js';
import { appHelpLink } from '../help/index.js';
import { canInTenant, tenantName } from '../tenants.js';
import AppRecordForm from './app/AppRecordForm.vue';
import AppButton from '../components/ui/AppButton.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPageValue from '../components/ui/AppPageValue.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import CopyButton from '../components/ui/CopyButton.vue';
import DescriptionList from '../components/ui/DescriptionList.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import NumCell from '../components/ui/NumCell.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import ProminentFacts from '../components/ui/ProminentFacts.vue';

// App の画面の 1 件（ADR 0051。定期便の 1 件など）。見出し・事実の並び・表（回の履歴など）・押せる操作は App が渡し、描くのは核の部品だけ。
// 見るのは閲覧の段階から。操作は運用の段階から（足りない人にはボタンを出さず一言。見た目のためだけで、守るのはサーバーの 403）。
// 操作は確認の文（App の confirm）を出してから送る（ConfirmButton。danger は危ない操作の見た目）。終わったら答えの 1 件に入れ替える。
// 断られた（409）ときはサーバーの文（user_message）を出す。404 は「今は押せない」（ほかの人が先に状態を変えた・古い画面からの押し直し）なので、
// その旨を出して 1 件を読み直す。
// 直す欄を持つ App（よくある質問など）は「直す」の欄も出す（運用の段階から。描くのは核の部品 AppRecordForm）。
// くり返して押す操作（repeatable。並べ替えの「上へ」など）は確認せずに送る。写す印（copy）の付いた事実は、値の下に写すボタン（商品フィードの URL など）。操作で記録が無くなった（消す）ら、一覧へ戻って通知する。
// 直す欄の言葉を App が決めた（form.wording。お問い合わせの「返信する」など）ら、見出し・ボタン・送った後の通知にそれを使う。
// 個人情報（名前・メール・本文など）は運用の段階の人にだけサーバーが返す。閲覧の人には隠したこと（record.personal が hidden）を一言で通知する。
// 欄のある操作（action.form。チケットの「無効にする」の理由など）は、押すと欄を開き、欄の値と一緒に送る（確認の文は欄の上に）。
// お金を動かす操作（action.money。返金）は「会計」の権限の人にだけ出す（足りない人には一言。守るのはサーバーの 403 と直前の再確認。当日払いの受け取り ・返金 ）。大きく出す事実（prominent。券面の名前など）は上に大きく
const props = defineProps({
    tenant: { type: String, required: true },
    plugin: { type: String, required: true },
    record: { type: String, required: true },
});
const router = useRouter();
const detail = ref(null);
const form = ref(null);
const error = ref(null);
const acting = useOperation();
const saving = useOperation();

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/apps/${encodeURIComponent(props.plugin)}/records/${encodeURIComponent(props.record)}`);
const pageTitle = computed(() => detail.value?.page_title ?? appPageTitle(props.tenant, props.plugin) ?? titleOf('app-record'));
const crumbs = computed(() => crumbsFor('app-record', props.tenant, tenantName(props.tenant), detail.value?.title ?? null, { plugin: props.plugin, title: pageTitle.value }));
const help = computed(() => appHelpLink(props.plugin, 'app-record', props.tenant));
const canOperate = computed(() => canInTenant(props.tenant, 'operate'));
const canMoney = computed(() => canInTenant(props.tenant, 'money'));
// 開いている欄つきの操作（key か null）
const opened = ref(null);
const openedAction = computed(() => detail.value?.actions.find((action) => action.key === opened.value && action.form) ?? null);
const prominent = computed(() => (detail.value?.facts ?? []).filter((fact) => fact.prominent));
const facts = computed(() => (detail.value?.facts ?? []).filter((fact) => !fact.prominent));
const shownActions = computed(() => (detail.value?.actions ?? []).filter((action) => !action.money || canMoney.value));
const moneyHidden = computed(() => (detail.value?.actions ?? []).some((action) => action.money) && !canMoney.value);
const editTitle = computed(() => form.value?.wording?.title ?? '編集');
const editSubmit = computed(() => form.value?.wording?.submit ?? '保存する');

watch(() => [props.tenant, props.plugin, props.record], () => {
    acting.message.value = null;
    acting.error.value = null;
    saving.message.value = null;
    saving.error.value = null;
    load();
}, { immediate: true });

function apply(result) {
    detail.value = result.record;
    form.value = result.form ?? null;

    if (!detail.value?.actions.some((action) => action.key === opened.value)) {
        opened.value = null;
    }
}

async function load() {
    detail.value = null;
    form.value = null;
    error.value = null;

    try {
        apply(await api('GET', base.value));
    } catch (e) {
        error.value = e;
    }
}

async function save(values) {
    await saving.run('save', () => api('PUT', base.value, { values }), {
        then: (result) => { apply(result); },
        done: form.value?.wording?.done ?? '保存しました。',
    });
}

async function act(action, values = null) {
    let changed = false;
    await acting.run(action.key, async () => {
        try {
            return await api('POST', `${base.value}/actions/${encodeURIComponent(action.key)}`, values === null ? undefined : { values });
        } catch (e) {
            changed = e instanceof ApiError && e.status === 404;
            throw e;
        }
    }, {
        then: async (result) => {
            if (result.record === null) {
                await router.push({ name: 'app-page', params: { tenant: props.tenant, plugin: props.plugin }, state: { appRemoved: true } });

                return;
            }

            opened.value = null;
            apply(result);
        },
        done: `「${action.label}」が済みました。`,
    });

    if (changed) {
        acting.error.value = 'ほかの操作で状態が変わっていたため、実行できませんでした。画面を最新の状態にしたので、今の状態を確認してから、もう一度選んでください。';
        await reload();
    }
}

// 操作の後の読み直し（読めなければ画面ごと誤りの表示にする。消えた記録など）
async function reload() {
    try {
        apply(await api('GET', base.value));
    } catch (e) {
        error.value = e;
    }
}
</script>

<template>
    <PageHeader :help="help" :title="detail?.title ?? pageTitle" :crumbs="crumbs" />
    <ErrorState v-if="error" :error="error" />
    <LoadingText v-else-if="detail === null" />

    <template v-else>
        <AppNotice v-if="acting.message.value" tone="success" data-test="app-action-done">{{ acting.message.value }}</AppNotice>
        <AppNotice v-if="acting.error.value" tone="error" data-test="app-action-error">{{ acting.error.value }}</AppNotice>

        <AppPanel v-if="detail.actions.length > 0" title="操作" data-test="app-actions">
            <AppText v-if="!canOperate" tone="soft" data-test="app-actions-no-permission">操作できるのは「運用」以上の権限のある人だけです。</AppText>
            <div v-else class="flex flex-wrap items-center gap-2">
                <template v-for="action in shownActions" :key="action.key">
                    <AppButton
                        v-if="action.form"
                        :variant="action.danger ? 'danger' : 'secondary'"
                        :aria-expanded="opened === action.key ? 'true' : 'false'"
                        :disabled="acting.pending.value !== null"
                        :data-test="`app-action-${action.key}`"
                        @click="opened = opened === action.key ? null : action.key"
                    >
                        {{ action.label }}
                    </AppButton>
                    <AppButton
                        v-else-if="action.repeatable"
                        :disabled="acting.pending.value !== null"
                        :loading="acting.pending.value === action.key"
                        :data-test="`app-action-${action.key}`"
                        @click="act(action)"
                    >
                        {{ action.label }}
                    </AppButton>
                    <ConfirmButton
                        v-else
                        :label="action.label"
                        :variant="action.danger ? 'danger' : 'secondary'"
                        :disabled="acting.pending.value !== null"
                        :loading="acting.pending.value === action.key"
                        :test="`app-action-${action.key}`"
                        @confirm="act(action)"
                    >
                        {{ action.confirm }}
                    </ConfirmButton>
                </template>
            </div>
            <AppText v-if="canOperate && moneyHidden" tone="soft" data-test="app-actions-money-hidden">代金の受け取り・取消などお金を扱う操作ができるのは、「会計」の権限のある人だけです。</AppText>
        </AppPanel>

        <AppPanel v-if="openedAction && canOperate" :title="openedAction.label" data-test="app-action-form">
            <AppText tone="soft">{{ openedAction.confirm }}</AppText>
            <AppRecordForm :form="openedAction.form" :submit-label="openedAction.label" :errors="acting.errors.value" :pending="acting.pending.value === openedAction.key" :test="`app-action-form-${openedAction.key}`" @submit="(values) => act(openedAction, values)" />
        </AppPanel>

        <AppPanel v-if="prominent.length > 0" data-test="app-prominent-panel">
            <ProminentFacts :items="prominent" />
        </AppPanel>

        <AppNotice v-if="detail.personal === 'hidden'" tone="info" data-test="app-personal-hidden">名前・メール・本文などの個人情報を見られるのは、「運用」以上の権限のある人だけです。</AppNotice>

        <AppPanel v-if="form !== null && canOperate" :title="editTitle" data-test="app-edit">
            <AppNotice v-if="saving.message.value" tone="success" data-test="app-edit-done">{{ saving.message.value }}</AppNotice>
            <AppNotice v-if="saving.error.value" tone="error">{{ saving.error.value }}</AppNotice>
            <AppRecordForm :form="form" :tenant="tenant" :submit-label="editSubmit" :errors="saving.errors.value" :pending="saving.pending.value !== null" test="app-edit-form" @submit="save" />
        </AppPanel>

        <AppPanel title="内容" data-test="app-facts">
            <DescriptionList :items="facts">
                <template #value="{ item }">
                    <AppPageValue :type="item.type" :value="item.value" :tenant="tenant" />
                    <CopyButton v-if="item.copy && typeof item.value === 'string'" :text="item.value" data-test="app-fact-copy" />
                </template>
            </DescriptionList>
        </AppPanel>

        <AppPanel v-for="(table, tableIndex) in detail.tables" :key="tableIndex" :title="table.title" data-test="app-table">
            <AppText v-if="table.rows.length === 0" tone="soft">まだありません。</AppText>
            <AppTable v-else :label="table.title" stack>
                <thead>
                    <tr>
                        <th v-for="column in table.columns" :key="column.key" :class="isNumeric(column.type) ? 'num' : null">{{ column.label }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, rowIndex) in table.rows" :key="row.id ?? rowIndex" data-test="app-table-row">
                        <template v-for="column in table.columns" :key="column.key">
                            <NumCell v-if="isNumeric(column.type)" :value="cellText(column.type, row.cells[column.key])" :data-label="column.label" :data-test="`app-cell-${column.key}`" />
                            <td v-else :data-label="column.label" :data-test="`app-cell-${column.key}`">
                                <AppPageValue :type="column.type" :value="row.cells[column.key]" :tenant="tenant" />
                            </td>
                        </template>
                    </tr>
                </tbody>
            </AppTable>
        </AppPanel>
    </template>
</template>
