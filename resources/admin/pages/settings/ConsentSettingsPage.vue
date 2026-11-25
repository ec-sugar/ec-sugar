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
import ChoicePills from '../../components/ui/ChoicePills.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import FormActions from '../../components/ui/FormActions.vue';
import LoadingText from '../../components/ui/LoadingText.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import SettingsLayout from '../../components/SettingsLayout.vue';

// 設定 › 同意バナー（ADR 0034 の 4 の置き換え）。解析・広告のタグ（App）を、同意したお客さんにだけ読み込むか。
// 既定は出さない（タグは最初から読み込み、外部送信の公表のページだけ）。海外のお客さんを相手にする店が「出す」にする
const props = defineProps({ tenant: { type: String, required: true } });

const { loaded, loadError, draft, dirty, canEdit, busy, errors, error, message, save } = useShopSettingsSection(
    toRef(props, 'tenant'),
    'consent',
    (consent) => ({ path: 'consent-banner', body: { show: consent.show } }),
    '同意バナーの設定を保存しました。',
);
const options = [
    { value: 'off', label: '表示しない' },
    { value: 'on', label: '表示する' },
];
const choice = computed({
    get: () => (draft.value?.show ? 'on' : 'off'),
    set: (value) => {
        draft.value.show = value === 'on';
    },
});
const crumbs = computed(() => crumbsFor('settings-consent', props.tenant, tenantName(props.tenant)));
</script>

<template>
    <SettingsLayout :tenant="tenant">
        <PageHeader :help="helpLink('settings-consent', tenant)" :title="titleOf('settings-consent')" :crumbs="crumbs" />
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>

        <ErrorState v-if="loadError" :error="loadError" />
        <LoadingText v-else-if="!loaded" />
        <AppPanel
            v-else
            as="form"
            title="同意バナー"
            description="解析・広告のタグの App（Google アナリティクス・Meta ピクセルなど）を使うときに、ショップの下に「外部への送信と Cookie について」のバナーを表示するかを選びます。どちらでも、送信先と送信する情報はフッターの「外部送信について」のページに自動で載り、注文の入力・確認やご注文の状況の画面にはタグを入れません。"
            @submit.prevent="save"
        >
            <AppField
                layout="row"
                label="同意バナー"
                :hint="choice === 'on'
                    ? 'バナーで「同意する」を選んだお客さんにだけ、タグを読み込みます。同意しないお客さんの分は計測されません。欧州など、事前に同意を得ることが法律で求められている国のお客さんを相手にする店向けです。'
                    : 'タグは、ショップを開いたときから読み込みます（日本の法律で求められているのは、送信先と送信する情報の公表です）。日本のお客さんを相手にする店は、ふつうはこちらです。'"
                :error="firstError(errors, 'show')"
            >
                <ChoicePills v-model="choice" legend="同意バナー" :options="options" :disabled="!canEdit" test-prefix="consent-banner" />
            </AppField>
            <AppText v-if="!canEdit" size="sm" tone="soft" data-test="settings-read-only">設定を変更できるのは「運用」以上の権限のある人だけです。</AppText>
            <FormActions v-else sticky>
                <template v-if="dirty" #meta><span data-test="settings-unsaved">保存していない変更があります</span></template>
                <AppButton type="submit" variant="primary" :disabled="busy" :loading="busy" data-test="save-consent-banner">同意バナーの設定を保存する</AppButton>
            </FormActions>
        </AppPanel>
    </SettingsLayout>
</template>
