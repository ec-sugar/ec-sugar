<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { api, describe } from '../api.js';
import { HELP_ITEM, isCurrentItem, OPERATOR_ITEMS, SELF_ITEMS, visibleTenantItems } from '../navigation.js';
import { appPagesOf, forgetAppPages, loadAppPages } from '../appPages.js';
import { session } from '../session.js';
import { forgetShopSettings } from '../shopSettings.js';
import { canInTenant, forgetTenants, loadTenantNames, onlyTenant, storefrontUrl } from '../tenants.js';
import IsolatedCopyNotice from './IsolatedCopyNotice.vue';
import ShopSwitcher from './ShopSwitcher.vue';
import AppButton from './ui/AppButton.vue';
import BrandLogo from './ui/BrandLogo.vue';
import AppIcon from './ui/AppIcon.vue';
import AppNotice from './ui/AppNotice.vue';
import { PRESS, PRESS_WIDE } from './ui/controlClasses.js';
import { fadeUp } from './ui/motion.js';

// 画面の骨組み: 左のメニュー（畳める。ベイジの 12）と中身。メニューは「対象」で並べる（ベイジの 5）。
// 階層は「店を選ぶ → 店の中のメニュー」の 2 段まで（ベイジの 6）。
// 見た目は灰の地に白い板を浮かせる形（案 C）: メニューは灰の地に浮かせた角の丸い白い板、選択中は灰の面と太字と藍のアイコン。
// 上から店の切り替え（中に運営全体）・主なメニュー（ホーム・注文・商品・写真・デザイン・App）・下に「設定 ›」とヘルプ・自分（パスキーとログアウト）。
// 狭い画面（md より狭い）では、メニューは「メニュー」のボタンで開き閉じし、画面を移ると閉じる
const route = useRoute();
const router = useRouter();
const collapsed = ref(false);
const busy = ref(false);
const error = ref(null);

// 店の中の画面ならその店。店の外の画面（メンバー・パスキーなど）でも、見られる店が 1 つならその店のメニューを出す
const tenant = computed(() => (typeof route.params.tenant === 'string' ? route.params.tenant : onlyTenant()));

// 項目はメニューの定義（navigation.js）から。段階の出し分けは見た目だけで、守るのはサーバー。
// App の画面の項目（ADR 0051）は経路の引数（App の名前）を持つ
function link(item) {
    return { ...item, to: tenant.value && item.group ? { name: item.route, params: { tenant: tenant.value, ...(item.params ?? {}) } } : { name: item.route } };
}

// 店の App の画面の並び（メニューの「App」の下に足す）。店が変わったら読む（読んだ店は読み直さない）
watch(tenant, (id) => loadAppPages(id), { immediate: true });

// 狭い画面でメニューを開いているか（広い画面ではいつも出す）
const mobileOpen = ref(false);
watch(() => route.fullPath, () => {
    mobileOpen.value = false;
});

const can = (level) => canInTenant(tenant.value, level);
// 動いているバージョン（コミットの印の先頭と日付）。焼き込んでいない開発の環境では「開発中」
const versionLabel = computed(() => {
    const system = session.system;

    if (!system?.short) {
        return '開発中';
    }

    return system.date ? `${system.short}（${new Date(system.date).toLocaleDateString('ja-JP')}）` : system.short;
});
// 店に入る前（店の一覧・運営全体の画面で、見られる店が 2 つ以上）は、主なメニューに店の一覧だけを出す
const items = computed(() => (tenant.value ? visibleTenantItems('main', can, appPagesOf(tenant.value)) : OPERATOR_ITEMS.filter((item) => item.key === 'tenants')).map(link));
const lowerItems = computed(() => [...(tenant.value ? visibleTenantItems('settings', can) : []), HELP_ITEM].map(link));
const selfItems = computed(() => SELF_ITEMS.map(link));

onMounted(loadTenantNames);

// 画面の切り替え: 別の画面（経路の名前か引数が変わった）へ移ったら、中身を下から数 px でふわっと出す。
// 前の画面はすぐ消し、新しい画面だけを 0.2 秒で出す（待たせない）。絞り込み・並べ替え（URL の ? の後ろ）では動かさない
const content = ref(null);
const pageKey = computed(() => `${String(route.name)}:${JSON.stringify(route.params)}`);

onMounted(() => fadeUp(content.value));
watch(pageKey, () => fadeUp(content.value), { flush: 'post' });

// メニューを開いた時: 名前を横から数 px でふわっと出す。最初に描く時は動かさない（押して開いた時と、項目が入れ替わった時だけ）
const toggled = ref(false);
const reveal = computed(() => (toggled.value ? 'animate-reveal-x' : ''));

function toggleMenu() {
    toggled.value = true;
    collapsed.value = !collapsed.value;
}

async function logout() {
    busy.value = true;
    error.value = null;

    try {
        await api('POST', '/auth/logout');
        session.operator = null;
        session.system = null;
        session.passkeys = null;
        session.recoveryPending = null;
        session.unusualSession = null;
        forgetShopSettings();
        forgetTenants();
        forgetAppPages();
        await router.replace({ name: 'login' });
    } catch (e) {
        error.value = describe(e);
    } finally {
        busy.value = false;
    }
}

function isCurrent(item) {
    return isCurrentItem(item, route.name, route.params);
}

function itemClasses(item) {
    const current = isCurrent(item);

    return [
        'flex h-10 items-center gap-3 rounded-lg px-3 text-sm no-underline',
        PRESS_WIDE,
        collapsed.value ? 'md:justify-center md:px-0' : '',
        // App の画面の項目は「App」の下に字下げ（畳んだときは字下げしない）
        item.sub ? 'pl-8' : '',
        current ? 'bg-selected font-bold text-ink [&_svg]:text-accent' : 'text-ink-muted hover:bg-subtle hover:text-ink',
    ];
}
</script>

<template>
    <div class="flex min-h-screen flex-col md:flex-row">
        <!-- 白い板。広い画面では地の上に浮かせて、画面の高さに合わせて留める（外に 10px） -->
        <aside
            :class="[
                'm-2 flex shrink-0 flex-col gap-4 rounded-board bg-surface px-3 py-3 md:gap-5 md:py-5',
                'md:sticky md:top-2.5 md:z-20 md:m-2.5 md:mr-0 md:h-[calc(100vh-1.25rem)]',
                collapsed ? 'md:w-16 md:px-2' : 'md:w-60',
            ]"
            aria-label="メインメニュー"
        >
            <div :class="['flex items-center gap-2', collapsed ? 'md:justify-center' : 'pl-2']">
                <BrandLogo :class="collapsed ? 'md:hidden' : reveal" />
                <span :class="['flex-1 text-xs text-ink-soft', collapsed ? 'md:hidden' : reveal]">管理</span>
                <button
                    type="button"
                    :aria-label="collapsed ? 'メニューを開く' : 'メニューを畳む'"
                    :title="collapsed ? 'メニューを開く' : 'メニューを畳む'"
                    :aria-expanded="!collapsed"
                    :class="['hidden size-8 items-center justify-center rounded-lg text-ink-soft hover:bg-hover hover:text-ink md:flex', PRESS]"
                    @click="toggleMenu"
                >
                    <AppIcon name="panel-left" />
                </button>
                <!-- 狭い画面だけ: メニューを開き閉じする -->
                <button
                    type="button"
                    :aria-expanded="mobileOpen"
                    aria-controls="admin-menu"
                    :class="['flex h-9 items-center gap-1.5 rounded-lg px-2.5 text-[13px] font-bold text-ink-muted ring-1 ring-line-strong hover:bg-subtle md:hidden', PRESS]"
                    data-test="menu-toggle"
                    @click="mobileOpen = !mobileOpen"
                >
                    <AppIcon :name="mobileOpen ? 'x' : 'menu'" />メニュー
                </button>
            </div>

            <!-- 店の切り替え。中に運営全体（店の一覧・メンバーと権限・App とテーマ・ログインの履歴） -->
            <ShopSwitcher :tenant="tenant" :collapsed="collapsed" :reveal="reveal" />

            <!-- メニューの中だけを縦に動かす（板ごと動かすと、店の切り替えの窓が板の外で切れる） -->
            <div id="admin-menu" :class="['-mx-3 flex-1 flex-col gap-4 px-3 md:flex md:min-h-0 md:gap-5 md:overflow-y-auto', collapsed ? 'md:-mx-2 md:px-2' : '', mobileOpen ? 'flex' : 'hidden']">
                <nav aria-label="店のメニュー" :class="['-mx-3 flex flex-col gap-0.5 border-t border-line px-3 pt-4', collapsed ? 'md:-mx-2 md:px-2' : '']">
                    <RouterLink
                        v-for="item in items"
                        :key="item.key"
                        :to="item.to"
                        :title="collapsed ? item.label : null"
                        :aria-current="isCurrent(item) ? 'page' : null"
                        :class="itemClasses(item)"
                        :data-test="`nav-${item.key}`"
                    >
                        <AppIcon :name="item.icon" />
                        <span :class="collapsed ? 'md:sr-only' : reveal">{{ item.label }}</span>
                    </RouterLink>
                    <!-- ショップを新しいタブで開く（URL はサーバーが渡す） -->
                    <a
                        v-if="tenant && storefrontUrl(tenant)"
                        :href="storefrontUrl(tenant)"
                        target="_blank"
                        rel="noopener noreferrer"
                        :title="collapsed ? 'ショップを開く（新しいタブ）' : null"
                        :class="itemClasses({ match: [] })"
                        data-test="open-storefront"
                    >
                        <AppIcon name="external" />
                        <span :class="collapsed ? 'md:sr-only' : reveal">ショップを開く<span class="sr-only">（新しいタブ）</span></span>
                    </a>
                </nav>

                <div :class="['-mx-3 mt-auto flex flex-col gap-0.5 border-t border-line px-3 pt-3', collapsed ? 'md:-mx-2 md:px-2' : '']">
                    <!-- 下の区切り線の下: 店の「設定 ›」とヘルプ -->
                    <nav aria-label="設定とヘルプ" class="flex flex-col gap-0.5">
                        <RouterLink
                            v-for="item in lowerItems"
                            :key="item.key"
                            :to="item.to"
                            :title="collapsed ? item.label : null"
                            :aria-current="isCurrent(item) ? 'page' : null"
                            :class="itemClasses(item)"
                            :data-test="`nav-${item.key}`"
                        >
                            <AppIcon :name="item.icon" />
                            <span :class="collapsed ? 'md:sr-only' : ['flex-1', reveal]">{{ item.label }}</span>
                            <AppIcon v-if="item.group === 'settings'" name="chevron-right" :size="14" :class="['text-ink-soft', collapsed ? 'md:hidden' : '']" />
                        </RouterLink>
                    </nav>
                    <!-- 自分: 名前・パスキー・ログアウト -->
                    <div :class="['mt-2 flex flex-col gap-2 border-t border-line pt-3', collapsed ? 'md:items-center' : 'px-2']">
                        <div class="flex min-w-0 items-center gap-3">
                            <span aria-hidden="true" class="flex size-7 shrink-0 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent">
                                {{ session.operator.name.slice(0, 1) }}
                            </span>
                            <span :class="['flex min-w-0 flex-col leading-tight', collapsed ? 'md:sr-only' : reveal]">
                                <span class="truncate text-[13px] font-bold" data-test="operator-name">{{ session.operator.name }}</span>
                                <span class="truncate text-[11px] text-ink-soft">{{ session.operator.email }}</span>
                            </span>
                        </div>
                        <nav aria-label="自分のもの" :class="['-mx-2 flex flex-col gap-0.5', collapsed ? 'md:mx-0' : '']">
                            <RouterLink
                                v-for="item in selfItems"
                                :key="item.key"
                                :to="item.to"
                                :title="collapsed ? item.label : null"
                                :aria-current="isCurrent(item) ? 'page' : null"
                                :class="itemClasses(item)"
                                :data-test="`nav-${item.key}`"
                            >
                                <AppIcon :name="item.icon" />
                                <span :class="collapsed ? 'md:sr-only' : reveal">{{ item.label }}</span>
                            </RouterLink>
                        </nav>
                        <!-- 畳んだときは板の幅に収まるようアイコンだけ（読み上げの名前は「ログアウト」のまま） -->
                        <AppButton size="sm" variant="secondary" :disabled="busy" :title="collapsed ? 'ログアウト' : null" :class="collapsed ? 'md:px-2' : 'self-start'" @click="logout">
                            <template v-if="collapsed"><AppIcon name="log-out" class="hidden md:block" /><span class="md:sr-only">ログアウト</span></template>
                            <template v-else>ログアウト</template>
                        </AppButton>
                        <p :class="['text-[11px] leading-tight text-ink-soft', collapsed ? 'md:hidden' : reveal]" :title="session.system?.revision ?? null" data-test="system-version">
                            EC-SUGAR {{ versionLabel }}
                        </p>
                    </div>
                </div>
            </div>
        </aside>

        <main class="min-w-0 flex-1 px-4 pt-4 pb-16 md:px-8 md:py-8 lg:px-gutter">
            <div ref="content" class="mx-auto flex max-w-6xl flex-col gap-6">
                <IsolatedCopyNotice v-if="session.isolated" />
                <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>
                <slot />
            </div>
        </main>
    </div>
</template>
