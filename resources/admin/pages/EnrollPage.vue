<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, ApiError, describe } from '../api.js';
import { createPasskey, passkeyEnvironment, passkeysSupported } from '../webauthn.js';
import { session, signedIn } from '../session.js';
import AppButton from '../components/ui/AppButton.vue';
import AppField from '../components/ui/AppField.vue';
import AppInput from '../components/ui/AppInput.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppText from '../components/ui/AppText.vue';
import AuthLayout from '../components/ui/AuthLayout.vue';
import CheckItems from '../components/ui/CheckItems.vue';
import FeatureList from '../components/ui/FeatureList.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import StepPath from '../components/ui/StepPath.vue';

// 登録用のリンクから管理画面に入るまでのウィザード（ADR 0031）: ようこそ → このブラウザの確認 → 登録 → できた
const route = useRoute();
const router = useRouter();
const supported = passkeysSupported();
const state = ref('checking'); // checking | invalid | signed-in | wizard
const step = ref('welcome'); // welcome | check | register | done
const operator = ref(null);
const system = ref(null); // サーバーのバージョン（登録用のリンクを確認した後にだけ届く）
const environment = ref(null);
// この画面を組み立てたときのバージョン（docker/Dockerfile が焼き込む）。サーバーのバージョンと違えば、更新の後の古い画面が残っている
const screenRevision = import.meta.env.VITE_EC_SUGAR_REVISION || null;
const name = ref('この端末のパスキー');
const busy = ref(false);
const error = ref(null);

const steps = [
    { value: 'welcome', label: 'ようこそ' },
    { value: 'check', label: '確認' },
    { value: 'register', label: '登録' },
    { value: 'done', label: '完了' },
];
function versionLabel() {
    if (!system.value?.short) {
        return 'システムのバージョン: バージョン情報なし（開発中の環境）';
    }

    const date = system.value.date
        ? `、${new Date(system.value.date).toLocaleString('ja-JP', { year: 'numeric', month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' })}`
        : '';

    return `システムのバージョン: ${system.value.short}${date}`;
}

const checks = computed(() => (environment.value === null ? [] : [
    {
        key: 'version',
        ok: (system.value?.revision ?? null) === screenRevision,
        optional: true,
        label: versionLabel(),
        fix: 'この画面が古いままです（更新の直後に起きることがあります）。端末に出た登録用のリンクを、もう一度開き直してください。',
    },
    {
        key: 'secure',
        ok: environment.value.secure,
        label: '安全な接続（https）で開いている',
        fix: '「https」で始まるアドレスで開き直してください。証明書の警告を無視して開いたページでは、ブラウザがパスキーを使わせません。',
    },
    {
        key: 'supported',
        ok: environment.value.supported,
        label: 'このブラウザがパスキーに対応している',
        fix: '最新の Chrome・Safari・Firefox・Edge に更新するか、別のブラウザで開いてください。',
    },
    {
        key: 'platform',
        ok: environment.value.platform,
        optional: true,
        label: 'この端末の指紋・顔・暗証番号で登録できる',
        fix: 'この端末では使えないようです。次の画面で、スマートフォンやセキュリティキーを選んで登録できます。',
    },
]));
const welcome = [
    { icon: 'key', text: 'パスキーはパスワードの代わりです。覚えるものはありません。' },
    { icon: 'shield', text: '入るときは、この端末の指紋・顔・暗証番号で本人を確認します。偽のサイトでは使えないので、盗まれにくい仕組みです。' },
    { icon: 'check', text: '1〜2 分で終わります。このリンクは 1 回だけ使えます。' },
];
const titles = { welcome: 'パスキーを登録します', check: 'このブラウザの確認', register: 'パスキーの登録', done: '登録できました' };
const title = computed(() => (state.value === 'wizard' ? titles[step.value] : '管理画面への登録'));
const heading = computed(() => (operator.value ? `ようこそ、${operator.value.name} さん` : '管理画面への登録'));

const blocked = computed(() => checks.value.some((check) => !check.ok && !check.optional));

// 登録トークンは URL の断片（#）で届く。この画面を開いたまま別のリンクを開くと、ページは読み込み直されず
// 断片だけが変わるので、断片の変化を見張る。読んだらすぐ URL と履歴から消す（消したことによる変化は無視する）
let started = false;

watch(() => route.hash, (hash) => {
    if (hash.length > 1 || !started) {
        started = true;
        begin(hash.slice(1));
    }
}, { immediate: true });

async function begin(token) {
    state.value = 'checking';
    step.value = 'welcome';
    operator.value = null;
    system.value = null;
    error.value = null;
    await router.replace({ path: '/enroll', hash: '' });

    if (session.operator) {
        state.value = 'signed-in';

        return;
    }

    if (!/^[0-9a-f]{64}$/.test(token)) {
        state.value = 'invalid';

        return;
    }

    try {
        const started = await api('POST', '/auth/enrollment', { token });
        operator.value = started.operator;
        system.value = started.system ?? null;
        state.value = 'wizard';
    } catch (e) {
        state.value = e instanceof ApiError && e.status === 409 ? 'signed-in' : 'invalid';
        error.value = e instanceof ApiError && e.status === 422 ? null : describe(e);
    }
}

async function check() {
    step.value = 'check';
    environment.value = null;
    environment.value = await passkeyEnvironment();
}

async function enroll() {
    busy.value = true;
    error.value = null;

    try {
        const { options } = await api('GET', '/auth/enrollment/options');
        const credential = await createPasskey(options);
        const result = await api('POST', '/auth/enrollment/passkey', { name: name.value.trim(), credential });
        signedIn(result.operator);
        step.value = 'done';
    } catch (e) {
        error.value = describe(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthLayout :title="title" :heading="heading" lead="管理画面に入るためのパスキーを、この端末に登録します。">
        <LoadingText v-if="state === 'checking'">登録用のリンクを確認しています…</LoadingText>
        <AppNotice v-else-if="state === 'invalid'" tone="error">
            <span data-test="enroll-invalid">登録用のリンクが無効か、期限が切れています。サーバーで <code>sudo ec-sugar operator enroll メールアドレス</code> を実行すると、新しいリンクが表示されます（あなたをメンバーに追加した人に依頼してもかまいません）。</span>
            <span v-if="error">{{ error }}</span>
        </AppNotice>
        <AppNotice v-else-if="state === 'signed-in'" tone="info">ログイン中です。この登録用のリンクを使うには、ログアウトしてからもう一度開いてください。</AppNotice>
        <template v-else>
            <StepPath :steps="steps" :current="step" label="登録の手順" />

            <div v-if="step === 'welcome'" class="flex flex-col gap-6" data-test="wizard-welcome">
                <AppText>
                    <strong data-test="enroll-operator">{{ operator.name }}</strong>（{{ operator.email }}）のパスキーを、この端末に登録します。
                </AppText>
                <FeatureList :items="welcome" />
                <AppButton variant="primary" size="lg" data-test="wizard-start" @click="check">はじめる</AppButton>
            </div>

            <div v-else-if="step === 'check'" class="flex flex-col gap-5" data-test="wizard-check">
                <AppText tone="muted">システムのバージョンと、このブラウザで登録できるかを確認します。</AppText>
                <LoadingText v-if="environment === null">確認しています…</LoadingText>
                <CheckItems v-else :items="checks" />
                <AppNotice v-if="environment !== null && blocked" tone="error" data-test="check-blocked">このままでは登録できません。注意のマークが付いた項目を解決してから、このページを開き直してください。</AppNotice>
                <AppButton v-if="environment !== null && !blocked" variant="primary" size="lg" data-test="wizard-next" @click="step = 'register'">次へ</AppButton>
            </div>

            <form v-else-if="step === 'register'" class="flex flex-col gap-5" data-test="wizard-register" @submit.prevent="enroll">
                <AppText tone="muted">ボタンを押すと、ブラウザがパスキーの保存先をたずねます。指紋・顔・暗証番号で確認すると登録が完了します。</AppText>
                <AppField label="パスキーの名前" hint="どの端末のものか分かる名前にします（例: 仕事用の MacBook）">
                    <AppInput v-model="name" required maxlength="100" autocomplete="off" data-test="passkey-name" />
                </AppField>
                <AppNotice v-if="error" tone="error" data-test="enroll-error">{{ error }}</AppNotice>
                <AppButton type="submit" variant="primary" size="lg" :disabled="busy || name.trim() === '' || !supported" data-test="enroll">パスキーを登録する</AppButton>
            </form>

            <div v-else class="flex flex-col gap-5" data-test="enroll-done">
                <AppText>これからは、ログインの画面で「パスキーでログイン」を押すだけで入れます。</AppText>
                <AppNotice tone="info">この端末を失くしても入れるように、別の端末（スマートフォンなど）のパスキーも追加しておくことをおすすめします。</AppNotice>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <AppButton variant="primary" size="lg" data-test="wizard-finish" @click="router.replace({ name: 'home' })">管理画面へ進む</AppButton>
                    <AppButton size="lg" data-test="wizard-add-passkey" @click="router.replace({ name: 'passkeys' })">別の端末のパスキーを追加</AppButton>
                </div>
            </div>
        </template>
    </AuthLayout>
</template>
