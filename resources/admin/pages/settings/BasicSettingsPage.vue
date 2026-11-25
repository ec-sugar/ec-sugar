<script setup>
import { computed, ref, toRef, watch } from 'vue';
import { api, describe } from '../../api.js';
import { ConfirmationCancelled, withConfirmation } from '../../confirmation.js';
import { errorsOf, firstError } from '../../format.js';
import { crumbsFor, titleOf } from '../../navigation.js';
import { helpLink } from '../../help/index.js';
import { session } from '../../session.js';
import { useShopSettingsSection } from '../../shopSettings.js';
import { rememberTenant, storefrontUrl, tenantName } from '../../tenants.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppField from '../../components/ui/AppField.vue';
import AppInput from '../../components/ui/AppInput.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppText from '../../components/ui/AppText.vue';
import DescriptionList from '../../components/ui/DescriptionList.vue';
import ChoicePills from '../../components/ui/ChoicePills.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import FormActions from '../../components/ui/FormActions.vue';
import LoadingText from '../../components/ui/LoadingText.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import SettingsLayout from '../../components/SettingsLayout.vue';

// 設定 › 基本（中身は ショップの公開）。ショップの名前と URL、名前を変える欄（オーナーだけ）、準備中と公開の切り替え、準備中のショップに入るパスワード。
// パスワードは書きかけの写し（shopSettings.js）に置かず、この画面の中だけで持つ。サーバーは置いたかどうかだけを返す
const props = defineProps({ tenant: { type: String, required: true } });

const { loaded, loadError, draft, dirty, canEdit, busy, errors, error, message, save } = useShopSettingsSection(
    toRef(props, 'tenant'),
    'publish',
    (publish) => ({ path: 'publish', body: { status: publish.status } }),
    'ショップの公開の状態を保存しました。',
);
const statusOptions = [
    { value: 'preparing', label: '準備中' },
    { value: 'open', label: '公開' },
];

const password = ref('');
// パスワードを置いたか。保存したあとはこの画面の答えを使う（書きかけの写しの「変更あり」にしない）
const savedHasPassword = ref(null);
const hasPassword = computed(() => savedHasPassword.value ?? draft.value?.has_password === true);
const passwordBusy = ref(false);
const passwordErrors = ref({});
const passwordError = ref(null);
const passwordMessage = ref(null);

async function savePassword(value) {
    passwordBusy.value = true;
    passwordErrors.value = {};
    passwordError.value = null;
    passwordMessage.value = null;

    try {
        const data = await api('PUT', `/api/tenants/${encodeURIComponent(props.tenant)}/shop-settings/storefront-password`, { password: value });
        savedHasPassword.value = data.publish.has_password === true;
        password.value = '';
        passwordMessage.value = value === null ? 'パスワードを解除しました。準備中のショップには、だれも入れません。' : 'パスワードを保存しました。前のパスワードで入っていた人も、新しいパスワードの入力が必要になります。';
    } catch (e) {
        passwordErrors.value = errorsOf(e);
        passwordError.value = Object.keys(passwordErrors.value).length > 0 ? '入力を確認してください。誤りのある項目の下に理由を出しています。' : describe(e);
    } finally {
        passwordBusy.value = false;
    }
}

// ショップの名前を変える。オーナーだけ・パスキーでもう一度確認してから（守るのはサーバー。ここは欄の出し分けだけ）。
// 変えたら店の切り替えとメニューの名前もすぐ新しくする
const isOwner = computed(() => session.operator?.role === 'owner');
// 欄の初めの値は今の名前（店の一覧を読み終えた後・別の店へ移った時も合わせる）
const name = ref(tenantName(props.tenant));
watch(() => tenantName(props.tenant), (value) => {
    name.value = value;
});
const nameBusy = ref(false);
const nameErrors = ref({});
const nameError = ref(null);
const nameMessage = ref(null);

async function saveName() {
    nameBusy.value = true;
    nameErrors.value = {};
    nameError.value = null;
    nameMessage.value = null;

    try {
        const id = props.tenant;
        const data = await withConfirmation(() => api('PUT', `/api/tenants/${encodeURIComponent(id)}/shop-settings/name`, { name: name.value }));
        rememberTenant(id, data.tenant.name);
        name.value = data.tenant.name;
        nameMessage.value = data.changed ? 'ショップの名前を変更しました。ショップの画面とこれから送るメールに、新しい名前が表示されます。' : '名前は変わっていません。';
    } catch (e) {
        nameErrors.value = errorsOf(e);
        nameError.value = e instanceof ConfirmationCancelled
            ? e.userMessage
            : Object.keys(nameErrors.value).length > 0 ? '入力を確認してください。誤りのある項目の下に理由を出しています。' : describe(e);
    } finally {
        nameBusy.value = false;
    }
}

const crumbs = computed(() => crumbsFor('settings-basic', props.tenant, tenantName(props.tenant)));
// ショップの名前と URL（名前はオーナーが下の欄で変えられる。URL は店を作るときに決める）
const shop = computed(() => [
    { label: 'ショップの名前', value: tenantName(props.tenant) },
    { label: 'ショップの URL', value: storefrontUrl(props.tenant), mono: true },
]);
</script>

<template>
    <SettingsLayout :tenant="tenant">
        <PageHeader :help="helpLink('settings-basic', tenant)" :title="titleOf('settings-basic')" :crumbs="crumbs" />
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>

        <ErrorState v-if="loadError" :error="loadError" />
        <LoadingText v-else-if="!loaded" />
        <template v-else>
            <AppPanel title="ショップ" data-test="settings-shop-info">
                <DescriptionList :items="shop" />
            </AppPanel>

            <template v-if="isOwner">
                <AppNotice v-if="nameMessage" tone="success" data-test="shop-name-saved">{{ nameMessage }}</AppNotice>
                <AppNotice v-if="nameError" tone="error">{{ nameError }}</AppNotice>
                <AppPanel as="form" title="ショップの名前" description="ショップの上部の名前・ページのタイトル・お客さんへのメールの差出人の名前と件名に表示されます。変更すると、次に開いた画面とこれから送るメールから新しい名前になります（送信済みのメールは変わりません）。変更するときは、パスキーでもう一度確認します。" @submit.prevent="saveName">
                    <AppField layout="row" label="ショップの名前" hint="1〜100 文字。改行は使えません" :error="firstError(nameErrors, 'name')">
                        <AppInput v-model="name" maxlength="100" autocomplete="organization" required data-test="shop-name" />
                    </AppField>
                    <FormActions>
                        <AppButton type="submit" variant="primary" :disabled="nameBusy" :loading="nameBusy" data-test="save-shop-name">名前を保存する</AppButton>
                    </FormActions>
                </AppPanel>
            </template>

            <AppPanel as="form" title="公開の状態" description="準備中のあいだ、ショップを開いた人には「準備中です」の画面とパスワードの欄だけが表示されます（検索結果にも載りません）。公開にすると、だれでも見られます（公開にするときは、パスキーでもう一度確認します）。特定商取引法に基づく表記と、ご注文の確認のメールのリンクは、準備中でも見られます。" @submit.prevent="save">
                <AppField layout="row" label="ショップ" :error="firstError(errors, 'status')">
                    <ChoicePills v-model="draft.status" legend="ショップの公開の状態" :options="statusOptions" :disabled="!canEdit" test-prefix="publish-status" />
                </AppField>
                <AppText v-if="!canEdit" size="sm" tone="soft" data-test="settings-read-only">設定を変更できるのは「運用」以上の権限のある人だけです。</AppText>
                <FormActions v-else sticky>
                    <template v-if="dirty" #meta><span data-test="settings-unsaved">保存していない変更があります</span></template>
                    <AppButton type="submit" variant="primary" :disabled="busy" :loading="busy" data-test="save-publish">公開の状態を保存する</AppButton>
                </FormActions>
            </AppPanel>

            <AppNotice v-if="passwordMessage" tone="success">{{ passwordMessage }}</AppNotice>
            <AppNotice v-if="passwordError" tone="error">{{ passwordError }}</AppNotice>
            <AppPanel
                as="form"
                title="準備中に入るパスワード"
                description="公開前のショップを制作会社や仲間に見てもらうときに伝えるパスワードです。パスワードを入力した人は、Stripe のテスト用の鍵を使っているときだけ、注文まで試せます（本番の鍵なら、公開してから注文できます）。パスワードを変えると、前に入った人も新しいパスワードの入力が必要になります。"
                @submit.prevent="savePassword(password)"
            >
                <AppText size="sm" data-test="publish-password-state">{{ hasPassword ? 'パスワードを設定しています。' : 'パスワードはまだありません（準備中のショップには、だれも入れません）。' }}</AppText>
                <AppField v-if="canEdit" layout="row" label="新しいパスワード" hint="8〜64 文字。保存したあとは表示しません" :error="firstError(passwordErrors, 'password')">
                    <AppInput v-model="password" type="password" minlength="8" maxlength="64" autocomplete="new-password" required data-test="publish-password" />
                </AppField>
                <FormActions v-if="canEdit">
                    <AppButton v-if="hasPassword" type="button" :disabled="passwordBusy" data-test="clear-publish-password" @click="savePassword(null)">パスワードを解除</AppButton>
                    <AppButton type="submit" variant="primary" :disabled="passwordBusy" :loading="passwordBusy" data-test="save-publish-password">パスワードを保存する</AppButton>
                </FormActions>
            </AppPanel>
        </template>
    </SettingsLayout>
</template>
