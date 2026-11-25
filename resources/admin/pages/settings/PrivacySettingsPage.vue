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
import ErrorState from '../../components/ui/ErrorState.vue';
import FormActions from '../../components/ui/FormActions.vue';
import LoadingText from '../../components/ui/LoadingText.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import SettingsLayout from '../../components/SettingsLayout.vue';

// 設定 › プライバシーポリシー（ADR 0094）。ショップの /privacy に出す本文。空ならショップのページもフッターのリンクも出ない
const props = defineProps({ tenant: { type: String, required: true } });

// サーバーの上限（ShopSettingsService::MAX_PRIVACY_POLICY）と同じ
const MAX_BODY = 20000;

// 書き始めのひな形（見出しの骨組みだけ。【】の中を店で書き替える）。法律の助言ではないので、中身の決まった言い回しは入れない
const PRIVACY_TEMPLATE = [
    '【お店の名前】（以下「当店」）は、お客さまの個人情報を次のとおり取り扱います。',
    '',
    '## 取得する情報',
    '- 【例: ご注文のときにいただくお名前・ご住所・電話番号・メールアドレス】',
    '- 【】',
    '',
    '## 使う目的',
    '- 【例: ご注文の商品をお届けするため】',
    '- 【】',
    '',
    '## 第三者への提供',
    '【どんな場合に、だれに提供するか（例: 配送の会社・決済の会社）】',
    '',
    '## 外部への送信（解析・広告のタグなど）',
    '【ショップで使っている解析・広告のタグの送信先は、ショップの「外部送信について」のページに自動で載ります。ここでは、そのことや使う目的を書けます】',
    '',
    '## 安全管理のための措置',
    '【】',
    '',
    '## 開示・訂正・利用停止などのお求め',
    '【お求めの受け付け方と、本人の確認の方法】',
    '',
    '## お問い合わせ先',
    '【窓口の名前・メールアドレスなど】',
    '',
    '## 改定',
    '【改定したときのお知らせの方法】',
    '',
    '【制定日: 2026 年 月 日】',
].join('\n');

const { loaded, loadError, draft, dirty, canEdit, busy, errors, error, message, save } = useShopSettingsSection(
    toRef(props, 'tenant'),
    'privacy',
    (value) => ({ path: 'privacy-policy', body: { body: value.body === '' ? null : value.body } }),
    'プライバシーポリシーを保存しました。ショップに反映されています。',
);
const crumbs = computed(() => crumbsFor('settings-privacy', props.tenant, tenantName(props.tenant)));
const empty = computed(() => (draft.value?.body ?? '').trim() === '');

function fillTemplate() {
    draft.value.body = PRIVACY_TEMPLATE;
}
</script>

<template>
    <SettingsLayout :tenant="tenant">
        <PageHeader :help="helpLink('settings-privacy', tenant)" :title="titleOf('settings-privacy')" :crumbs="crumbs" />
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>

        <ErrorState v-if="loadError" :error="loadError" />
        <LoadingText v-else-if="!loaded" />
        <AppPanel v-else as="form" title="プライバシーポリシー" description="ショップの「プライバシーポリシー」のページ（/privacy）に表示されます。本文があるあいだは、ショップのフッターにリンクが表示されます。空にすると、ページもリンクも表示されません。" @submit.prevent="save">
            <AppNotice tone="info" :live="false" data-test="privacy-caution">ひな形は見出しの骨組みで、法律の助言ではありません。内容はお店で確認してください（必要なら専門家に）。</AppNotice>
            <div v-if="empty && canEdit" class="flex flex-wrap items-center gap-3">
                <AppText tone="soft">まだありません。</AppText>
                <AppButton size="sm" data-test="privacy-template" @click="fillTemplate">ひな形を入れる</AppButton>
            </div>
            <AppField
                label="本文"
                :hint="`空の行で段落を分けます。「## 」で始まる行は見出し、「- 」か「・」で始まる行は箇条書きになります。HTML は使えません（${MAX_BODY.toLocaleString()} 字まで）`"
                :error="firstError(errors, 'body')"
            >
                <AppTextarea v-model="draft.body" rows="20" :maxlength="MAX_BODY" :disabled="!canEdit" data-test="privacy-body" />
            </AppField>
            <AppText v-if="!canEdit" size="sm" tone="soft" data-test="settings-read-only">設定を変更できるのは「運用」以上の権限のある人だけです。</AppText>
            <FormActions v-else sticky>
                <template v-if="dirty" #meta><span data-test="settings-unsaved">保存していない変更があります</span></template>
                <AppButton type="submit" variant="primary" :disabled="busy" :loading="busy" data-test="save-privacy">プライバシーポリシーを保存する</AppButton>
            </FormActions>
        </AppPanel>
    </SettingsLayout>
</template>
