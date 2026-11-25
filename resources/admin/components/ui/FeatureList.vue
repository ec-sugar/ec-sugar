<script setup>
import AppIcon from './AppIcon.vue';

// 印つきの短い説明の並び（登録のウィザードの「ようこそ」など）
defineProps({
    items: { type: Array, required: true }, // [{ icon, text }]
});
</script>

<template>
    <ul class="flex flex-col gap-3">
        <li v-for="item in items" :key="item.text" class="flex gap-3 text-sm leading-relaxed text-ink-muted">
            <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-accent-soft text-accent">
                <AppIcon :name="item.icon" :size="16" />
            </span>
            <span class="pt-1">{{ item.text }}</span>
        </li>
    </ul>
</template>
