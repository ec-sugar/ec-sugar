<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { api, ApiError, describe } from '../api.js';
import { passkeysSupported, usePasskey } from '../webauthn.js';
import { session, signedIn, takeReturnTo } from '../session.js';
import AppButton from '../components/ui/AppButton.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppText from '../components/ui/AppText.vue';
import AuthLayout from '../components/ui/AuthLayout.vue';

const router = useRouter();
const supported = passkeysSupported();
const busy = ref(false);
const error = ref(null);

async function login() {
    busy.value = true;
    error.value = null;

    try {
        const { options } = await api('GET', '/auth/login/options');
        const { operator } = await api('POST', '/auth/login', { credential: await usePasskey(options) });
        signedIn(operator);
        await router.replace(takeReturnTo());
    } catch (e) {
        if (e instanceof ApiError && e.status === 409) {
            await router.replace({ name: 'home' });

            return;
        }

        error.value = describe(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthLayout title="ログイン" heading="EC-SUGAR 管理画面" lead="ショップ・注文・売上の管理をここから行います。">
        <AppNotice v-if="session.notice" tone="info">{{ session.notice }}</AppNotice>
        <template v-if="supported">
            <AppText tone="muted">登録済みのパスキー（Touch ID・Face ID・セキュリティキーなど）でログインします。</AppText>
            <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>
            <AppButton variant="primary" size="lg" :disabled="busy" data-test="login" @click="login">パスキーでログイン</AppButton>
        </template>
        <AppNotice v-else tone="error">このブラウザはパスキーに対応していません。最新の Chrome・Safari・Firefox・Edge をお使いください。</AppNotice>
        <AppButton variant="ghost" :to="{ name: 'recover' }" data-test="to-recover">パスキーを使えないとき（回復用のコード）</AppButton>
        <template #footer>パスキーを登録していない、または回復用のコードも無い場合は、システムの管理者に登録用のリンクの発行を依頼してください。</template>
    </AuthLayout>
</template>
