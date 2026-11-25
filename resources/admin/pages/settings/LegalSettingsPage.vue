<script setup>
import { computed, toRef } from 'vue';
import { firstError } from '../../format.js';
import { crumbsFor, titleOf } from '../../navigation.js';
import { helpLink } from '../../help/index.js';
import { useShopSettingsSection } from '../../shopSettings.js';
import { tenantName } from '../../tenants.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppField from '../../components/ui/AppField.vue';
import AppInput from '../../components/ui/AppInput.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppText from '../../components/ui/AppText.vue';
import AppTextarea from '../../components/ui/AppTextarea.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import FormActions from '../../components/ui/FormActions.vue';
import LoadingText from '../../components/ui/LoadingText.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import SettingsLayout from '../../components/SettingsLayout.vue';

// 設定 › 特商法の表記（で 1 画面に）。ショップの「特定商取引法に基づく表記」のページに、この順で出る
const props = defineProps({ tenant: { type: String, required: true } });

// 特商法の表記のひな形（項目の名前だけ。内容は店ごとに書く）
const LEGAL_TEMPLATE = ['販売業者', '運営統括責任者', '所在地', '電話番号', 'メールアドレス', '販売価格', '商品代金以外の必要料金', 'お支払いの方法', 'お支払いの時期', '引き渡しの時期', '返品・交換について'];
const MAX_LEGAL = 20;

const { loaded, loadError, draft: legal, dirty, canEdit, busy, errors, error, message, save } = useShopSettingsSection(
    toRef(props, 'tenant'),
    'legal',
    (rows) => ({ path: 'legal', body: { items: rows.map((row) => ({ label: row.label, value: row.value })) } }),
    '特定商取引法に基づく表記を保存しました。ショップに反映されています。',
);
const crumbs = computed(() => crumbsFor('settings-legal', props.tenant, tenantName(props.tenant)));

function fillTemplate() {
    legal.value.splice(0, legal.value.length, ...LEGAL_TEMPLATE.map((label) => ({ label, value: '' })));
}

function move(index, offset) {
    const [row] = legal.value.splice(index, 1);
    legal.value.splice(index + offset, 0, row);
}
</script>

<template>
    <SettingsLayout :tenant="tenant">
        <PageHeader :help="helpLink('settings-legal', tenant)" :title="titleOf('settings-legal')" :crumbs="crumbs" />
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>

        <ErrorState v-if="loadError" :error="loadError" />
        <LoadingText v-else-if="!loaded" />
        <AppPanel v-else as="form" title="特定商取引法に基づく表記" description="ショップの「特定商取引法に基づく表記」のページに、この順で表示されます。個人の店で所在地や電話番号を載せない場合は「請求があれば遅滞なく開示します」と書けます。" @submit.prevent="save">
            <div v-if="legal.length === 0" class="flex flex-wrap items-center gap-3">
                <AppText tone="soft">まだありません。ショップには「準備中」と表示されます。</AppText>
                <AppButton v-if="canEdit" size="sm" data-test="legal-template" @click="fillTemplate">よく使う項目を入れる</AppButton>
            </div>
            <div v-for="(row, index) in legal" :key="index" class="grid grid-cols-1 items-start gap-4 md:grid-cols-[14rem_1fr_auto]" data-test="legal-row">
                <AppField :label="`${index + 1} つ目の項目`" :error="firstError(errors, `items.${index}.label`)">
                    <AppInput v-model="row.label" maxlength="30" required :disabled="!canEdit" data-test="legal-label" />
                </AppField>
                <AppField :label="`${index + 1} つ目の内容`" hint="改行できます（1000 字まで）" :error="firstError(errors, `items.${index}.value`)">
                    <AppTextarea v-model="row.value" rows="2" maxlength="1000" required :disabled="!canEdit" data-test="legal-value" />
                </AppField>
                <div v-if="canEdit" class="flex gap-1 md:pt-7">
                    <AppButton size="sm" variant="ghost" :disabled="index === 0" :aria-label="`${index + 1} つ目を上へ`" @click="move(index, -1)">上へ</AppButton>
                    <AppButton size="sm" variant="ghost" :disabled="index === legal.length - 1" :aria-label="`${index + 1} つ目を下へ`" @click="move(index, 1)">下へ</AppButton>
                    <AppButton size="sm" variant="ghost" :aria-label="`${index + 1} つ目を削除`" @click="legal.splice(index, 1)">削除</AppButton>
                </div>
            </div>
            <AppNotice v-if="firstError(errors, 'items')" tone="error">{{ firstError(errors, 'items') }}</AppNotice>
            <div v-if="canEdit && legal.length < MAX_LEGAL">
                <AppButton size="sm" data-test="add-legal" @click="legal.push({ label: '', value: '' })">項目を追加</AppButton>
            </div>
            <AppText v-if="!canEdit" size="sm" tone="soft" data-test="settings-read-only">設定を変更できるのは「運用」以上の権限のある人だけです。</AppText>
            <FormActions v-else sticky>
                <template v-if="dirty" #meta><span data-test="settings-unsaved">保存していない変更があります</span></template>
                <AppButton type="submit" variant="primary" :disabled="busy" :loading="busy" data-test="save-legal">表記を保存する</AppButton>
            </FormActions>
        </AppPanel>
    </SettingsLayout>
</template>
