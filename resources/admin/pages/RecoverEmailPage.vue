<script setup>
import { onMounted, ref } from 'vue';
import { api, ApiError, describe } from '../api.js';
import { formatDate } from '../format.js';
import AppButton from '../components/ui/AppButton.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppText from '../components/ui/AppText.vue';
import AuthLayout from '../components/ui/AuthLayout.vue';

// メールでの戻し（ADR 0038 の区切り 2）: メールのリンク（#の後ろのトークン）で 24 時間の待ちに入り、待った後に同じブラウザで登録の画面へ移る
const readyAt = ref(null);
const busy = ref(false);
const error = ref(null);

// メールの検査（リンクを開いて JS も動かすもの）が受け付けてしまわないよう、受け付けはボタンを押してから
const token = ref('');

onMounted(() => {
    token.value = window.location.hash.slice(1);

    if (token.value !== '') {
        // トークンは読んだらすぐ URL から消す（履歴・画面共有に残さない）
        window.history.replaceState(null, '', window.location.pathname);
    }
});

async function accept() {
    busy.value = true;
    error.value = null;

    try {
        readyAt.value = (await api('POST', '/auth/recovery/email/confirm', { token: token.value })).ready_at;
        token.value = '';
    } catch (e) {
        error.value = e instanceof ApiError && e.status === 422 ? (e.body?.message ?? describe(e)) : describe(e);
    } finally {
        busy.value = false;
    }
}

async function finish() {
    busy.value = true;
    error.value = null;

    try {
        const { url } = await api('POST', '/auth/recovery/email/finish');
        const target = new URL(url, window.location.origin);

        if (target.origin !== window.location.origin) {
            throw new Error('unexpected');
        }

        window.location.assign(target.href);
    } catch (e) {
        if (e instanceof ApiError && e.status === 409) {
            readyAt.value = e.body?.ready_at ?? readyAt.value;
            error.value = `まだ待機期間中です。${formatDate(readyAt.value)} から登録できます。`;
        } else {
            error.value = e instanceof ApiError && e.status === 422 ? (e.body?.message ?? describe(e)) : describe(e);
        }

        busy.value = false;
    }
}
</script>

<template>
    <AuthLayout title="メールでパスキーを登録し直す" heading="EC-SUGAR 管理画面" lead="パスキーも回復用のコードも無いときの復旧の方法です。">
        <AppNotice v-if="readyAt" tone="info" :live="false" data-test="recovery-waiting">
            申し込みを受け付けました。{{ formatDate(readyAt) }} から、このブラウザでこの画面を開いて「新しいパスキーを登録する」を押してください。待機期間中は、本人とほかのオーナーへ通知しています。
        </AppNotice>
        <AppText v-else tone="muted">メールのリンクを開いたブラウザで、24 時間後に「新しいパスキーを登録する」を押します。</AppText>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>
        <template v-if="token">
            <AppText tone="muted">このブラウザで申し込みを受け付けると、24 時間の待機期間が始まります。その間に、本人とほかのオーナーへ通知します。24 時間後から 72 時間以内に、このブラウザで新しいパスキーを登録できます。</AppText>
            <AppButton variant="primary" size="lg" :loading="busy" :disabled="busy" data-test="recovery-accept" @click="accept">このブラウザで申し込みを受け付ける</AppButton>
        </template>
        <AppButton v-else variant="primary" size="lg" :loading="busy" :disabled="busy" data-test="recovery-finish" @click="finish">新しいパスキーを登録する</AppButton>
        <AppButton variant="ghost" :to="{ name: 'login' }">ログインに戻る</AppButton>
        <template #footer>元のパスキーでログインすると、この申し込みは取り消されます。</template>
    </AuthLayout>
</template>
