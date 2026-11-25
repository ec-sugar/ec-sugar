<script setup>
import { ref, watch } from 'vue';
import { CONTROL } from './controlClasses.js';

// 色を 1 つ決める欄（ADR 0097 の主な色）。ブラウザの色を選ぶ部品（input type="color"）と、# の後ろに 6 桁を打つ欄を並べる。
// 値は #rrggbb（小文字）か null（決めていない）。打っている途中の形の合わない値は外へ出さない（6 桁そろったら出す）。
// 決めていない間は、色を選ぶ部品に fallback（組の色）を出す。色を style で塗らない（CSP）。確認はサーバーもする
const model = defineModel({ type: [String, null], default: null });
const props = defineProps({
    fallback: { type: String, required: true },
    label: { type: String, required: true },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    testPrefix: { type: String, default: 'color' },
});

const HEX = /^[0-9a-f]{6}$/;
const text = ref('');

watch(() => model.value ?? props.fallback, (value) => {
    const digits = (value ?? '').replace(/^#/, '').toLowerCase();
    if (digits !== text.value) text.value = digits;
}, { immediate: true });

function typed(value) {
    const digits = value.replace(/^#/, '').trim().toLowerCase();
    text.value = digits;
    if (HEX.test(digits)) model.value = `#${digits}`;
}

function picked(event) {
    const value = String(event.target.value).toLowerCase();
    if (/^#[0-9a-f]{6}$/.test(value)) model.value = value;
}
</script>

<template>
    <div class="flex items-center gap-2">
        <input
            type="color"
            :value="model ?? fallback"
            :disabled="disabled"
            :aria-label="`${label}を選ぶ`"
            class="h-10 w-12 shrink-0 cursor-pointer rounded-lg bg-subtle p-1 ring-1 ring-line-strong disabled:cursor-not-allowed disabled:opacity-60"
            :data-test="`${testPrefix}-picker`"
            @input="picked"
        >
        <span class="flex min-w-0 flex-1 items-center gap-1">
            <span class="text-sm text-ink-soft" aria-hidden="true">#</span>
            <input
                :value="text"
                type="text"
                inputmode="text"
                maxlength="7"
                autocomplete="off"
                spellcheck="false"
                :disabled="disabled"
                :aria-label="`${label}（# の後ろの 6 桁）`"
                :aria-invalid="invalid || (text !== '' && !HEX.test(text)) ? 'true' : null"
                :class="[CONTROL, 'h-10 tabular']"
                :data-test="`${testPrefix}-hex`"
                @input="typed($event.target.value)"
            >
        </span>
    </div>
</template>
