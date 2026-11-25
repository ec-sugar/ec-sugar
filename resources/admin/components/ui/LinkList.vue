<script setup>
import { RouterLink } from 'vue-router';
import AppIcon from './AppIcon.vue';
import { PRESS_WIDE } from './controlClasses.js';

// 目次の行の並び（設定の目次）。1 行に名前（太字）と一言の説明、右に「›」。行の全体が押せる。
// to の無い行はまだ画面の無い項目で、「近日」の札を付けて押せなくする（リンクにしない）。押した瞬間にわずかに縮む
defineProps({
    items: { type: Array, required: true }, // [{ key, label, description, to, test }]
});
</script>

<template>
    <ul class="-mx-2 flex flex-col">
        <li v-for="item in items" :key="item.key" class="border-t border-line first:border-t-0">
            <RouterLink
                v-if="item.to"
                :to="item.to"
                :class="['flex items-center gap-4 rounded-lg px-2 py-3.5 text-ink no-underline hover:bg-subtle', PRESS_WIDE]"
                :data-test="item.test"
            >
                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="text-sm font-bold">{{ item.label }}</span>
                    <span v-if="item.description" class="text-[13px] text-ink-soft">{{ item.description }}</span>
                </span>
                <AppIcon name="chevron-right" class="text-ink-soft" />
            </RouterLink>
            <div v-else class="flex items-center gap-4 px-2 py-3.5 text-ink-soft" aria-disabled="true" :data-test="item.test">
                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="text-sm font-bold">{{ item.label }}</span>
                    <span v-if="item.description" class="text-[13px]">{{ item.description }}</span>
                </span>
                <span class="rounded bg-subtle px-1.5 py-px text-[11px] font-bold">近日</span>
            </div>
        </li>
    </ul>
</template>
