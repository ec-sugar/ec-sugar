<script setup>
import { ref } from 'vue';
import AppButton from './AppButton.vue';

// 1 度だけ見せる秘密の並び（回復用のコード。ADR 0038）。読み違えないよう等幅で並べ、まとめて写せる
const props = defineProps({
    codes: { type: Array, required: true },
    label: { type: String, required: true },
});
const copied = ref(false);

async function copy() {
    try {
        await navigator.clipboard.writeText(props.codes.join('\n'));
        copied.value = true;
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <ol class="grid grid-cols-1 gap-x-6 gap-y-1.5 rounded-xl bg-subtle p-4 sm:grid-cols-2" :aria-label="label" data-test="secret-codes">
            <li v-for="code in codes" :key="code" class="font-mono text-[15px] tracking-wider text-ink select-all">{{ code }}</li>
        </ol>
        <div>
            <AppButton size="sm" variant="secondary" data-test="copy-codes" @click="copy">{{ copied ? 'コピーしました' : 'まとめてコピー' }}</AppButton>
        </div>
    </div>
</template>
