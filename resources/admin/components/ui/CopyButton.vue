<script setup>
import { ref } from 'vue';
import AppButton from './AppButton.vue';

// 文を写す小さなボタン（App の画面の事実の copy の印。商品フィードの URL など、ほかのサービスの画面に貼る値）。値の下の行に置く。
// 写せたら 2 秒だけ「写しました」。ブラウザが許さないときは、文字を選んで写すよう一言を出す（読み上げにも伝わるよう role="status"）
const props = defineProps({
    text: { type: String, required: true },
    label: { type: String, default: 'コピー' },
});
const state = ref(null);
let timer = null;

async function copy() {
    clearTimeout(timer);

    try {
        await navigator.clipboard.writeText(props.text);
        state.value = 'done';
    } catch {
        state.value = 'failed';
    }

    timer = setTimeout(() => { state.value = null; }, 2000);
}
</script>

<template>
    <span class="mt-1.5 flex flex-wrap items-center gap-2">
        <AppButton size="sm" variant="secondary" data-test="copy-button" @click="copy">{{ state === 'done' ? 'コピーしました' : label }}</AppButton>
        <span v-if="state === 'failed'" role="status" class="text-xs text-ink-soft">コピーできませんでした。文字を選んでコピーしてください。</span>
    </span>
</template>
