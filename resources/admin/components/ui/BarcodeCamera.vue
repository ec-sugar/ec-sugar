<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AppButton from './AppButton.vue';
import AppText from './AppText.vue';

// カメラで QR コードを読む枠（App「デジタルチケット」の受付）。開いた時だけカメラを使い（ブラウザが許すかを聞く）、閉じたら止める。
// 読むのはブラウザの標準の BarcodeDetector（使えるかは親が barcode.js の canScanQr で確認してから出す）。映像はどこにも送らず、読んだ文字だけを親に渡す。
// 映像は MediaStream を video の srcObject に渡すだけ（URL を作らないので、管理画面の CSP のまま動く）
defineProps({
    label: { type: String, default: 'QR コードを読むカメラ' },
});
const emit = defineEmits(['detected', 'close']);
const video = ref(null);
const message = ref(null);
let stream = null;
let timer = null;
let busy = false;

function stop() {
    window.clearInterval(timer);
    timer = null;

    for (const track of stream?.getTracks() ?? []) {
        track.stop();
    }

    stream = null;
}

async function scan(detector) {
    if (busy || video.value === null) {
        return;
    }

    busy = true;

    try {
        const codes = await detector.detect(video.value);
        const value = codes.find((code) => typeof code.rawValue === 'string' && code.rawValue !== '')?.rawValue;

        if (value) {
            stop();
            emit('detected', value);
        }
    } catch {
        // 読めない場面（ピントが合わない など）は次の回に
    } finally {
        busy = false;
    }
}

onMounted(async () => {
    try {
        const detector = new window.BarcodeDetector({ formats: ['qr_code'] });
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
        video.value.srcObject = stream;
        await video.value.play();
        timer = window.setInterval(() => scan(detector), 300);
    } catch {
        stop();
        message.value = 'カメラを使えませんでした。ブラウザの設定でカメラを許可するか、チケットに書かれた番号を入力してください。';
    }
});

onBeforeUnmount(stop);
</script>

<template>
    <div class="flex flex-col items-start gap-2">
        <video ref="video" class="aspect-square w-full max-w-xs rounded-lg bg-ink object-cover" muted playsinline :aria-label="label" data-test="barcode-camera" />
        <AppText v-if="message" tone="danger" size="sm" role="alert" data-test="barcode-camera-error">{{ message }}</AppText>
        <AppButton data-test="barcode-camera-close" @click="stop(); emit('close')">カメラを閉じる</AppButton>
    </div>
</template>
