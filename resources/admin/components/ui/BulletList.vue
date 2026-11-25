<script setup>
// 印の付いた箇条書き（説明のページの「確認すること」など）。中身は <li> で並べる。順番に意味があるときは StepList
defineProps({ size: { type: String, default: 'md', validator: (v) => ['md', 'sm'].includes(v) } });
</script>

<template>
    <ul :class="['flex list-disc flex-col gap-1.5 pl-5 text-ink marker:text-ink-faint', size === 'sm' ? 'text-[13px]' : 'text-sm']">
        <slot />
    </ul>
</template>
