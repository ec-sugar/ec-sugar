<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { api, describe } from '../api.js';
import { canInTenant } from '../tenants.js';
import { firstError, formatAmount } from '../format.js';
import { useOperation } from '../operation.js';
import AppButton from './ui/AppButton.vue';
import AppCheckbox from './ui/AppCheckbox.vue';
import AppField from './ui/AppField.vue';
import AppInput from './ui/AppInput.vue';
import AppNotice from './ui/AppNotice.vue';
import AppPanel from './ui/AppPanel.vue';
import AppSelect from './ui/AppSelect.vue';
import AppText from './ui/AppText.vue';
import FormActions from './ui/FormActions.vue';
import InsetSection from './ui/InsetSection.vue';

// 店が受け取る支払い（銀行振込・代金引換。ADR 0074）。Stripe を通さないので、入金は店の人が注文の画面で確認する。
// 振込先は店の鍵で暗号化して持ち、お金の段階の人にだけ見せる。変えるのはお金の段階でパスキーの再確認の後（サーバーが守る）
const props = defineProps({ tenant: { type: String, required: true } });

const status = ref(null);
const types = ref({});
const form = reactive({
    bank_transfer: false,
    bank_days: 7,
    bank_account: { bank: '', branch: '', type: 'ordinary', number: '', holder: '' },
    cod: false,
    cod_fees: [],
    cod_limit: '300000',
    members_only: false,
});
const { pending, errors, error, message, run } = useOperation();
const canEdit = computed(() => canInTenant(props.tenant, 'money'));
const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/payment-settings`);
const DAY_CHOICES = [1, 2, 3, 4, 5, 6, 7, 10, 14];
const MAX_TIERS = 10;

function fill(data) {
    const direct = data.direct;
    status.value = direct;
    types.value = direct.account_types ?? {};
    Object.assign(form, {
        bank_transfer: direct.bank_transfer,
        bank_days: direct.bank_days,
        bank_account: { bank: '', branch: '', type: 'ordinary', number: '', holder: '', ...(direct.bank_account ?? {}) },
        cod: direct.cod,
        cod_fees: direct.cod_fees.map((tier) => ({ below: tier.below === null ? '' : String(tier.below), fee: String(tier.fee) })),
        cod_limit: String(direct.cod_limit),
        members_only: direct.members_only === true,
    });
}

async function load() {
    error.value = null;

    try {
        fill(await api('GET', base.value));
    } catch (e) {
        error.value = describe(e);
    }
}

const toInt = (value) => {
    const n = Number.parseInt(String(value), 10);

    return Number.isNaN(n) ? null : n;
};

// 段の行の説明（「¥10,000 未満」「それより上」）
function tierLabel(index) {
    const previous = index === 0 ? null : toInt(form.cod_fees[index - 1].below);

    return index === form.cod_fees.length - 1
        ? (previous === null ? 'すべての額' : `${formatAmount(previous)} 以上`)
        : `${previous === null ? '' : `${formatAmount(previous)} 以上 `}この額未満`;
}

function addTier() {
    const last = form.cod_fees.at(-1);
    form.cod_fees.splice(form.cod_fees.length - 1, 0, { below: '', fee: last?.fee ?? '0' });
}

function removeTier(index) {
    form.cod_fees.splice(index, 1);
}

function save() {
    return run('save', () => api('PUT', `${base.value}/direct`, {
        bank_transfer: form.bank_transfer,
        bank_days: form.bank_days,
        bank_account: form.bank_transfer || form.bank_account.number !== '' ? { ...form.bank_account } : null,
        cod: form.cod,
        cod_fees: form.cod_fees.map((tier, index) => ({ below: index === form.cod_fees.length - 1 ? null : toInt(tier.below), fee: toInt(tier.fee) })),
        cod_limit: toInt(form.cod_limit),
        members_only: form.members_only,
    }), {
        done: '銀行振込・代金引換の設定を保存しました。',
        then: fill,
    });
}

watch(() => props.tenant, load, { immediate: true });
</script>

<template>
    <AppPanel data-test="direct-payment-panel" title="銀行振込・代金引換" description="Stripe を通さず、お店が直接受け取る支払いです。入金は、注文の画面で「入金を確認した」を押して確認します。使うかどうかは、それぞれ選べます（はじめは使いません）。">
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>

        <form v-if="status" class="flex flex-col gap-5" novalidate data-test="direct-payment-form" @submit.prevent="save">
            <InsetSection title="銀行振込（先払い）" description="お客さんは注文の後、下の口座へ振り込みます。入金を確認するまで注文は「入金待ち」になり、在庫は確保したままです。期限を過ぎて 1 日たっても入金を確認できないときは、注文を自動で取り消し、在庫・クーポン・ポイントを元に戻します。">
                <AppCheckbox v-model="form.bank_transfer" label="銀行振込を使う" :disabled="!canEdit" test="direct-bank" />
                <template v-if="form.bank_transfer">
                    <AppText v-if="!canEdit && status.bank_account_set" tone="soft">振込先は登録済みです（内容を見られるのは「会計」の権限のある人だけです）。</AppText>
                    <div v-if="canEdit" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <AppField label="銀行名" :error="firstError(errors, 'bank_account.bank')">
                            <AppInput v-model="form.bank_account.bank" maxlength="40" autocomplete="off" data-test="direct-bank-name" />
                        </AppField>
                        <AppField label="支店名" :error="firstError(errors, 'bank_account.branch')">
                            <AppInput v-model="form.bank_account.branch" maxlength="40" autocomplete="off" data-test="direct-bank-branch" />
                        </AppField>
                        <AppField label="口座の種類" :error="firstError(errors, 'bank_account.type')">
                            <AppSelect v-model="form.bank_account.type" data-test="direct-bank-type">
                                <option v-for="(label, value) in types" :key="value" :value="value">{{ label }}</option>
                            </AppSelect>
                        </AppField>
                        <AppField label="口座番号" hint="4〜8 桁の数字" :error="firstError(errors, 'bank_account.number')">
                            <AppInput v-model="form.bank_account.number" inputmode="numeric" maxlength="8" autocomplete="off" data-test="direct-bank-number" />
                        </AppField>
                        <AppField label="口座の名義" hint="通帳のとおりに（カタカナ）" :error="firstError(errors, 'bank_account.holder')">
                            <AppInput v-model="form.bank_account.holder" maxlength="60" autocomplete="off" data-test="direct-bank-holder" />
                        </AppField>
                        <AppField label="お振込みの期限" hint="ご注文の日から何日後の 23:59 まで" :error="firstError(errors, 'bank_days')">
                            <AppSelect v-model.number="form.bank_days" data-test="direct-bank-days">
                                <option v-for="days in DAY_CHOICES" :key="days" :value="days">{{ days }} 日後</option>
                            </AppSelect>
                        </AppField>
                    </div>
                </template>
            </InsetSection>

            <InsetSection title="代金引換" description="商品のお届けの時に、運送会社がお客さんから代金を受け取ります。代金を受け取る前に発送します。運送会社から代金が入金されたら、注文の画面で「代引きの入金を確認した」を押します。贈り物・定期便・予約・デジタルの品の注文では選べません。">
                <AppCheckbox v-model="form.cod" label="代金引換を使う" :disabled="!canEdit" test="direct-cod" />
                <template v-if="form.cod">
                    <AppText tone="soft">代引き手数料（税込み）は、ご注文の合計（手数料を含まない額）に応じて決まり、お客さんの支払い額に加わります。</AppText>
                    <div v-for="(tier, index) in form.cod_fees" :key="index" class="flex flex-col gap-3 md:flex-row md:items-end" data-test="direct-cod-tier">
                        <AppField v-if="index < form.cod_fees.length - 1" label="ご注文の合計（円）がこの額未満" :error="firstError(errors, `cod_fees.${index}.below`)">
                            <AppInput v-model="tier.below" inputmode="numeric" maxlength="7" autocomplete="off" :disabled="!canEdit" data-test="direct-cod-below" />
                        </AppField>
                        <AppText v-else tone="soft">{{ tierLabel(index) }}</AppText>
                        <AppField label="手数料（円・税込み）" :error="firstError(errors, `cod_fees.${index}.fee`)">
                            <AppInput v-model="tier.fee" inputmode="numeric" maxlength="6" autocomplete="off" :disabled="!canEdit" data-test="direct-cod-fee" />
                        </AppField>
                        <AppButton v-if="canEdit && index < form.cod_fees.length - 1" size="sm" @click="removeTier(index)">この行を削除</AppButton>
                    </div>
                    <AppText v-if="firstError(errors, 'cod_fees')" tone="danger">{{ firstError(errors, 'cod_fees') }}</AppText>
                    <FormActions v-if="canEdit && form.cod_fees.length < MAX_TIERS">
                        <AppButton size="sm" data-test="direct-cod-add-tier" @click="addTier">行を追加</AppButton>
                    </FormActions>
                    <AppField label="代金引換で受け付ける上限額（円・手数料を含む）" hint="運送会社の代金引換の上限に合わせます（多くは 30 万円）" :error="firstError(errors, 'cod_limit')">
                        <AppInput v-model="form.cod_limit" inputmode="numeric" maxlength="7" autocomplete="off" :disabled="!canEdit" data-test="direct-cod-limit" />
                    </AppField>
                </template>
            </InsetSection>

            <InsetSection v-if="form.bank_transfer || form.cod" title="会員だけにする" description="オンにすると、ログインした会員のお客さんだけが銀行振込・代金引換を選べます。ゲストのお客さんにはこの 2 つを表示せず、「会員の方だけ」とログインへのリンクを添えます（カードなどの Stripe の支払いは今までどおり。Stripe を使っていない店では、ゲストは注文できません）。いたずらの注文で在庫が確保されたままになることや、代引きの荷物の受け取り拒否を減らせます。">
                <AppCheckbox v-model="form.members_only" label="銀行振込・代金引換は会員だけ" :disabled="!canEdit" test="direct-members-only" />
                <AppText tone="soft">どちらの設定でも、入金待ちの注文（銀行振込・発送前の代金引換・コンビニ払い）は、同じメールアドレスで 3 件まで、同じ回線（インターネットの接続）から 5 件までです。入金の確認・取消・発送のどれかが済むと、また選べるようになります。</AppText>
            </InsetSection>

            <AppNotice tone="info" :live="false" data-test="direct-payment-legal">
                使う時は、「特定商取引法に基づく表記」のお支払いの方法と時期にも、銀行振込・代金引換を書き足してください（振込手数料・代引き手数料のお客さんの負担も）。
            </AppNotice>

            <FormActions v-if="canEdit">
                <AppButton type="submit" :disabled="pending !== null" :loading="pending === 'save'" data-test="save-direct-payment">保存する</AppButton>
            </FormActions>
        </form>
    </AppPanel>
</template>
