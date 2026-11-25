<script setup>
import { RouterLink } from 'vue-router';
import AppIcon from './AppIcon.vue';
import { PRESS } from './controlClasses.js';

// 画面の見出し: 現在地（パンくず。ベイジの 19）・題・主な操作（右上。ベイジの 4・9）。題は 24px の太字（灰の地に白いカードの形）。
// tabs を渡すと、題の下にその項目の中の画面をタブで並べる（商品の一覧・カテゴリ など。並びはメニューの定義の tabsFor から）。
// help を渡すと、題の横に「説明を見る」（その画面のヘルプの節へのリンク。行き先は help/index.js の helpLink から）
defineProps({
    title: { type: String, required: true },
    crumbs: { type: Array, default: () => [] }, // [{ label, to }]。最後は今いる画面なので to を付けない
    tabs: { type: Array, default: () => [] }, // [{ key, label, to, current }]
    help: { type: Object, default: null }, // 説明のページの節への経路
});
</script>

<template>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex min-w-0 flex-col gap-1">
            <nav v-if="crumbs.length" aria-label="現在地">
                <ol class="flex flex-wrap items-center gap-1.5 text-[13px] text-ink-faint">
                    <template v-for="(crumb, index) in crumbs" :key="index">
                        <li v-if="index > 0" aria-hidden="true">/</li>
                        <li>
                            <RouterLink v-if="crumb.to" :to="crumb.to" class="text-ink-soft no-underline hover:text-accent hover:underline">{{ crumb.label }}</RouterLink>
                            <span v-else aria-current="page" class="text-ink-muted">{{ crumb.label }}</span>
                        </li>
                    </template>
                </ol>
            </nav>
            <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                <h1 class="min-w-0 truncate text-2xl font-bold tracking-tight text-ink"><slot name="title">{{ title }}</slot></h1>
                <RouterLink
                    v-if="help"
                    :to="help"
                    :class="['inline-flex h-7 shrink-0 items-center gap-1 rounded-lg px-2 text-[13px] text-ink-soft no-underline hover:bg-subtle hover:text-accent', PRESS]"
                    data-test="help-link"
                >
                    <AppIcon name="help" :size="15" />説明を見る
                </RouterLink>
            </div>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
        <nav v-if="tabs.length" aria-label="この項目の中の画面" class="-mb-2 w-full">
            <ul class="flex flex-wrap gap-1 border-b border-line-strong">
                <li v-for="tab in tabs" :key="tab.key">
                    <RouterLink
                        :to="tab.to"
                        :aria-current="tab.current ? 'page' : null"
                        :class="[
                            '-mb-px flex h-10 items-center border-b-2 px-3 text-sm no-underline',
                            PRESS,
                            tab.current ? 'border-accent font-bold text-ink' : 'border-transparent text-ink-soft hover:text-ink',
                        ]"
                        :data-test="`tab-page-${tab.key}`"
                    >
                        {{ tab.label }}
                    </RouterLink>
                </li>
            </ul>
        </nav>
    </div>
</template>
