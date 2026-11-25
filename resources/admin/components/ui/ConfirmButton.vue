<script setup>
import { ref, watch } from 'vue';
import AppButton from './AppButton.vue';
import AppText from './AppText.vue';

// 押す前に確認するボタン（元に戻せない操作）。1 回目で問い（slot）と「やめる」を出し、2 回目で confirm を出す。
// 操作が終わった（loading / disabled が外れた）ら閉じる。並べ方は親に任せる（この部品は箱を作らない）。
// 問いを開いた時に ask、「やめる」で cancel を出す（問いの横に、開いてから読む通知を親が出すとき。テーマの選び替え）。
// 問いと 2 回目のボタンは、横から数 px でふわっと出す
const props = defineProps({
    label: { type: String, required: true },
    // 2 回目のボタンの文字（省くと label と同じ）
    confirmLabel: { type: String, default: null },
    variant: { type: String, default: 'danger' },
    size: { type: String, default: 'sm' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    ariaLabel: { type: String, default: null },
    // ブラウザのテストの印（2 回目のボタンは省くと confirm-<test>）
    test: { type: String, default: null },
    confirmTest: { type: String, default: null },
});
const emit = defineEmits(['confirm', 'ask', 'cancel']);

const asking = ref(false);
const sent = ref(false);

function confirm() {
    sent.value = true;
    emit('confirm');
}

function ask() {
    asking.value = true;
    emit('ask');
}

function cancel() {
    asking.value = false;
    emit('cancel');
}

watch(() => props.loading || props.disabled, (busy) => {
    if (!busy && sent.value) {
        asking.value = false;
        sent.value = false;
    }
});
</script>

<template>
    <div class="contents">
        <template v-if="asking">
            <AppText v-if="$slots.default" tone="muted" class="animate-reveal-x"><slot /></AppText>
            <AppButton size="sm" variant="ghost" class="animate-reveal-x" @click="cancel">やめる</AppButton>
            <AppButton class="animate-reveal-x" :size="size" :variant="variant" :disabled="disabled" :loading="loading" :data-test="confirmTest ?? (test ? `confirm-${test}` : null)" @click="confirm">{{ confirmLabel ?? label }}</AppButton>
        </template>
        <AppButton v-else :size="size" :variant="variant" :disabled="disabled" :aria-label="ariaLabel" :data-test="test" @click="ask">{{ label }}</AppButton>
    </div>
</template>
