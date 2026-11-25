<script setup>
// フォームの最後の操作の並び。確定は右端（ベイジの 9）。左にはバージョンなどの補足を置ける。
// sticky は長いフォーム向け（灰の地に白いカードの形）: 画面の下に浮かせた白い帯に留め、どこまで読んでも保存できる
defineProps({ sticky: { type: Boolean, default: false } });
</script>

<template>
    <div
        :class="[
            'flex flex-wrap items-center justify-end gap-3',
            sticky ? 'sticky bottom-4 z-10 rounded-2xl bg-surface px-5 py-3 shadow-float' : 'border-t border-line pt-6',
        ]"
    >
        <span v-if="$slots.meta" class="mr-auto text-xs text-ink-soft"><slot name="meta" /></span>
        <slot />
    </div>
</template>
