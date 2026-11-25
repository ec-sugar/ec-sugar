<script setup>
import { computed } from 'vue';
import { WIREFRAMES, wireframeShapes } from './wireframes.js';

// セクションのパターンのワイヤーの絵。名前（theme.json の wireframe。核の語彙）から図形の並びを引いて SVG で描く。名前が無い・知らないときは汎用の絵。
// 色はトークンだけ（写真は無地の枠＝線の濃い色、文は薄い文字の色、濃い地は文字の色の面）。ダークはトークンが入れ替わるだけ（濃い地は明るい面になり、地との差で分かる）。
// 飾りの絵なので読み上げない（名前は絵の下の文字で出す）
const props = defineProps({
    name: { type: String, default: null },
});

const RECT = {
    p: 'fill-line-strong',
    a: 'fill-line',
    k: 'fill-surface stroke-line-strong',
    d: 'fill-ink-muted',
};
const LINE = {
    h: { height: 4, class: 'fill-ink-soft' },
    t: { height: 2.5, class: 'fill-ink-faint' },
    b: { height: 6, class: 'fill-ink-muted' },
    l: { height: 2.5, class: 'fill-surface' },
    lb: { height: 6, class: 'fill-surface' },
};
const CIRCLE = { c: 'fill-line-strong', lc: 'fill-surface' };

const known = computed(() => WIREFRAMES.includes(props.name));
const items = computed(() => wireframeShapes(props.name).map(([kind, x, y, a, b], i) => {
    if (CIRCLE[kind]) {
        return { key: i, tag: 'circle', attrs: { cx: x, cy: y, r: a }, class: CIRCLE[kind] };
    }

    if (LINE[kind]) {
        const { height, class: tone } = LINE[kind];

        return { key: i, tag: 'rect', attrs: { x, y, width: a, height, rx: height / 2 }, class: tone };
    }

    return { key: i, tag: 'rect', attrs: { x, y, width: a, height: b, rx: kind === 'k' ? 2 : 1 }, class: RECT[kind] ?? RECT.a };
}));
</script>

<template>
    <svg viewBox="0 0 120 72" aria-hidden="true" focusable="false" class="block h-auto w-full rounded-md bg-surface" :data-wireframe="known ? name : 'generic'">
        <template v-for="item in items" :key="item.key">
            <circle v-if="item.tag === 'circle'" v-bind="item.attrs" :class="item.class" />
            <rect v-else v-bind="item.attrs" :class="item.class" stroke-width="1" />
        </template>
    </svg>
</template>
