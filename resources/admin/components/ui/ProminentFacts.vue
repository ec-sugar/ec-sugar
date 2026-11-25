<script setup>
// 1 件の画面のいちばん上に大きく出す事実（App の画面の prominent の印。チケットの受付で、券面の名前を身分証と見比べる）。
// 値は文字として差し込むだけ（HTML を差し込まない）。2 つまで（サーバーが決まりを守る）
defineProps({
    // [{ label, value }]
    items: { type: Array, required: true },
});
</script>

<template>
    <dl class="flex flex-col gap-3" data-test="app-prominent">
        <div v-for="(item, index) in items" :key="index" class="flex flex-col gap-0.5">
            <dt class="text-[13px] font-bold text-ink-muted">{{ item.label }}</dt>
            <dd class="break-all text-2xl font-bold text-ink" data-test="app-prominent-value">{{ item.value ?? '—' }}</dd>
        </div>
    </dl>
</template>
