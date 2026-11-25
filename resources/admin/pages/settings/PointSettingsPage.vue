<script setup>
import { computed, ref, watch } from 'vue';
import { api, describe } from '../../api.js';
import { errorsOf, firstError, formatAmount } from '../../format.js';
import { crumbsFor, titleOf } from '../../navigation.js';
import { helpLink } from '../../help/index.js';
import { canInTenant, tenantName } from '../../tenants.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppField from '../../components/ui/AppField.vue';
import AppInput from '../../components/ui/AppInput.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppText from '../../components/ui/AppText.vue';
import ChoicePills from '../../components/ui/ChoicePills.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import FormActions from '../../components/ui/FormActions.vue';
import LoadingText from '../../components/ui/LoadingText.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import StatCard from '../../components/ui/StatCard.vue';
import SettingsLayout from '../../components/SettingsLayout.vue';

// 設定 › ポイント（ADR 0068 の 7）。会員に付けるポイントの率（0〜20%、0.1% きざみ。既定 1%）と、使う・使わない。1 ポイント = 1 円。
// 使わないにすると、新しい注文では付けない・使えない（残っているポイントは消さず、期限で消える）。率を変えても、前の注文は注文の時の率のまま。
// 読むのは閲覧から、変えるのは運用から（サーバーも 403 で断る）
const props = defineProps({ tenant: { type: String, required: true } });

const loaded = ref(false);
const loadError = ref(null);
const busy = ref(false);
const errors = ref({});
const error = ref(null);
const message = ref(null);
const server = ref(null);
const draft = ref(null);

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/shop-settings/points`);
const canEdit = computed(() => canInTenant(props.tenant, 'operate'));
const crumbs = computed(() => crumbsFor('settings-points', props.tenant, tenantName(props.tenant)));
const dirty = computed(() => draft.value !== null && JSON.stringify(body(draft.value)) !== JSON.stringify(body(fromServer(server.value))));
const choice = computed({
    get: () => (draft.value?.enabled ? 'on' : 'off'),
    set: (value) => {
        draft.value.enabled = value === 'on';
    },
});

// 率は画面では %（1.5 など）、送るときは万分率（150）
function fromServer(points) {
    return { enabled: points.enabled === true, rate: points.rate_bp / 100 };
}

function body(d) {
    return { enabled: d.enabled, rate_bp: Math.round(Number(d.rate) * 100) };
}

function receive(data) {
    server.value = data.points;
    draft.value = fromServer(data.points);
    loaded.value = true;
}

async function load() {
    loadError.value = null;
    loaded.value = false;

    try {
        receive(await api('GET', base.value));
    } catch (e) {
        loadError.value = e;
    }
}

watch(() => props.tenant, load, { immediate: true });

async function save() {
    busy.value = true;
    errors.value = {};
    error.value = null;
    message.value = null;

    try {
        receive(await api('PUT', base.value, body(draft.value)));
        message.value = 'ポイントの設定を保存しました。';
    } catch (e) {
        errors.value = errorsOf(e);
        error.value = Object.keys(errors.value).length > 0 ? '入力を確認してください。誤りのある項目の下に理由を出しています。' : describe(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <SettingsLayout :tenant="tenant">
        <PageHeader :help="helpLink('settings-points', tenant)" :title="titleOf('settings-points')" :crumbs="crumbs" />
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>

        <ErrorState v-if="loadError" :error="loadError" />
        <LoadingText v-else-if="!loaded" />
        <template v-else>
            <!-- 負債の数はお金の段階の人にだけ返る（ほかは null） -->
            <div v-if="server.outstanding !== null" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <StatCard label="まだ使われていないポイント" :value="formatAmount(server.outstanding)" note="1 ポイント = 1 円。お客さんが使える分（店の負債）" value-test="points-outstanding" />
                <StatCard label="ポイントのある会員" :value="`${server.holders.toLocaleString('ja-JP')} 人`" value-test="points-holders" />
            </div>
            <AppPanel
                as="form"
                title="ポイント"
                description="会員のお客さんの注文に、商品の代金（値引きと利用ポイントを引いた後の税込みの額。送料は除く）× 率のポイントを、発送した時に付与します（1 ポイント未満は切り捨て）。会員は次の注文から、1 ポイント = 1 円として使えます。ポイントは、最後にポイントを付与・利用した日から 1 年で失効します。"
                data-test="points-form"
                @submit.prevent="save"
            >
                <AppField as="div" layout="row" label="ポイント" :error="firstError(errors, 'enabled')"
                    :hint="choice === 'on' ? '会員にポイントを付与し、使えるようにします。' : '新しい注文にはポイントを付与せず、使うこともできません。残っているポイントは削除されず、期限が来ると失効します。すでに付与予定のポイントは、発送した時に付与されます。'">
                    <ChoicePills v-model="choice" legend="ポイント" :options="[{ value: 'off', label: '使わない' }, { value: 'on', label: '使う' }]" :disabled="!canEdit" test-prefix="points-enabled" />
                </AppField>
                <AppField layout="row" label="付与率（%）" hint="0〜20% を 0.1% 単位で設定します。変更しても、前の注文は注文した時の率のままです。" :error="firstError(errors, 'rate_bp')">
                    <AppInput v-model="draft.rate" type="number" min="0" max="20" step="0.1" inputmode="decimal" required :disabled="!canEdit || !draft.enabled" data-test="points-rate" />
                </AppField>
                <AppText v-if="!canEdit" size="sm" tone="soft" data-test="settings-read-only">設定を変更できるのは「運用」以上の権限のある人だけです。</AppText>
                <FormActions v-else sticky>
                    <template v-if="dirty" #meta><span data-test="settings-unsaved">保存していない変更があります</span></template>
                    <AppButton type="submit" variant="primary" :disabled="busy" :loading="busy" data-test="save-points">ポイントの設定を保存する</AppButton>
                </FormActions>
            </AppPanel>
        </template>
    </SettingsLayout>
</template>
