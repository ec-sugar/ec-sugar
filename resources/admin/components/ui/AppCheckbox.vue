<script setup>
// 1 つのチェックボックスと名前（保存のボタンで反映する ON/OFF。押した瞬間に切り替わる操作ではトグルスイッチを使う。ベイジの 24）
const model = defineModel({ type: Boolean, default: false });
defineProps({
    label: { type: String, required: true },
    disabled: { type: Boolean, default: false },
    test: { type: String, default: null },
});
</script>

<template>
    <label class="inline-flex min-h-9 cursor-pointer items-center gap-2.5 text-[13px] text-ink-muted hover:text-ink">
        <input v-model="model" type="checkbox" class="size-4 accent-accent" :disabled="disabled" :data-test="test">
        {{ label }}
    </label>
</template>
