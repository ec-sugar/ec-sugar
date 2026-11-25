<script setup>
import { computed, ref, watch } from 'vue';
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
import AppReveal from '../../components/ui/AppReveal.vue';
import AppTable from '../../components/ui/AppTable.vue';
import AppText from '../../components/ui/AppText.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import FormActions from '../../components/ui/FormActions.vue';
import LoadingText from '../../components/ui/LoadingText.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import SettingsLayout from '../../components/SettingsLayout.vue';

// 設定 › 配送と送料（ADR 0064）。配送の方法（通常便・クール便など。10 まで）ごとに、都道府県の送料と「この金額以上で無料」。
// 入力は全国・地域でまとめて入れ、県ごとに直せる（保存は県ごと）。いちばん上の方法が、方法を選んでいない商品の方法。
// 保存は表をまとめて置き換える（消した方法の商品は、いちばん上の方法になる）。額を決めるのはサーバー（ここは入力の手助けだけ）
const props = defineProps({ tenant: { type: String, required: true } });

const loadError = ref(null);
const loaded = ref(false);
const methods = ref([]);
const regions = ref([]);
const limits = ref({ methods: 10, fee: 100000, free_over: 10000000, name: 30 });
const saved = ref('');
const busy = ref(false);
const errors = ref({});
const error = ref(null);
const message = ref(null);

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/shipping-methods`);
const canEdit = computed(() => canInTenant(props.tenant, 'operate'));
const crumbs = computed(() => crumbsFor('settings-shipping', props.tenant, tenantName(props.tenant)));
const dirty = computed(() => loaded.value && JSON.stringify(payload()) !== saved.value);

function fromServer(data) {
    methods.value = data.methods.map((m) => ({ id: m.id, name: m.name, free_over: m.free_over ?? '', rates: { ...m.rates }, open: false }));
    regions.value = data.regions;
    limits.value = data.limits;
    saved.value = JSON.stringify(payload());
}

async function load() {
    loadError.value = null;
    loaded.value = false;

    try {
        fromServer(await api('GET', base.value));
        loaded.value = true;
    } catch (e) {
        loadError.value = e;
    }
}

watch(() => props.tenant, load, { immediate: true });

// 送る形。空の欄は null（サーバーが断る。0 と取り違えない）
function payload() {
    const value = (v) => (v === '' || v === null || v === undefined ? null : v);

    return methods.value.map((m) => ({
        id: m.id,
        name: m.name,
        free_over: value(m.free_over),
        rates: Object.fromEntries(Object.entries(m.rates).map(([prefecture, fee]) => [prefecture, value(fee)])),
    }));
}

// まとめた欄の値: 中の県が同じ額ならその額、違えば空（「県ごとに違う」）
function sameValue(method, prefectures) {
    const values = [...new Set(prefectures.map((p) => String(method.rates[p] ?? '')))];

    return values.length === 1 ? method.rates[prefectures[0]] : '';
}

function setAll(method, prefectures, value) {
    prefectures.forEach((p) => {
        method.rates[p] = value;
    });
}

const allPrefectures = computed(() => regions.value.flatMap((r) => r.prefectures));

function addMethod() {
    const first = methods.value[0];
    methods.value.push({ id: null, name: '', free_over: '', rates: Object.fromEntries(allPrefectures.value.map((p) => [p, first ? first.rates[p] : 0])), open: false });
}

function move(index, offset) {
    const [method] = methods.value.splice(index, 1);
    methods.value.splice(index + offset, 0, method);
}

async function save() {
    busy.value = true;
    errors.value = {};
    error.value = null;
    message.value = null;

    try {
        fromServer(await api('PUT', base.value, { methods: payload() }));
        message.value = '送料を保存しました。';
    } catch (e) {
        errors.value = errorsOf(e);
        error.value = Object.keys(errors.value).length > 0 ? '入力を確認してください。誤りのある項目の下に理由を出しています。' : describe(e);
    } finally {
        busy.value = false;
    }
}

// その方法の県の欄に誤りがあるか（閉じた「県ごとに直す」を開く手がかり）
function rateError(index, prefectures) {
    return prefectures.map((p) => firstError(errors.value, `methods.${index}.rates.${p}`)).find((m) => m) ?? null;
}
</script>

<template>
    <SettingsLayout :tenant="tenant">
        <PageHeader :help="helpLink('settings-shipping', tenant)" :title="titleOf('settings-shipping')" :crumbs="crumbs" />
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>

        <ErrorState v-if="loadError" :error="loadError" />
        <LoadingText v-else-if="!loaded" />
        <form v-else class="flex flex-col gap-6" novalidate data-test="shipping-form" @submit.prevent="save">
            <AppPanel
                v-for="(method, index) in methods"
                :key="method.id ?? `new-${index}`"
                :title="method.name || `配送の方法 ${index + 1}`"
                :description="index === 0 ? '配送の方法を選んでいない商品は、この方法で送ります。' : '商品の登録の画面でこの方法を選んだ商品を、この方法で送ります。配送の方法が違う商品をいっしょに買うと、別々の荷物で送り、送料は方法ごとにかかります。'"
                data-test="shipping-method"
            >
                <AppField layout="row" label="方法の名前" hint="お客さんにも表示されます（例: 通常便・クール便）" :error="firstError(errors, `methods.${index}.name`)">
                    <AppInput v-model="method.name" :maxlength="limits.name" required :disabled="!canEdit" data-test="shipping-method-name" />
                </AppField>
                <AppField layout="row" label="全国の送料（円）" hint="入力すると、すべての都道府県が同じ額になります。地域で違うときは下の表で設定します" :error="rateError(index, allPrefectures)">
                    <AppInput
                        :model-value="sameValue(method, allPrefectures)"
                        type="number" min="0" :max="limits.fee" step="1" inputmode="numeric" placeholder="地域で違う"
                        :disabled="!canEdit" :data-test="index === 0 ? 'shipping-fee' : 'shipping-method-fee'"
                        @update:model-value="setAll(method, allPrefectures, $event)"
                    />
                </AppField>
                <AppField layout="row" label="この金額以上で送料無料（円）" optional hint="この方法で送る商品の小計（クーポンの値引き後）で判断します。空欄なら送料無料にしません" :error="firstError(errors, `methods.${index}.free_over`)">
                    <AppInput v-model="method.free_over" type="number" min="1" :max="limits.free_over" step="1" inputmode="numeric" :disabled="!canEdit" :data-test="index === 0 ? 'free-shipping-over' : 'shipping-method-free-over'" />
                </AppField>

                <AppTable label="地域ごとの送料">
                    <thead>
                        <tr><th>地域</th><th class="num">送料（円）</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="region in regions" :key="region.name" data-test="shipping-region">
                            <td>{{ region.name }}</td>
                            <td class="num">
                                <AppInput
                                    :model-value="sameValue(method, region.prefectures)"
                                    type="number" min="0" :max="limits.fee" step="1" inputmode="numeric" placeholder="県ごとに違う"
                                    :aria-label="`${region.name}の送料`" :disabled="!canEdit" :data-test="`region-fee-${region.name}`"
                                    @update:model-value="setAll(method, region.prefectures, $event)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </AppTable>

                <div class="flex flex-wrap items-center gap-2">
                    <AppButton size="sm" variant="ghost" :aria-expanded="method.open ? 'true' : 'false'" data-test="shipping-prefectures-toggle" @click="method.open = !method.open">
                        {{ method.open ? '県ごとの送料を閉じる' : '県ごとに編集' }}
                    </AppButton>
                    <template v-if="canEdit">
                        <AppButton size="sm" variant="ghost" :disabled="index === 0" :aria-label="`${method.name || index + 1} を上へ`" @click="move(index, -1)">上へ</AppButton>
                        <AppButton size="sm" variant="ghost" :disabled="index === methods.length - 1" :aria-label="`${method.name || index + 1} を下へ`" @click="move(index, 1)">下へ</AppButton>
                        <AppButton v-if="methods.length > 1" size="sm" variant="ghost" data-test="remove-shipping-method" @click="methods.splice(index, 1)">この方法を削除</AppButton>
                    </template>
                </div>
                <AppReveal :show="method.open || !!rateError(index, allPrefectures)">
                    <AppTable label="県ごとの送料">
                        <thead>
                            <tr><th>都道府県</th><th class="num">送料（円）</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="prefecture in allPrefectures" :key="prefecture">
                                <td>{{ prefecture }}</td>
                                <td class="num">
                                    <AppInput
                                        v-model="method.rates[prefecture]" type="number" min="0" :max="limits.fee" step="1" inputmode="numeric"
                                        :aria-label="`${prefecture}の送料`" :aria-invalid="!!firstError(errors, `methods.${index}.rates.${prefecture}`)" :disabled="!canEdit" :data-test="`prefecture-fee-${prefecture}`"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </AppTable>
                </AppReveal>
                <AppNotice v-if="firstError(errors, `methods.${index}.rates`) || firstError(errors, `methods.${index}.id`)" tone="error">
                    {{ firstError(errors, `methods.${index}.rates`) || firstError(errors, `methods.${index}.id`) }}
                </AppNotice>
            </AppPanel>

            <AppNotice v-if="firstError(errors, 'methods')" tone="error">{{ firstError(errors, 'methods') }}</AppNotice>
            <AppText size="sm" tone="soft">離島への追加の送料は設定できません。かかる場合は、設定 › 注文の案内の「届く目安」に書いてください。削除した方法を選んでいた商品は、いちばん上の方法で送ります。</AppText>
            <div v-if="canEdit && methods.length < limits.methods">
                <AppButton size="sm" data-test="add-shipping-method" @click="addMethod">配送の方法を追加</AppButton>
            </div>
            <AppText v-if="!canEdit" size="sm" tone="soft" data-test="settings-read-only">設定を変更できるのは「運用」以上の権限のある人だけです。</AppText>
            <FormActions v-else sticky>
                <template v-if="dirty" #meta><span data-test="settings-unsaved">保存していない変更があります</span></template>
                <AppButton type="submit" variant="primary" :disabled="busy" :loading="busy" data-test="save-shipping">送料を保存する</AppButton>
            </FormActions>
        </form>
    </SettingsLayout>
</template>
