<script setup>
// パネルの中の小さなセクション（細い輪郭で囲む）。as="form" でフォームにもなる
defineProps({
    as: { type: String, default: 'div' },
    title: { type: String, required: true },
    description: { type: String, default: null },
});
</script>

<template>
    <component :is="as" class="flex flex-col gap-5 rounded-xl p-5 ring-1 ring-line">
        <div class="flex flex-col gap-1">
            <h3 class="text-sm font-bold text-ink">{{ title }}</h3>
            <p v-if="description" class="text-xs text-ink-soft">{{ description }}</p>
        </div>
        <slot />
    </component>
</template>
