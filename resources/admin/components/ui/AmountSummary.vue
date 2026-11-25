<script setup>
// 金額の合計（小計・消費税・合計など）。右に寄せ、最後の行を強く
defineProps({
    rows: { type: Array, required: true }, // [{ label, value }]
});
</script>

<template>
    <dl class="ml-auto flex w-full max-w-xs flex-col gap-1.5 text-sm">
        <div v-for="(row, index) in rows" :key="row.label" :class="['flex justify-between gap-6', index === rows.length - 1 ? 'border-t border-line pt-2 font-semibold text-ink' : 'text-ink-muted']">
            <dt>{{ row.label }}</dt>
            <dd class="tabular">{{ row.value }}</dd>
        </div>
    </dl>
</template>
