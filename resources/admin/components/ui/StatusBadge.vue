<script setup>
import { computed } from 'vue';

// 状態のバッジ。形は 1 種類、色は小さな丸だけ。意味は必ず文字で出す
const props = defineProps({
    tone: { type: String, default: 'neutral', validator: (v) => ['ok', 'warn', 'danger', 'neutral', 'accent'].includes(v) },
});

const DOTS = { ok: 'bg-ok', warn: 'bg-warn', danger: 'bg-danger', neutral: 'bg-idle', accent: 'bg-accent' };
const dot = computed(() => DOTS[props.tone]);
</script>

<template>
    <span class="inline-flex h-6 items-center gap-1.5 rounded-full bg-subtle px-2.5 text-xs font-medium whitespace-nowrap text-ink-muted">
        <span aria-hidden="true" :class="['size-1.5 rounded-full', dot]" />
        <slot />
    </span>
</template>
