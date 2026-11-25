<script setup>
import AppButton from './AppButton.vue';
import AppIcon from './AppIcon.vue';

// 並べ替えられる 1 つのまとまり（商品の説明のブロック・仕様の表の行など）の枠。上に種類の名前と「上へ・下へ・消す」を置く。
// キーボードでは、ボタンを Tab で選んで Enter、または枠の中で Alt + ↑ / ↓ で並べ替える（上下の入れ替えは親がする）
const props = defineProps({
    // 種類の名前（「段落」など）と、並びの何番目か（1 から）
    label: { type: String, required: true },
    position: { type: Number, required: true },
    total: { type: Number, required: true },
    error: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    test: { type: String, default: null },
});
const emit = defineEmits(['up', 'down', 'remove']);

function onKey(event) {
    if (props.disabled || !event.altKey) {
        return;
    }

    if (event.key === 'ArrowUp' && props.position > 1) {
        event.preventDefault();
        emit('up');
    } else if (event.key === 'ArrowDown' && props.position < props.total) {
        event.preventDefault();
        emit('down');
    }
}
</script>

<template>
    <li
        :class="['flex flex-col gap-3 rounded-xl p-4 ring-1 transition-colors focus-within:ring-accent', error ? 'ring-danger' : 'ring-line']"
        :aria-label="`${position} 番目: ${label}`"
        :data-test="test"
        @keydown="onKey"
    >
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-[13px] font-bold text-ink-muted">{{ label }}</span>
            <span class="text-xs text-ink-faint tabular">{{ position }} / {{ total }}</span>
            <span class="ml-auto flex items-center gap-1">
                <AppButton size="sm" variant="ghost" :disabled="disabled || position === 1" :aria-label="`${position} 番目の${label}を上へ`" title="上へ（Alt + ↑）" data-move="up" @click="emit('up')"><AppIcon name="chevron-up" /></AppButton>
                <AppButton size="sm" variant="ghost" :disabled="disabled || position === total" :aria-label="`${position} 番目の${label}を下へ`" title="下へ（Alt + ↓）" data-move="down" @click="emit('down')"><AppIcon name="chevron-down" /></AppButton>
                <AppButton size="sm" variant="ghost" :disabled="disabled" :aria-label="`${position} 番目の${label}を削除`" data-move="remove" @click="emit('remove')">削除</AppButton>
            </span>
        </div>
        <slot />
        <p v-if="error" class="text-xs text-danger" role="alert">{{ error }}</p>
    </li>
</template>
