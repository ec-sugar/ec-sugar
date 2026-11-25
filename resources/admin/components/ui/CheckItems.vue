<script setup>
import AppIcon from './AppIcon.vue';

// 確認の一覧（登録のウィザードの「確認」）。できていれば印、できていなければ注意の印と直し方。
// optional の項目は、できていなくても先へ進める（灰の注意の印）。色だけに頼らず、読み上げにも状態を言う
defineProps({
    items: { type: Array, required: true }, // [{ key, ok, optional, label, fix }]
});
</script>

<template>
    <ul class="flex flex-col divide-y divide-line rounded-xl bg-subtle">
        <li v-for="item in items" :key="item.key" class="flex gap-3 px-4 py-3" :data-test="`check-${item.key}`" :data-ok="item.ok">
            <AppIcon
                :name="item.ok ? 'check' : 'alert'"
                :size="18"
                :class="['mt-0.5 shrink-0', item.ok ? 'text-ok' : (item.optional ? 'text-ink-soft' : 'text-danger')]"
            />
            <span class="flex flex-col gap-1">
                <span class="text-sm text-ink">{{ item.label }}<span class="sr-only">{{ item.ok ? '（できています）' : '（できていません）' }}</span></span>
                <span v-if="!item.ok" class="text-xs leading-relaxed text-ink-muted">{{ item.fix }}</span>
            </span>
        </li>
    </ul>
</template>
