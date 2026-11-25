<script setup>
import AppIcon from './AppIcon.vue';

// テーマの札（「デザイン」のテーマの画面）。見本の絵（テーマの theme.json の screenshot）・名前・バージョン・状態・操作。
// wide は今のテーマの大きな札（広い画面では左に絵・右に名前と操作。狭い画面では縦に積む）、tile は「ほかのテーマ」の並びの札（上に絵）。
// 絵はテーマのトップを縦長に撮ったもの（640 × 900）。今のテーマの札では全体を、並びの札では上の 4:3（ヘッダー・ヒーロー・商品の 1 段目）だけを出す。絵の無いテーマは無地の枠に線の絵（見本はワイヤーの形で）
defineProps({
    label: { type: String, required: true },
    version: { type: String, default: null },
    screenshot: { type: String, default: null },
    layout: { type: String, default: 'tile', validator: (v) => ['wide', 'tile'].includes(v) },
});
</script>

<template>
    <article :class="layout === 'wide' ? 'grid gap-6 md:grid-cols-[15rem_1fr] md:items-start' : 'flex min-w-0 flex-col gap-4 rounded-xl p-4 ring-1 ring-line'">
        <figure :class="['w-full overflow-hidden rounded-lg bg-subtle ring-1 ring-line', layout === 'wide' ? 'aspect-[32/45] max-w-60' : 'aspect-[4/3]']" data-test="theme-shot">
            <img v-if="screenshot" :src="screenshot" :alt="`「${label}」の見本`" loading="lazy" class="size-full object-cover object-top">
            <div v-else class="flex size-full flex-col items-center justify-center gap-2 p-4 text-center text-ink-faint" data-test="theme-shot-empty">
                <AppIcon name="brush" :size="28" />
                <span class="text-xs">見本の画像はありません</span>
            </div>
        </figure>
        <div class="flex min-w-0 flex-col gap-3">
            <div class="flex flex-col gap-1">
                <h3 :class="['font-bold break-words text-ink', layout === 'wide' ? 'text-lg' : 'text-sm']" data-test="theme-label">{{ label }}</h3>
                <p v-if="version" class="text-xs text-ink-soft tabular" data-test="theme-version">バージョン {{ version }}</p>
            </div>
            <div v-if="$slots.status" class="flex flex-col items-start gap-1.5"><slot name="status" /></div>
            <slot />
            <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
        </div>
    </article>
</template>
