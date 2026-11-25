<script setup>
import { RouterLink } from 'vue-router';

// 表の行の名前（to があれば詳細へのリンク）と、その下の補足（品番・保存先など）
defineProps({
    label: { type: String, required: true },
    to: { type: [String, Object], default: null },
    sub: { type: String, default: null },
});
</script>

<template>
    <div class="flex min-w-0 flex-col">
        <RouterLink v-if="to" :to="to" class="font-medium text-ink no-underline hover:text-accent">{{ label }}</RouterLink>
        <span v-else class="font-medium text-ink">{{ label }}</span>
        <span v-if="sub" class="text-xs text-ink-soft">{{ sub }}</span>
    </div>
</template>
