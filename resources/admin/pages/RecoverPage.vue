<script setup>
import { ref } from 'vue';
import { api, ApiError, describe } from '../api.js';
import AppButton from '../components/ui/AppButton.vue';
import AppField from '../components/ui/AppField.vue';
import AppInput from '../components/ui/AppInput.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppText from '../components/ui/AppText.vue';
import AuthLayout from '../components/ui/AuthLayout.vue';

// パスキーをなくした時（ADR 0038）: メールと回復用のコードが合えば、新しいパスキーを登録する画面へ移る
const email = ref('');
const code = ref('');
const busy = ref(false);
const error = ref(null);
// コードも無いとき: メールで申し込む（ADR 0038 の区切り 2）
const mailEmail = ref('');
const mailBusy = ref(false);
const mailMessage = ref(null);

async function requestMail() {
    mailBusy.value = true;
    mailMessage.value = null;

    try {
        mailMessage.value = (await api('POST', '/auth/recovery/email', { email: mailEmail.value.trim() })).message;
    } catch (e) {
        mailMessage.value = describe(e);
    } finally {
        mailBusy.value = false;
    }
}

async function recover() {
    busy.value = true;
    error.value = null;

    try {
        const { url } = await api('POST', '/auth/recovery', { email: email.value.trim(), code: code.value });
        const target = new URL(url, window.location.origin);

        // 登録の画面は同じ管理画面の中だけ（ほかのアドレスへは移らない）
        if (target.origin !== window.location.origin) {
            throw new Error('unexpected');
        }

        window.location.assign(target.href);
    } catch (e) {
        error.value = e instanceof ApiError && (e.status === 422 || e.status === 429) ? (e.body?.message ?? describe(e)) : describe(e);
        busy.value = false;
    }
}
</script>

<template>
    <AuthLayout title="パスキーを使えないとき" heading="EC-SUGAR 管理画面" lead="回復用のコードで、新しいパスキーを登録し直します。">
        <AppText tone="muted">パスキーを登録した時に控えた回復用のコード（4 文字ずつ区切った 16 文字）を 1 つ入力します。使ったコードは二度と使えません。</AppText>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>
        <form class="flex flex-col gap-4" @submit.prevent="recover">
            <AppField label="メール">
                <AppInput v-model="email" type="email" required maxlength="254" autocomplete="username" data-test="recover-email" />
            </AppField>
            <AppField label="回復用のコード" hint="大文字・小文字と区切りの「-」は気にせず入力できます">
                <AppInput v-model="code" required maxlength="64" autocomplete="one-time-code" spellcheck="false" data-test="recover-code" />
            </AppField>
            <AppButton type="submit" variant="primary" size="lg" :loading="busy" :disabled="busy" data-test="recover">新しいパスキーを登録する</AppButton>
        </form>
        <AppText tone="muted">回復用のコードも無いときは、メールで申し込めます。届いたリンクを開いてから 24 時間待つと、そのブラウザで新しいパスキーを登録できます（待機期間中は、本人とほかのオーナーへ通知します）。</AppText>
        <AppNotice v-if="mailMessage" tone="info" data-test="recover-mail-sent">{{ mailMessage }}</AppNotice>
        <form class="flex flex-col gap-4" @submit.prevent="requestMail">
            <AppField label="メール（メールで申し込む）">
                <AppInput v-model="mailEmail" type="email" required maxlength="254" autocomplete="username" data-test="recover-mail-email" />
            </AppField>
            <AppButton type="submit" :loading="mailBusy" :disabled="mailBusy" data-test="recover-mail">メールで申し込む</AppButton>
        </form>
        <AppButton variant="ghost" :to="{ name: 'login' }">ログインに戻る</AppButton>
        <template #footer>サーバーを管理している人に、登録用のリンクの発行を依頼することもできます（sudo ec-sugar operator enroll）。</template>
    </AuthLayout>
</template>
