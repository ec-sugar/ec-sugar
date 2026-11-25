<script setup>
import BrandLogo from './BrandLogo.vue';
import markWhite from '../../assets/brand/mark-white.png';

// ログインと登録の画面の外枠（ADR 0031 の案 A）。左に看板（ロゴ・見出し）、右に入口（題とフォーム）。
// スマホでは看板が上の帯になる。目立たせるのは看板の地の大きな角砂糖の線だけにして、ほかは静かに保つ
defineProps({
    title: { type: String, required: true }, // 右の題（例: ログイン）
    heading: { type: String, required: true }, // 看板の見出し
    lead: { type: String, default: null }, // 看板の見出しの下の一言
});
</script>

<template>
    <div class="grid min-h-screen bg-surface md:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
        <aside class="relative flex flex-col gap-6 overflow-hidden md:justify-between md:gap-8 bg-brand px-6 py-6 text-on-brand sm:px-10 md:py-10">
            <!-- 地の角砂糖（飾り。ロゴの印の白の版を薄く。読み上げない） -->
            <img :src="markWhite" alt="" aria-hidden="true" class="pointer-events-none absolute -right-20 -bottom-24 hidden size-[26rem] opacity-[0.07] md:block" width="128" height="128" />
            <BrandLogo tone="on-brand" size="lg" />
            <div class="relative flex flex-col gap-3">
                <p class="text-2xl font-bold leading-snug tracking-tight md:text-[2rem]">{{ heading }}</p>
                <p v-if="lead" class="max-w-sm text-sm leading-relaxed text-on-brand-soft">{{ lead }}</p>
            </div>
            <div class="hidden md:block" aria-hidden="true" />
        </aside>
        <main class="flex items-start justify-center px-6 py-10 sm:px-10 md:items-center md:py-16">
            <section class="flex w-full max-w-md flex-col gap-6">
                <h1 class="text-2xl font-bold tracking-tight text-ink md:text-[1.75rem]">{{ title }}</h1>
                <slot />
                <p v-if="$slots.footer" class="text-xs leading-relaxed text-ink-soft"><slot name="footer" /></p>
            </section>
        </main>
    </div>
</template>
