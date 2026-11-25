<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { api, describe } from '../api.js';
import { canInTenant } from '../tenants.js';
import { firstError } from '../format.js';
import { useOperation } from '../operation.js';
import AppButton from './ui/AppButton.vue';
import AppCheckbox from './ui/AppCheckbox.vue';
import AppField from './ui/AppField.vue';
import AppNotice from './ui/AppNotice.vue';
import AppPanel from './ui/AppPanel.vue';
import AppSelect from './ui/AppSelect.vue';
import AppText from './ui/AppText.vue';
import FormActions from './ui/FormActions.vue';

// 使う支払いの手段（ADR 0058）。カードはいつも使う（Apple Pay・Google Pay はカードの中で、Stripe のダッシュボードで有効なら出る）。
// 足せるのはコンビニ払いと PayPay。Stripe のダッシュボードでも有効にしないと、お客さんがお支払いに進めないので、画面で案内する。
// 変えるのはお金の段階でパスキーの再確認の後（サーバーが守る）
const props = defineProps({ tenant: { type: String, required: true } });

const status = ref(null);
const dashboard = ref(null);
const form = reactive({ konbini: false, paypay: false, konbini_days: 3 });
const { pending, errors, error, message, run } = useOperation();
const canEdit = computed(() => canInTenant(props.tenant, 'money'));
const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/payment-settings`);
// お支払いの期限の日数（Stripe は 1〜60 日。長く在庫を押さえないよう 30 日まで）
const DAY_CHOICES = [1, 2, 3, 4, 5, 7, 10, 14, 21, 30];

function fill(data) {
    status.value = data.methods;
    dashboard.value = data.dashboard;
    Object.assign(form, { konbini: data.methods.konbini, paypay: data.methods.paypay, konbini_days: data.methods.konbini_days });
}

async function load() {
    error.value = null;

    try {
        fill(await api('GET', base.value));
    } catch (e) {
        error.value = describe(e);
    }
}

function save() {
    return run('save', () => api('PUT', `${base.value}/methods`, { ...form }), {
        done: '支払いの手段を保存しました。Stripe のダッシュボードでも、同じ手段を有効にしてください。',
        then: fill,
    });
}

watch(() => props.tenant, load, { immediate: true });
</script>

<template>
    <AppPanel data-test="payment-methods-panel" title="使う支払いの手段" description="クレジットカードはいつも使えます（Apple Pay・Google Pay はカード払いに含まれ、Stripe のダッシュボードで有効にすると表示されます）。コンビニ払いと PayPay を追加できます。">
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>

        <form v-if="status" class="flex flex-col gap-5" novalidate data-test="payment-methods-form" @submit.prevent="save">
            <div class="flex flex-col gap-2">
                <AppCheckbox v-model="form.konbini" label="コンビニ払い（120 円〜30 万円のご注文）" :disabled="!canEdit" test="method-konbini" />
                <AppText tone="soft">お客さんは注文の後、払込票の番号でコンビニ（ファミリーマート・ローソン・ミニストップ・セイコーマート）で払います。支払われるまで注文は「入金待ち」になり、在庫は確保したままです。期限までに支払われないときは、注文を自動で取り消し、在庫とクーポンの使用回数を元に戻します。定期便の申し込みには使えません。</AppText>
                <AppField v-if="form.konbini" label="お支払いの期限" hint="ご注文の日から何日後の 23:59 まで" :error="firstError(errors, 'konbini_days')">
                    <AppSelect v-model.number="form.konbini_days" :disabled="!canEdit" data-test="method-konbini-days">
                        <option v-for="days in DAY_CHOICES" :key="days" :value="days">{{ days }} 日後</option>
                    </AppSelect>
                </AppField>
            </div>

            <div class="flex flex-col gap-2">
                <AppCheckbox v-model="form.paypay" label="PayPay（50 円〜100 万円のご注文）" :disabled="!canEdit || (!status.paypay_available && !form.paypay)" test="method-paypay" />
                <AppText v-if="!status.paypay_available" tone="soft" data-test="paypay-unavailable">「Stripe へ接続」で使っている店では、PayPay は使えません（Stripe の制限）。自分の Stripe の鍵で設定した店だけが使えます。</AppText>
                <AppText v-else tone="soft">お客さんは PayPay のアプリで支払い、その場で支払いが完了します。</AppText>
                <AppText v-if="firstError(errors, 'paypay')" tone="danger">{{ firstError(errors, 'paypay') }}</AppText>
            </div>

            <!-- 開いた時からある案内（操作の結果でない）なので、通知（status）にせず注意書き（note）にする。開くたびに読み上げず、同じ画面の保存の通知とも重ならない -->
            <AppNotice tone="info" :live="false" data-test="payment-methods-dashboard">
                選んだ手段は、Stripe のダッシュボードの「<a :href="dashboard?.payment_methods" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2">決済手段</a>」でも有効にしてください。Stripe で有効になっていないと、お客さんがお支払いの画面に進めません（Stripe の審査が必要な手段もあります）。
            </AppNotice>

            <FormActions v-if="canEdit">
                <AppButton type="submit" :disabled="pending !== null" :loading="pending === 'save'" data-test="save-payment-methods">保存する</AppButton>
            </FormActions>
        </form>
    </AppPanel>
</template>
