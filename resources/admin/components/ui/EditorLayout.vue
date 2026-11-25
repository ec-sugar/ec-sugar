<script setup>
// 編集の画面の骨組み（ページの編集。ADR 0039 区切り 2）。メニューを出さず画面の全体を使う（左にセクションの一覧・真ん中にプレビューの形）:
// 上に操作の帯（#back・題・#bar）、その下にお知らせ（#notices）、3 列（左 #start・真ん中 既定の slot・右 #end）。
// 広い画面では左右の列を画面の高さに留めて中だけ動かし、狭い画面では左 → 真ん中 → 右の順に縦に積む
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    startLabel: { type: String, required: true },
    endLabel: { type: String, required: true },
});
</script>

<template>
    <div class="flex min-h-screen flex-col bg-canvas">
        <header class="sticky top-0 z-30 flex flex-wrap items-center gap-x-3 gap-y-2 bg-surface px-4 py-2.5 ring-1 ring-line">
            <slot name="back" />
            <div class="flex min-w-0 flex-col">
                <h1 class="truncate text-[15px] font-bold text-ink">{{ title }}</h1>
                <p v-if="subtitle" class="truncate text-xs text-ink-soft">{{ subtitle }}</p>
            </div>
            <slot name="bar" />
        </header>
        <div v-if="$slots.notices" class="flex flex-col gap-3 px-4 pt-4">
            <slot name="notices" />
        </div>
        <div class="grid flex-1 grid-cols-1 items-start gap-4 p-4 lg:grid-cols-[18rem_minmax(0,1fr)_21rem]">
            <aside :aria-label="startLabel" class="flex flex-col gap-4 rounded-card bg-surface p-4 lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto">
                <slot name="start" />
            </aside>
            <main class="flex min-w-0 flex-col gap-3">
                <slot />
            </main>
            <aside :aria-label="endLabel" class="flex flex-col gap-4 rounded-card bg-surface p-4 lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto">
                <slot name="end" />
            </aside>
        </div>
    </div>
</template>
