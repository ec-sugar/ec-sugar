<script setup>
// 番号つきの手順の並び（説明や設定の手順）。中身は <li> で並べる
defineProps({ size: { type: String, default: 'md', validator: (v) => ['md', 'sm'].includes(v) } });
</script>

<template>
    <ol :class="['flex list-decimal flex-col pl-5 text-ink', size === 'sm' ? 'gap-1 text-sm' : 'gap-6']">
        <slot />
    </ol>
</template>
