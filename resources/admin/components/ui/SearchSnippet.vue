<script setup>
// 検索の結果の見え方の試し見（ADR 0061）。題・URL・説明を、検索の結果に近い形で並べる。長い題と説明は検索の結果と同じく途中で切れて見える
defineProps({
    title: { type: String, required: true },
    url: { type: String, default: null },
    description: { type: String, default: null },
});
</script>

<template>
    <figure class="flex flex-col gap-1 rounded-xl bg-surface p-4 ring-1 ring-line" data-test="search-snippet">
        <figcaption class="text-xs text-ink-soft">検索結果での表示（目安）</figcaption>
        <p v-if="url" class="truncate text-xs text-ink-muted" data-test="search-snippet-url">{{ url }}</p>
        <p class="line-clamp-1 text-base text-accent" data-test="search-snippet-title">{{ title }}</p>
        <p v-if="description" class="line-clamp-2 text-[13px] text-ink-muted" data-test="search-snippet-description">{{ description }}</p>
    </figure>
</template>
