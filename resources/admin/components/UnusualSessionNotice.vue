<script setup>
import { ref } from 'vue';
import { api, describe } from '../api.js';
import { formatDate } from '../format.js';
import { session } from '../session.js';
import AppButton from './ui/AppButton.vue';
import AppNotice from './ui/AppNotice.vue';
import TextLink from './ui/TextLink.vue';

// いつもと違う接続元から入った、今の画面でない端末がログイン中。本人へはメールでも通知している。
// 心当たりが無ければ、ここからその端末をログアウトさせる
const busy = ref(false);
const error = ref(null);

async function end() {
    busy.value = true;
    error.value = null;

    try {
        await api('DELETE', `/auth/sessions/${encodeURIComponent(session.unusualSession.id)}`);
        session.unusualSession = null;
    } catch (e) {
        error.value = describe(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppNotice v-if="session.unusualSession" tone="warning" :live="false" data-test="unusual-session">
        いつもと違う接続元から、{{ formatDate(session.unusualSession.created_at) }} に {{ session.unusualSession.device }} でログインした端末があります。心当たりが無ければログアウトさせ、<TextLink :to="{ name: 'passkeys' }">パスキー</TextLink>を見直してください。
        <div>
            <AppButton size="sm" :loading="busy" :disabled="busy" data-test="unusual-session-end" @click="end">この端末をログアウトさせる</AppButton>
        </div>
    </AppNotice>
    <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>
</template>
