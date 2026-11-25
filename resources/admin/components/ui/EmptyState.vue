<script setup>
import AppIcon from './AppIcon.vue';

// 何も無いときの表示。「無い」ことと、次にできることを書く。
// 見た目は灰の地に白いカードの形: 白いカードの中の点線の枠に、絵（任意）・一言・補足・次の操作（#action）を真ん中に。
// 絵は線のアイコンを灰の丸に載せたもの（飾り。意味は一言で伝える）。一覧の型（区切り 3）では、まだ 1 件も無いときは
// 次にやることのボタン（例: 「商品を登録する」）、絞り込んで 0 件のときは「条件を外す」を #action に置く
defineProps({
    title: { type: String, required: true },
    icon: { type: String, default: null },
});
</script>

<template>
    <div class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-line-strong px-6 py-12 text-center">
        <span v-if="icon" class="mb-2 flex size-16 items-center justify-center rounded-full bg-subtle text-ink-soft" aria-hidden="true" data-test="empty-figure">
            <AppIcon :name="icon" :size="28" />
        </span>
        <p class="font-bold text-ink-muted">{{ title }}</p>
        <p v-if="$slots.default" class="max-w-md text-[13px] text-ink-soft"><slot /></p>
        <div v-if="$slots.action" class="mt-2 flex flex-wrap justify-center gap-2"><slot name="action" /></div>
    </div>
</template>
