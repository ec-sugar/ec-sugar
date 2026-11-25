<script setup>
// 白い大きなカード（灰の地に浮かせる形）。線も影も付けず、灰の地との差だけで分ける。
// title を渡すと欄の見出し（と説明・右上の操作）を付ける。中身は縦に並べる
defineProps({
    as: { type: String, default: 'section' },
    title: { type: String, default: null },
    description: { type: String, default: null },
    padded: { type: Boolean, default: true },
});
</script>

<template>
    <component :is="as" :class="['flex flex-col gap-6 rounded-card bg-surface', padded ? 'p-5 sm:p-card' : '']">
        <div v-if="title" class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <h2 class="text-base font-bold text-ink">{{ title }}</h2>
                <p v-if="description" class="text-[13px] text-ink-soft">{{ description }}</p>
            </div>
            <div v-if="$slots.actions" class="flex items-center gap-2"><slot name="actions" /></div>
        </div>
        <slot />
    </component>
</template>
