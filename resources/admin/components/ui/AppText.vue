<script setup>
import { computed } from 'vue';

// 本文・補足の文字。色は tone、大きさは size で選ぶ（画面に文字の色や大きさを直接書かない）
const props = defineProps({
    as: { type: String, default: 'p' },
    tone: { type: String, default: 'default', validator: (v) => ['default', 'muted', 'soft', 'danger'].includes(v) },
    size: { type: String, default: 'md', validator: (v) => ['md', 'sm', 'xs'].includes(v) },
    mono: { type: Boolean, default: false },
    // nowrap: 折り返さない（日時など）/ breakAll: 空白の無い長い文字（JSON・ID）をどこでも折り返す
    nowrap: { type: Boolean, default: false },
    breakAll: { type: Boolean, default: false },
});

// danger: 欄の誤り（AppField の誤りと同じ色）
const TONES = { default: 'text-ink', muted: 'text-ink-muted', soft: 'text-ink-soft', danger: 'text-danger' };
const SIZES = { md: 'text-sm', sm: 'text-[13px]', xs: 'text-xs' };
const classes = computed(() => [
    TONES[props.tone],
    SIZES[props.size],
    props.mono ? 'font-mono' : '',
    props.nowrap ? 'whitespace-nowrap' : '',
    props.breakAll ? 'break-all' : '',
]);
</script>

<template>
    <component :is="as" :class="classes"><slot /></component>
</template>
