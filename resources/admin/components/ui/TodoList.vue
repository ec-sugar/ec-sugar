<script setup>
import { RouterLink } from 'vue-router';
import AppIcon from './AppIcon.vue';
import { PRESS_WIDE } from './controlClasses.js';

// 「やること」の並び（ホーム）。1 行に状態の丸・名前・数・右に「›」で、行の全体が押せて該当の画面へ移る。
// 数が 0 の行は親が渡さない。1 行も無ければ #empty（今やることは無い、の一言）。色だけに頼らず、意味は名前で伝える
defineProps({
    items: { type: Array, required: true }, // [{ key, label, count, unit, description, tone, to, test }]
});

const DOTS = { danger: 'bg-danger', warn: 'bg-warn', accent: 'bg-accent', neutral: 'bg-idle' };
</script>

<template>
    <ul v-if="items.length > 0" class="-mx-2 flex flex-col">
        <li v-for="item in items" :key="item.key" class="border-t border-line first:border-t-0">
            <RouterLink :to="item.to" :class="['flex items-center gap-3 rounded-lg px-2 py-3.5 text-ink no-underline hover:bg-subtle', PRESS_WIDE]" :data-test="item.test">
                <span aria-hidden="true" :class="['size-2 shrink-0 rounded-full', DOTS[item.tone] ?? DOTS.neutral]" />
                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="text-sm font-bold">{{ item.label }}</span>
                    <span v-if="item.description" class="text-[13px] text-ink-soft">{{ item.description }}</span>
                </span>
                <span class="tabular shrink-0 text-sm font-bold" data-test="todo-count">{{ item.count }} {{ item.unit }}</span>
                <AppIcon name="chevron-right" class="shrink-0 text-ink-soft" />
            </RouterLink>
        </li>
    </ul>
    <p v-else class="flex items-center gap-2 text-sm text-ink-soft" data-test="todo-empty"><AppIcon name="check" class="text-ok" /><slot name="empty" /></p>
</template>
