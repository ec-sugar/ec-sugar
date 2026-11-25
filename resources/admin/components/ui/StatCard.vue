<script setup>
// 数字のカード。size="lg" は概要の主な数（白い大きなカード）、"sm" はカードの中の小さな数（薄い灰の面）。
// note は数の下の小さな補足（ホームの「¥3,000・2 件」など）
defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String], required: true },
    size: { type: String, default: 'lg', validator: (v) => ['lg', 'sm'].includes(v) },
    note: { type: String, default: null },
    valueTest: { type: String, default: null },
});
</script>

<template>
    <div
        :class="[
            'flex flex-col gap-1',
            size === 'lg' ? 'rounded-card bg-surface px-6 py-5 sm:px-7 sm:py-6' : 'rounded-xl bg-subtle px-4 py-3',
        ]"
    >
        <span :class="size === 'lg' ? 'text-[13px] text-ink-soft' : 'text-xs text-ink-soft'">{{ label }}</span>
        <span :class="['tabular font-bold text-ink', size === 'lg' ? 'text-2xl' : 'text-lg']" :data-test="valueTest">{{ value }}</span>
        <span v-if="note" class="text-xs text-ink-soft">{{ note }}</span>
    </div>
</template>
