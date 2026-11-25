<script setup>
// ショップの写真の札（ADR 0028 区切り 1）。横長（16:9）の枠に写真を収め、使っている所があれば左上に札を出す。下に操作（slot）を置く
defineProps({
    src: { type: String, required: true },
    alt: { type: String, default: '' },
    // 使っている所（例: 「ヒーローに使用中」）。空なら札を出さない
    badge: { type: String, default: null },
});
</script>

<template>
    <li class="flex flex-col gap-3 rounded-xl p-3 ring-1 ring-line" data-test="media-tile">
        <div class="relative aspect-video overflow-hidden rounded-lg bg-subtle">
            <img :src="src" :alt="alt" loading="lazy" class="size-full object-cover">
            <span v-if="badge" class="absolute top-2 left-2 rounded-full bg-surface px-2 py-0.5 text-xs font-medium text-ink shadow-xs" data-test="media-badge">{{ badge }}</span>
        </div>
        <slot />
    </li>
</template>
