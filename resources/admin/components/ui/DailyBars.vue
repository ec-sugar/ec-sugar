<script setup>
import { computed } from 'vue';
import { formatAmount } from '../../format.js';

// 日ごとの売上の棒（運営全体の画面の「売上の動き」）。SVG の棒で、高さは属性で書く（style を使わない。CSP）。
// 色はトークンの fill-* だけ。0 の日は細い線だけを置く。棒ごとに <title>（日付・金額・件数）を付け、
// 読み上げには全体の要約（期間・合計・いちばん多い日）を aria-label で伝える
const props = defineProps({
    items: { type: Array, required: true }, // [{ day: 'YYYY-MM-DD', sales, orders }]
    label: { type: String, required: true },
    currency: { type: String, default: 'JPY' },
});

const WIDTH = 300;
const HEIGHT = 80;
const GAP = 2;

function short(day) {
    const [, month, date] = day.split('-');

    return `${Number(month)}/${Number(date)}`;
}

const max = computed(() => Math.max(0, ...props.items.map((item) => item.sales)));
const total = computed(() => props.items.reduce((sum, item) => sum + item.sales, 0));
const bars = computed(() => {
    const width = props.items.length > 0 ? WIDTH / props.items.length : WIDTH;

    return props.items.map((item, index) => {
        const height = max.value > 0 && item.sales > 0 ? Math.max(2, Math.round((item.sales / max.value) * HEIGHT)) : 1;

        return {
            key: item.day,
            x: index * width + GAP / 2,
            width: Math.max(1, width - GAP),
            y: HEIGHT - height,
            height,
            empty: item.sales <= 0,
            title: `${short(item.day)}　${formatAmount(item.sales, props.currency)}・${item.orders} 件`,
        };
    });
});
const summary = computed(() => {
    if (props.items.length === 0) {
        return `${props.label}。まだありません`;
    }

    const first = props.items[0].day;
    const last = props.items[props.items.length - 1].day;
    const top = props.items.reduce((best, item) => (item.sales > best.sales ? item : best), props.items[0]);

    return `${props.label}。${short(first)} から ${short(last)} までの合計 ${formatAmount(total.value, props.currency)}`
        + (top.sales > 0 ? `。いちばん多い日は ${short(top.day)} の ${formatAmount(top.sales, props.currency)}` : '。売上はまだありません');
});
</script>

<template>
    <figure class="flex flex-col gap-2">
        <svg :viewBox="`0 0 ${WIDTH} ${HEIGHT}`" preserveAspectRatio="none" role="img" :aria-label="summary" class="h-28 w-full" data-test="daily-bars">
            <rect
                v-for="bar in bars"
                :key="bar.key"
                :x="bar.x"
                :y="bar.y"
                :width="bar.width"
                :height="bar.height"
                rx="1"
                :class="bar.empty ? 'fill-line-strong' : 'fill-accent'"
                :data-day="bar.key"
            >
                <title>{{ bar.title }}</title>
            </rect>
        </svg>
        <figcaption v-if="items.length > 0" class="flex justify-between text-xs text-ink-soft tabular" aria-hidden="true">
            <span>{{ short(items[0].day) }}</span>
            <span>{{ short(items[items.length - 1].day) }}</span>
        </figcaption>
    </figure>
</template>
