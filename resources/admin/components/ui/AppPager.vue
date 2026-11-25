<script setup>
import AppButton from './AppButton.vue';

// 件数とページ送り。1 ページに収まるときは件数だけ。
// count が偽なら件数を出さない（一覧の型では件数を上の絞り込みの箱に出すので、ここはページ送りだけ）
defineProps({
    page: { type: Number, required: true },
    lastPage: { type: Number, required: true },
    total: { type: Number, required: true },
    count: { type: Boolean, default: true },
});
const emit = defineEmits(['go']);
</script>

<template>
    <nav v-if="count || lastPage > 1" :class="['flex items-center text-[13px] text-ink-soft', count ? 'justify-between' : 'justify-end']" aria-label="ページ">
        <span v-if="count">{{ total }} 件</span>
        <span v-if="lastPage > 1" class="flex items-center gap-2">
            <AppButton size="sm" :disabled="page <= 1" @click="emit('go', page - 1)">前のページ</AppButton>
            <span class="tabular">{{ page }} / {{ lastPage }}</span>
            <AppButton size="sm" :disabled="page >= lastPage" @click="emit('go', page + 1)">次のページ</AppButton>
        </span>
    </nav>
</template>
