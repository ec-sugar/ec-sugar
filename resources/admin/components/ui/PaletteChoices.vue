<script setup>
import { PRESS } from './controlClasses.js';

// 色の組を 1 つ選ぶ（ラジオボタン。ADR 0097）。札ごとに、組の主な色・地・文字・帯の色を小さな丸で並べ、下に組の名前。
// 丸の色は SVG の fill の属性で塗る（style を使わない。CSP の style-src に掛からない）。色の値はサーバーが確認した #rrggbb だけが来る。
// 選んだ札は藍の輪郭。丸は飾り（aria-hidden）で、読み上げの名前は組の名前。disabled は段階の足りない人に見せるとき
const model = defineModel({ type: String, required: true });
const props = defineProps({
    palettes: { type: Array, required: true }, // [{ name, label, colors: { canvas, ink, accent, band?, … } }]
    legend: { type: String, required: true },
    disabled: { type: Boolean, default: false },
});

const COLOR = /^#[0-9a-f]{6}$/;
// 並べる色の順（無い色は飛ばす）
const ORDER = ['accent', 'ink', 'band', 'canvas'];

function swatches(colors) {
    return ORDER.map((key) => colors?.[key]).filter((value) => typeof value === 'string' && COLOR.test(value));
}
</script>

<template>
    <fieldset :disabled="props.disabled" class="grid grid-cols-2 gap-2">
        <legend class="sr-only">{{ legend }}</legend>
        <label
            v-for="palette in palettes"
            :key="palette.name"
            :class="[
                'flex flex-col gap-2 rounded-lg p-2.5 text-[13px] ring-1 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-accent',
                props.disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
                PRESS,
                model === palette.name ? 'bg-surface font-medium text-ink ring-2 ring-accent' : 'text-ink-soft ring-line-strong hover:text-ink',
            ]"
            :data-test="`palette-${palette.name}`"
        >
            <input v-model="model" type="radio" :value="palette.name" class="sr-only">
            <svg :viewBox="`0 0 ${swatches(palette.colors).length * 20} 20`" class="h-5 w-auto self-start" aria-hidden="true">
                <circle v-for="(color, i) in swatches(palette.colors)" :key="i" :cx="10 + i * 20" cy="10" r="8.5" :fill="color" class="stroke-line-strong" stroke-width="1" />
            </svg>
            <span>{{ palette.label }}</span>
        </label>
    </fieldset>
</template>
