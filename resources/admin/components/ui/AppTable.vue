<script setup>
import { computed } from 'vue';

// 表。線は行の間の薄い区切りだけ。文字は左揃え、数は見出しごと右揃え（.num を th / td に付ける。ベイジの 22）。
// 幅が足りないときは表の中で横に動かす（画面全体は横に動かさない）。
// stack を付けると、狭い画面（md より狭い）では見出しを隠し、1 行を 1 枚の札にして縦に並べる（一覧の型）。
// 札の中では、欄（td）の data-label を左に小さく、値を右に置く。data-label の無い欄（名前の欄）は札の頭に幅いっぱいで出す。
// 並べ替えは見出しが隠れるので、一覧の絞り込みの箱（ListFilters）の並べ替えの選択で行う
const props = defineProps({
    label: { type: String, required: true },
    stack: { type: Boolean, default: false },
});

const STACK = [
    'max-md:block max-md:[&_thead]:hidden max-md:[&_tbody]:block',
    'max-md:[&_tbody_tr]:flex max-md:[&_tbody_tr]:flex-col max-md:[&_tbody_tr]:gap-2 max-md:[&_tbody_tr]:px-4 max-md:[&_tbody_tr]:py-4',
    'max-md:[&_td]:flex max-md:[&_td]:min-w-0 max-md:[&_td]:items-center max-md:[&_td]:justify-between max-md:[&_td]:gap-4 max-md:[&_td]:p-0',
    'max-md:[&_td[data-label]]:before:shrink-0 max-md:[&_td[data-label]]:before:text-xs max-md:[&_td[data-label]]:before:text-ink-soft',
    'max-md:[&_td[data-label]]:before:content-[attr(data-label)]',
].join(' ');

const classes = computed(() => [
    'w-full border-collapse text-sm',
    '[&_th]:px-4 [&_th]:py-2 [&_th]:text-left [&_th]:text-xs [&_th]:font-bold [&_th]:whitespace-nowrap [&_th]:text-ink-soft',
    '[&_td]:px-4 [&_td]:py-3 [&_td]:align-middle',
    '[&_.num]:text-right [&_.num]:tabular',
    '[&_tbody_tr]:border-t [&_tbody_tr]:border-line [&_tbody_tr:hover]:bg-subtle',
    props.stack ? STACK : '',
]);
</script>

<template>
    <div class="-mx-4 overflow-x-auto">
        <table :aria-label="label" :class="classes">
            <slot />
        </table>
    </div>
</template>
