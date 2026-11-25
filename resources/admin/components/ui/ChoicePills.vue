<script setup>
import { PRESS } from './controlClasses.js';

// 少ない選択肢から 1 つを選ぶ（ラジオボタン）。選んだものは藍の輪郭で示し、押した瞬間にわずかに縮む。testPrefix を渡すと各ボタンに data-test を付ける。値は文字か数（見出しの大きさなど）。
// disabled は段階の足りない人に見せるとき（選んでいるものは見えるが押せない）
const model = defineModel({ type: [String, Number], required: true });
defineProps({
    options: { type: Array, required: true }, // [{ value, label }]
    legend: { type: String, required: true },
    testPrefix: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});
</script>

<template>
    <fieldset :disabled="disabled" class="flex flex-wrap gap-2">
        <legend class="sr-only">{{ legend }}</legend>
        <label
            v-for="option in options"
            :key="option.value"
            :class="[
                'inline-flex h-9 items-center gap-2 rounded-lg px-3 text-[13px] ring-1',
                disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
                PRESS,
                model === option.value ? 'bg-surface font-medium text-ink ring-accent' : 'text-ink-soft ring-line-strong hover:text-ink',
            ]"
        >
            <input v-model="model" type="radio" :value="option.value" class="accent-accent" :data-test="testPrefix ? `${testPrefix}-${option.value}` : null">
            {{ option.label }}
        </label>
    </fieldset>
</template>
