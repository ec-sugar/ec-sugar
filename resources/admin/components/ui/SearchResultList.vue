<script setup>
import { RouterLink } from 'vue-router';
import AppIcon from './AppIcon.vue';
import { PRESS_WIDE } from './controlClasses.js';

// 言葉で探した結果の並び（ヘルプの中の検索）。1 行に、どこの節か（小さく）・節の名前（太字）・言葉の前後の文（当たった所に印）。行の全体が押せる。
// 前後の文は部品の並び（{ text, hit }）で受け取り、当たった所を <mark> にする（HTML は受け取らない）
defineProps({
    items: { type: Array, required: true }, // [{ key, to, place, title, snippet: [{ text, hit }], test }]
    label: { type: String, required: true },
});
</script>

<template>
    <ul :aria-label="label" class="-mx-2 flex flex-col">
        <li v-for="item in items" :key="item.key" class="border-t border-line first:border-t-0">
            <RouterLink :to="item.to" :class="['flex items-center gap-4 rounded-lg px-2 py-3.5 text-ink no-underline hover:bg-subtle', PRESS_WIDE]" :data-test="item.test">
                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="text-xs text-ink-soft">{{ item.place }}</span>
                    <span class="text-sm font-bold">{{ item.title }}</span>
                    <span v-if="item.snippet.length" class="text-[13px] text-ink-muted">
                        <template v-for="(part, index) in item.snippet" :key="index">
                            <mark v-if="part.hit" class="rounded-sm bg-accent-soft px-0.5 text-ink">{{ part.text }}</mark>
                            <template v-else>{{ part.text }}</template>
                        </template>
                    </span>
                </span>
                <AppIcon name="chevron-right" class="text-ink-soft" />
            </RouterLink>
        </li>
    </ul>
</template>
