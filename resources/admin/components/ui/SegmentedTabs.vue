<script setup>
import { PRESS } from './controlClasses.js';

// 件数つきの絞り込み（すべて 7 / 公開中 5 …）。選んでいるものは墨色の地で示す（色だけに頼らない）。押した瞬間にわずかに縮む
const model = defineModel({ type: String, default: '' });
defineProps({
    items: { type: Array, required: true }, // [{ value, label, count }]
    label: { type: String, required: true },
});
</script>

<template>
    <div role="group" :aria-label="label" class="flex flex-wrap gap-1">
        <button
            v-for="item in items"
            :key="item.value"
            type="button"
            :aria-pressed="model === item.value"
            :class="[
                'inline-flex h-8 items-center gap-1.5 rounded-lg px-3 text-[13px]',
                PRESS,
                model === item.value ? 'bg-ink text-surface' : 'text-ink-soft hover:bg-subtle hover:text-ink',
            ]"
            :data-test="`tab-${item.value || 'all'}`"
            @click="model = item.value"
        >
            {{ item.label }}<span v-if="item.count !== undefined && item.count !== null" class="tabular text-xs opacity-70">{{ item.count }}</span>
        </button>
    </div>
</template>
