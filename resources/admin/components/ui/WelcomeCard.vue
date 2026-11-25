<script setup>
import { computed, ref } from 'vue';
import { m } from 'motion-v';
import AppButton from './AppButton.vue';
import AppIcon from './AppIcon.vue';
import { SHIFT, TRANSITION } from './motion.js';

// 最初の 1 回の「ようこそ」（ホームのいちばん上）。3〜4 枚の短い説明を「次へ」「戻る」でめくる。
// 画面をふさがない（ホームの中のカード。ほかの操作も、閉じずにできる）。
//   close: 閉じる（右上の ×・最後の「はじめる」）。親はもう出さない印をサーバーに置く
//   later: あとで見る。親はこの時だけ隠す（印は置かない）
// めくると、次の 1 枚が横から数 px でふわっと出る（「動きを減らす」の人には、App.vue の MotionConfig が動きを止める）
const props = defineProps({
    slides: { type: Array, required: true }, // [{ key, icon, title, body }]
});
const emit = defineEmits(['close', 'later']);

const index = ref(0);
const slide = computed(() => props.slides[Math.min(index.value, props.slides.length - 1)]);
const last = computed(() => index.value >= props.slides.length - 1);
const from = { opacity: 0, x: SHIFT };
const to = { opacity: 1, x: 0 };
</script>

<template>
    <section aria-labelledby="welcome-title" class="relative flex flex-col gap-5 rounded-card bg-surface p-5 ring-1 ring-accent-soft sm:p-card" data-test="welcome">
        <button
            type="button"
            class="absolute top-3 right-3 inline-flex size-9 items-center justify-center rounded-lg text-ink-soft hover:bg-hover hover:text-ink"
            aria-label="閉じる（今後は表示しません）"
            title="閉じる"
            data-test="welcome-close"
            @click="emit('close')"
        >
            <AppIcon name="x" />
        </button>

        <m.div :key="slide.key" class="flex gap-4 pr-8" aria-live="polite" :initial="from" :animate="to" :transition="TRANSITION" data-test="welcome-slide">
            <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent" aria-hidden="true">
                <AppIcon :name="slide.icon" :size="22" />
            </span>
            <span class="flex min-w-0 flex-col gap-1.5">
                <span class="text-xs font-bold text-ink-soft">はじめに・{{ index + 1 }} / {{ slides.length }}</span>
                <h2 id="welcome-title" class="text-lg font-bold text-ink" data-test="welcome-title">{{ slide.title }}</h2>
                <p class="max-w-prose text-sm leading-relaxed text-ink-muted">{{ slide.body }}</p>
            </span>
        </m.div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="flex gap-1.5" aria-hidden="true">
                <span v-for="(item, i) in slides" :key="item.key" :class="['size-2 rounded-full', i === index ? 'bg-accent' : 'bg-line-strong']" />
            </span>
            <span class="flex flex-wrap items-center gap-2">
                <AppButton variant="ghost" size="sm" data-test="welcome-later" @click="emit('later')">あとで見る</AppButton>
                <AppButton v-if="index > 0" size="sm" data-test="welcome-back" @click="index--"><AppIcon name="arrow-left" />戻る</AppButton>
                <AppButton v-if="!last" variant="primary" size="sm" data-test="welcome-next" @click="index++">次へ<AppIcon name="arrow-right" /></AppButton>
                <AppButton v-else variant="primary" size="sm" data-test="welcome-start" @click="emit('close')">はじめる</AppButton>
            </span>
        </div>
    </section>
</template>
