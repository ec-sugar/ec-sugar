<script setup>
import { computed } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { visibleSettingsSections } from '../navigation.js';
import { canInTenant } from '../tenants.js';
import { PRESS_WIDE } from './ui/controlClasses.js';

// 設定の 1 項目の画面の骨組み（案 C）。広い画面（lg 以上）では左に設定の目次を開いたまま置き、右に今の項目の画面。
// 目次のまとまりと項目はメニューの定義（navigation.js の SETTINGS_SECTIONS）から。まだ画面の無い項目は「近日」の札で押せない。
// 狭い画面では目次を出さず（設定の目次の画面とパンくずの「設定」から選ぶ）、中身だけを全幅で見せる
const props = defineProps({ tenant: { type: String, required: true } });

const route = useRoute();
const sections = computed(() => visibleSettingsSections((level) => canInTenant(props.tenant, level)));

function itemClasses(current) {
    return [
        'flex h-9 items-center gap-2 rounded-lg px-2.5 text-[13px] no-underline',
        PRESS_WIDE,
        current ? 'bg-selected font-bold text-ink' : 'text-ink-muted hover:bg-subtle hover:text-ink',
    ];
}
</script>

<template>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[13rem_minmax(0,1fr)] lg:items-start">
        <nav aria-label="設定の目次" class="hidden flex-col gap-4 rounded-card bg-surface p-3 lg:sticky lg:top-8 lg:flex" data-test="settings-toc">
            <div v-for="section in sections" :key="section.key" class="flex flex-col gap-0.5">
                <p class="px-2.5 pb-1 text-[11px] font-bold text-ink-soft">{{ section.title }}</p>
                <template v-for="item in section.items" :key="item.key">
                    <RouterLink
                        v-if="item.route"
                        :to="{ name: item.route, params: { tenant } }"
                        :aria-current="item.route === route.name ? 'page' : null"
                        :class="itemClasses(item.route === route.name)"
                        :data-test="`toc-${item.key}`"
                    >
                        {{ item.label }}
                    </RouterLink>
                    <span v-else class="flex h-9 items-center justify-between gap-2 px-2.5 text-[13px] text-ink-soft" aria-disabled="true" data-test="toc-soon">
                        {{ item.label }}<span class="rounded bg-subtle px-1.5 py-px text-[11px] font-bold">近日</span>
                    </span>
                </template>
            </div>
        </nav>
        <div class="flex min-w-0 flex-col gap-6">
            <slot />
        </div>
    </div>
</template>
