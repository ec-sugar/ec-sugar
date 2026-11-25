<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../../api.js';
import { canScanQr } from '../../barcode.js';
import { useOperation } from '../../operation.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppField from '../../components/ui/AppField.vue';
import AppInput from '../../components/ui/AppInput.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import BarcodeCamera from '../../components/ui/BarcodeCamera.vue';
import FormActions from '../../components/ui/FormActions.vue';

// App の画面の「読む・探す」の欄（チケットの受付で QR を読む・券面の番号やお名前を入れる）。欄の名前と案内は App が渡し、描くのは核の部品。
// 送ると、サーバー（App）が照らした 1 件の ID が返り、その 1 件の画面へ移る（名前を見比べてから受け付ける）。断り（409）はそのまま出す。
// カメラのボタンは、App が許し（scan）、ブラウザが QR を読める（BarcodeDetector）時だけ。読み取り機（キーボードとして打つ）なら、欄に打たれて Enter で送られる
const props = defineProps({
    tenant: { type: String, required: true },
    plugin: { type: String, required: true },
    // { label, hint, scan }（一覧の答えの lookup）
    lookup: { type: Object, required: true },
});
const router = useRouter();
const input = ref('');
const camera = ref(false);
const scannable = ref(false);
const looking = useOperation();

onMounted(() => {
    scannable.value = props.lookup.scan === true && canScanQr();
});

async function submit() {
    const text = input.value.trim();

    if (text === '') {
        return;
    }

    await looking.run('lookup', () => api('POST', `/api/tenants/${encodeURIComponent(props.tenant)}/apps/${encodeURIComponent(props.plugin)}/lookup`, { input: text }), {
        then: async (result) => {
            const record = typeof result?.id === 'string' ? result.id.toLowerCase() : '';
            input.value = '';

            if (/^[0-9a-z]{26}$/.test(record)) {
                await router.push({ name: 'app-record', params: { tenant: props.tenant, plugin: props.plugin, record } });
            }
        },
    });
}

function detected(value) {
    camera.value = false;
    input.value = value;
    submit();
}
</script>

<template>
    <form novalidate data-test="app-lookup" @submit.prevent="submit">
        <AppNotice v-if="looking.error.value" tone="error" data-test="app-lookup-error">{{ looking.error.value }}</AppNotice>
        <AppField :label="lookup.label" :hint="lookup.hint">
            <AppInput v-model="input" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="200" data-test="app-lookup-input" />
        </AppField>
        <BarcodeCamera v-if="camera" @detected="detected" @close="camera = false" />
        <FormActions>
            <AppButton type="submit" variant="primary" :loading="looking.pending.value === 'lookup'" data-test="app-lookup-submit">開く</AppButton>
            <AppButton v-if="scannable && !camera" data-test="app-lookup-camera" @click="camera = true">カメラで読み取る</AppButton>
        </FormActions>
    </form>
</template>
