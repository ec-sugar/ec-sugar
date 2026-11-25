<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { visibleOperatorItems } from '../navigation.js';
import { session } from '../session.js';
import { onlyTenant, tenantDirectory, tenantName } from '../tenants.js';
import AppIcon from './ui/AppIcon.vue';
import AppReveal from './ui/AppReveal.vue';
import { PRESS_WIDE } from './ui/controlClasses.js';

// 店の切り替え（案 C）。押すと小さな窓が開き、ほかの店へ移る（見られる店が 2 つ以上のとき）と、運営全体の画面
// （店の一覧・メンバーと権限・App とテーマ・ログインの履歴）へ移れる。店の中のメニューには運営全体を混ぜない。
// 窓は外を押す・Esc・画面を移ると閉じる。出し分けは見た目のためだけで、守るのはサーバー（RequireOwner と店ごとの段階）
const props = defineProps({
    tenant: { type: String, default: null },
    collapsed: { type: Boolean, default: false },
    reveal: { type: String, default: '' },
});

// 窓に並べる店の数。多い店は「店の一覧」から探す
const SHOP_LIMIT = 8;

const route = useRoute();
const open = ref(false);
const root = ref(null);
const button = ref(null);

const single = computed(() => onlyTenant() !== null);
const label = computed(() => (props.tenant ? tenantName(props.tenant) : '店を選んでください'));
// 今の店はいつも先頭に（多い店でも窓から外れない）
const shops = computed(() => {
    const ids = Object.keys(tenantDirectory.names).filter((id) => id !== props.tenant);
    const ordered = props.tenant && tenantDirectory.names[props.tenant] !== undefined ? [props.tenant, ...ids] : ids;

    return ordered.slice(0, SHOP_LIMIT).map((id) => ({ id, name: tenantName(id), current: id === props.tenant }));
});
const operatorItems = computed(() => visibleOperatorItems(session.operator?.role === 'owner', single.value));

function toggle() {
    open.value = !open.value;
}

function close(focus = false) {
    open.value = false;

    if (focus) {
        button.value?.focus();
    }
}

function outside(event) {
    if (open.value && root.value && !root.value.contains(event.target)) {
        close();
    }
}

onMounted(() => document.addEventListener('pointerdown', outside));
onBeforeUnmount(() => document.removeEventListener('pointerdown', outside));
watch(() => route.fullPath, () => close());

function itemClasses(current) {
    return [
        'flex h-9 items-center gap-2.5 rounded-lg px-2.5 text-[13px] no-underline',
        PRESS_WIDE,
        current ? 'bg-selected font-bold text-ink [&_svg]:text-accent' : 'text-ink-muted hover:bg-subtle hover:text-ink',
    ];
}
</script>

<template>
    <div ref="root" class="relative" @keydown.esc="close(true)">
        <button
            ref="button"
            type="button"
            :aria-expanded="open"
            aria-controls="shop-switcher-panel"
            :title="collapsed ? label : null"
            :class="[
                'flex w-full items-center gap-2 rounded-lg bg-surface text-left text-ink ring-1 ring-line-strong hover:bg-subtle',
                PRESS_WIDE,
                collapsed ? 'size-10 justify-center' : 'h-12 justify-between px-3',
            ]"
            :data-test="single ? 'shop-name' : 'tenant-switcher'"
            @click="toggle"
        >
            <AppIcon v-if="collapsed" name="building" />
            <template v-else>
                <span :class="['flex min-w-0 flex-col leading-tight', reveal]">
                    <span class="text-[11px] text-ink-soft">{{ single ? 'ショップ' : '店' }}</span>
                    <span class="truncate text-[13px] font-bold">{{ label }}</span>
                </span>
                <AppIcon name="chevron-up-down" :size="14" class="text-ink-soft" />
            </template>
            <span class="sr-only">（店の切り替えと運営全体）</span>
        </button>

        <AppReveal
            id="shop-switcher-panel"
            :show="open"
            :class="[
                'absolute z-30 flex flex-col gap-1 rounded-xl bg-surface p-2 shadow-pop ring-1 ring-line',
                collapsed ? 'top-0 left-full ml-3 w-60' : 'inset-x-0 top-full mt-2',
            ]"
            data-test="shop-switcher-panel"
        >
            <template v-if="!single && shops.length > 0">
                <p class="px-2.5 pt-1 pb-0.5 text-[11px] font-bold text-ink-soft">店を切り替える</p>
                <nav aria-label="店を切り替える" class="flex flex-col gap-0.5">
                    <RouterLink
                        v-for="shop in shops"
                        :key="shop.id"
                        :to="{ name: 'tenant', params: { tenant: shop.id } }"
                        :aria-current="shop.current ? 'true' : null"
                        :class="itemClasses(shop.current)"
                        data-test="switch-shop"
                    >
                        <AppIcon name="store" />
                        <span class="min-w-0 flex-1 truncate">{{ shop.name }}</span>
                        <AppIcon v-if="shop.current" name="check" :size="14" />
                    </RouterLink>
                </nav>
                <div class="my-1 border-t border-line" />
            </template>
            <p class="px-2.5 pt-1 pb-0.5 text-[11px] font-bold text-ink-soft">運営全体</p>
            <nav aria-label="運営全体" class="flex flex-col gap-0.5">
                <RouterLink
                    v-for="item in operatorItems"
                    :key="item.key"
                    :to="{ name: item.route }"
                    :aria-current="item.match.includes(route.name) ? 'page' : null"
                    :class="itemClasses(item.match.includes(route.name))"
                    :data-test="`nav-${item.key}`"
                >
                    <AppIcon :name="item.icon" />
                    <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                </RouterLink>
            </nav>
        </AppReveal>
    </div>
</template>
