<script setup>
import AppButton from './AppButton.vue';
import AppIcon from './AppIcon.vue';

// 一覧の絞り込みの箱（一覧の型）。細い輪郭の箱に、上の段は言葉で探す（#search）と、
// 状態で絞る・並べ替え（既定の slot に FilterSelect を並べる）。下の段に件数と、絞り込み中なら「条件を外す」。
// 狭い画面では検索欄の下に選択を 2 列で並べる。条件そのものは画面が URL に持つ（listQuery.js）
defineProps({
    label: { type: String, default: '絞り込み' },
    // 条件に合う件数（読み込む前は null で出さない）
    total: { type: Number, default: null },
    // 言葉か状態で絞っているか（「条件を外す」を出す）
    filtered: { type: Boolean, default: false },
});
const emit = defineEmits(['clear']);
</script>

<template>
    <section :aria-label="label" class="flex flex-col gap-3 rounded-xl p-4 ring-1 ring-line" data-test="list-filters">
        <div class="flex flex-col gap-3 md:flex-row md:items-end">
            <div class="min-w-0 flex-1"><slot name="search" /></div>
            <div v-if="$slots.default" class="grid grid-cols-2 gap-3 md:flex md:shrink-0"><slot /></div>
        </div>
        <div class="flex min-h-8 flex-wrap items-center justify-between gap-2 text-[13px] text-ink-soft">
            <span aria-live="polite" class="tabular" data-test="list-total">
                <template v-if="total !== null">{{ total }} 件<template v-if="filtered">（絞り込み中）</template></template>
            </span>
            <AppButton v-if="filtered" size="sm" variant="ghost" data-test="clear-filters" @click="emit('clear')">
                <AppIcon name="x" />条件を解除
            </AppButton>
        </div>
    </section>
</template>
