<script setup>
import { watchEffect } from 'vue';
import { useRoute } from 'vue-router';
import { domAnimation, LazyMotion, MotionConfig } from 'motion-v';
import { titleOf } from './navigation.js';
import { session } from './session.js';
import { tenantName } from './tenants.js';
import { appPageTitle } from './appPages.js';
import AppShell from './components/AppShell.vue';
import ConfirmDialog from './components/ConfirmDialog.vue';

// 動き（ADR 0037）: 全体を MotionConfig と LazyMotion で包む。部品は motion-v の m.* を書くだけで動き、
// 端末の「動きを減らす」を選んだ人には motion-v が動きを止める（reducedMotion="user"）。読むのは動きの機能だけ（domAnimation）
// ログイン中はメニューつきの骨組み、ログインと登録の画面（meta.bare）は左に看板・右に入口（AuthLayout）、ほかは中央に 1 枚だけ
const route = useRoute();

// ブラウザのタブの題: 画面の題（メニューの定義から）と、店の中では店の名前（どの店のタブか分かるように）
watchEffect(() => {
    const id = typeof route.params.tenant === 'string' && session.operator ? route.params.tenant : null;
    const tenant = id === null ? null : tenantName(id);
    // App の画面（ADR 0051）の題は App の名前（店の App の画面の並びから）
    const appTitle = id === null ? null : appPageTitle(id, route.params.plugin);
    document.title = [titleOf(route.name, route.params, appTitle), tenant, 'EC-SUGAR 管理'].filter((part) => part).join(' — ');
});
</script>

<template>
    <MotionConfig reduced-motion="user">
        <LazyMotion :features="domAnimation">
            <AppShell v-if="session.operator && !route.meta.bare && !route.meta.fullscreen">
                <RouterView />
            </AppShell>
            <!-- 画面の全体を使う画面（meta.fullscreen。ページの編集）は、骨組みも画面が持つ（EditorLayout） -->
            <RouterView v-else-if="session.operator && route.meta.fullscreen" />
            <!-- ログインと登録の画面（meta.bare）は、画面ごとに外枠（AuthLayout）を持つ -->
            <RouterView v-else-if="route.meta.bare" />
            <main v-else class="flex min-h-screen items-start justify-center px-4 py-12 sm:py-20">
                <div class="w-full max-w-lg">
                    <RouterView />
                </div>
            </main>
            <ConfirmDialog />
        </LazyMotion>
    </MotionConfig>
</template>
