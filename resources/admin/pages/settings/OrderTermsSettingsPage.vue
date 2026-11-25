<script setup>
import { computed, toRef } from 'vue';
import { firstError } from '../../format.js';
import { crumbsFor, titleOf } from '../../navigation.js';
import { helpLink } from '../../help/index.js';
import { useShopSettingsSection } from '../../shopSettings.js';
import { tenantName } from '../../tenants.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppField from '../../components/ui/AppField.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppText from '../../components/ui/AppText.vue';
import AppTextarea from '../../components/ui/AppTextarea.vue';
import CheckList from '../../components/ui/CheckList.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import FormActions from '../../components/ui/FormActions.vue';
import LoadingText from '../../components/ui/LoadingText.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import SettingsLayout from '../../components/SettingsLayout.vue';

// 設定 › 注文の案内（で 1 画面に）。注文の最終確認の画面に出す、届く目安と返品・交換の条件
const props = defineProps({ tenant: { type: String, required: true } });

const { loaded, loadError, draft, dirty, canEdit, busy, errors, error, message, save } = useShopSettingsSection(
    toRef(props, 'tenant'),
    'terms',
    (terms) => ({ path: 'order-terms', body: { delivery: terms.delivery, returns: terms.returns } }),
    '注文の案内を保存しました。',
);
// 0 円の品を売るか（既定は売らない）。注文の案内と同じ画面に、別の保存で
const free = useShopSettingsSection(
    toRef(props, 'tenant'),
    'free',
    (value) => ({ path: 'free-items', body: { sell: value.sell } }),
    '0 円の品の扱いを保存しました。',
);
const freeChoice = computed({
    get: () => (free.draft.value?.sell ? ['sell'] : []),
    set: (values) => {
        free.draft.value.sell = values.includes('sell');
    },
});
const crumbs = computed(() => crumbsFor('settings-order-terms', props.tenant, tenantName(props.tenant)));
</script>

<template>
    <SettingsLayout :tenant="tenant">
        <PageHeader :help="helpLink('settings-order-terms', tenant)" :title="titleOf('settings-order-terms')" :crumbs="crumbs" />
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>

        <ErrorState v-if="loadError" :error="loadError" />
        <LoadingText v-else-if="!loaded" />
        <AppPanel v-else as="form" title="注文の案内" description="注文の最終確認の画面に表示されます（特定商取引法で、注文の確定前に表示することが求められている項目です）。" @submit.prevent="save">
            <AppField layout="row" label="届く目安" hint="例: ご注文から 3〜5 日でお届けします（200 字まで）" :error="firstError(errors, 'delivery')">
                <AppTextarea v-model="draft.delivery" rows="2" maxlength="200" :disabled="!canEdit" data-test="terms-delivery" />
            </AppField>
            <AppField layout="row" label="返品・交換の条件" hint="例: 商品到着後 30 日以内、未使用のものに限ります（500 字まで）" :error="firstError(errors, 'returns')">
                <AppTextarea v-model="draft.returns" rows="4" maxlength="500" :disabled="!canEdit" data-test="terms-returns" />
            </AppField>
            <AppText v-if="!canEdit" size="sm" tone="soft" data-test="settings-read-only">設定を変更できるのは「運用」以上の権限のある人だけです。</AppText>
            <FormActions v-else sticky>
                <template v-if="dirty" #meta><span data-test="settings-unsaved">保存していない変更があります</span></template>
                <AppButton type="submit" variant="primary" :disabled="busy" :loading="busy" data-test="save-terms">注文の案内を保存する</AppButton>
            </FormActions>
        </AppPanel>

        <AppNotice v-if="free.message.value" tone="success">{{ free.message.value }}</AppNotice>
        <AppNotice v-if="free.error.value" tone="error">{{ free.error.value }}</AppNotice>
        <AppPanel
            v-if="loaded"
            as="form"
            title="0 円の品"
            description="オンにすると、価格が 0 円の商品をショップに表示し、支払いなしで注文を受け付けます（お客さんにご注文のメールが届きます）。オンにしても、0 円の商品は 1 回のご注文で 1 点まで、同じお客さんからの 0 円のご注文は 1 時間に 3 回までです。"
            @submit.prevent="free.save"
        >
            <AppField layout="row" label="0 円の品を売る" hint="オフのときは、0 円の商品はショップに表示されません（価格の入力間違いで、商品が無料で売れてしまうのを防ぎます）">
                <CheckList v-model="freeChoice" legend="0 円の品を売る" :options="[{ value: 'sell', label: '0 円の商品をショップに表示して、注文を受け付ける', depth: 0 }]" test-prefix="free-items" />
            </AppField>
            <FormActions v-if="free.canEdit.value" sticky>
                <template v-if="free.dirty.value" #meta><span data-test="settings-unsaved-free">保存していない変更があります</span></template>
                <AppButton type="submit" variant="primary" :disabled="free.busy.value" :loading="free.busy.value" data-test="save-free-items">0 円の品の扱いを保存する</AppButton>
            </FormActions>
        </AppPanel>
    </SettingsLayout>
</template>
