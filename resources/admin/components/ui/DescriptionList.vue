<script setup>
// 項目と値の一覧（概要など）。値が空なら「—」。
// 値を文字でなく部品で描くときは #value の slot（{ item } を受け取る。App の画面の事実の並び。ADR 0051）
defineProps({
    items: { type: Array, required: true }, // [{ label, value, mono? }]
});
</script>

<template>
    <dl class="grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2">
        <div v-for="item in items" :key="item.label" class="flex min-w-0 flex-col gap-0.5">
            <dt class="text-xs text-ink-soft">{{ item.label }}</dt>
            <dd :class="['text-sm break-words text-ink', item.mono ? 'font-mono text-xs' : '']">
                <slot name="value" :item="item">{{ item.value === null || item.value === undefined || item.value === '' ? '—' : item.value }}</slot>
            </dd>
        </div>
    </dl>
</template>
