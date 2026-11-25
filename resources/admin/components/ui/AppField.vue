<script setup>
// 項目の枠: 名前・入力欄・補足・誤り。誤りは何が悪いかと直し方を書く（ベイジの 17）。入力欄は中に置く。
// layout="row" は長いフォーム向け（灰の地に白いカードの形）: 広い画面で左に項目名（太字）・右に入力、項目の間に区切り線。
// 狭い画面では上に項目名・下に入力に戻る。区切り線は、すぐ前も row の項目のときだけ上に出る（見出しの直後には出ない）。
// 中が入力欄 1 つでないとき（チェックの並び・写真の一覧など）は as="div" にする（label で包むと、中を押したときに最初の入力欄へ焦点が飛ぶ）
const props = defineProps({
    as: { type: String, default: 'label', validator: (v) => ['label', 'div'].includes(v) },
    label: { type: String, required: true },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    optional: { type: Boolean, default: false },
    layout: { type: String, default: 'stack', validator: (v) => ['stack', 'row'].includes(v) },
});

const row = props.layout === 'row';
</script>

<template>
    <component
        :is="as"
        :class="row
            ? 'field-row flex flex-col gap-1.5 [.field-row+&]:border-t [.field-row+&]:border-line [.field-row+&]:pt-6 lg:grid lg:grid-cols-[13rem_minmax(0,1fr)] lg:items-start lg:gap-8'
            : 'flex flex-col gap-1.5'"
    >
        <span :class="['flex flex-wrap items-center gap-2 font-bold', row ? 'text-sm text-ink lg:min-h-10' : 'text-[13px] text-ink-muted']">
            {{ label }}<span v-if="optional" class="rounded bg-subtle px-1.5 py-px text-[11px] font-bold text-ink-soft">任意</span>
        </span>
        <span class="flex min-w-0 flex-col gap-1.5">
            <slot />
            <span v-if="error" class="text-xs text-danger" role="alert">{{ error }}</span>
            <span v-else-if="hint" class="text-xs text-ink-soft">{{ hint }}</span>
        </span>
    </component>
</template>
