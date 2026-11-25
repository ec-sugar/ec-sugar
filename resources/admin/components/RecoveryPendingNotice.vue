<script setup>
import { ref } from 'vue';
import { api, describe } from '../api.js';
import { formatDate } from '../format.js';
import { session } from '../session.js';
import AppButton from './ui/AppButton.vue';
import AppNotice from './ui/AppNotice.vue';

// メールでの戻しの待ちがある運営への通知（ADR 0038）。本人の申し込みでなければ、ここから取り消す
const busy = ref(false);
const error = ref(null);

async function cancel() {
    busy.value = true;
    error.value = null;

    try {
        await api('POST', '/auth/recovery/email/cancel');
        session.recoveryPending = null;
    } catch (e) {
        error.value = describe(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppNotice v-if="session.recoveryPending" tone="warning" :live="false" data-test="recovery-pending">
        あなたのアカウントに、メールでパスキーを登録し直す申し込みがあります（{{ formatDate(session.recoveryPending) }} から有効）。心当たりが無ければ取り消してください。
        <div>
            <AppButton size="sm" :loading="busy" :disabled="busy" data-test="recovery-cancel" @click="cancel">申し込みを取り消す</AppButton>
        </div>
    </AppNotice>
    <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>
</template>
