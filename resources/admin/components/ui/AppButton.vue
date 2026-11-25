<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { m } from 'motion-v';
import AppIcon from './AppIcon.vue';
import { PRESS } from './controlClasses.js';
import { doneSoFar, TRANSITION_FAST } from './motion.js';

// ボタン。画面の主な操作（primary）は 1 画面に 1 つ（ベイジの 9）。to を渡すと画面の移動のリンクになる。
// loading の間は回る印を出して押せなくする（時間のかかる操作。二度押しを防ぎ、押せたことを伝える）。
// 回し終えた時に済んだ通知（AppNotice の success）が出ていれば、回る印の場所にチェックを一瞬出す（保存した印）。押している間はわずかに縮む
const props = defineProps({
    variant: { type: String, default: 'secondary', validator: (v) => ['primary', 'secondary', 'danger', 'ghost'].includes(v) },
    // lg はログイン前の画面のような、1 枚のカードの主な操作。icon はアイコンだけの四角いボタン（sm と同じ高さ。狭い列の行の操作）
    size: { type: String, default: 'md', validator: (v) => ['lg', 'md', 'sm', 'icon'].includes(v) },
    to: { type: [String, Object], default: null },
    // 外の画面（店頭など）を新しいタブで開くリンク。URL はサーバーが渡したものだけ
    href: { type: String, default: null },
    type: { type: String, default: 'button' },
    loading: { type: Boolean, default: false },
});

const VARIANTS = {
    primary: 'bg-accent text-on-accent shadow-sm hover:bg-accent-hover hover:text-on-accent',
    secondary: 'bg-surface text-ink-muted ring-1 ring-line-strong hover:bg-hover hover:text-ink',
    danger: 'bg-surface text-danger ring-1 ring-danger/40 hover:bg-danger-soft hover:text-danger',
    ghost: 'text-ink-soft hover:bg-hover hover:text-ink',
};

const SIZES = { lg: 'h-12 px-6 text-[15px]', md: 'h-10 px-4 text-sm', sm: 'h-8 px-3 text-[13px]', icon: 'size-8 shrink-0 text-[13px]' };

const classes = computed(() => [
    'inline-flex items-center justify-center gap-2 rounded-lg font-bold whitespace-nowrap no-underline',
    PRESS,
    'disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50',
    SIZES[props.size],
    VARIANTS[props.variant],
]);

// 保存した印: 回し始めた時の「済んだ通知の回数」を覚え、回し終えた後の描き直し（通知が出る）を待って、増えていればチェックを出す
const DONE_FOR_MS = 1200;
const done = ref(false);
let doneBefore = 0;
let doneTimer = null;

watch(() => props.loading, async (loading, wasLoading) => {
    if (loading) {
        doneBefore = doneSoFar();
        done.value = false;

        return;
    }

    if (!wasLoading) {
        return;
    }

    await nextTick();

    if (doneSoFar() > doneBefore) {
        done.value = true;
        clearTimeout(doneTimer);
        doneTimer = setTimeout(() => {
            done.value = false;
        }, DONE_FOR_MS);
    }
});

onBeforeUnmount(() => clearTimeout(doneTimer));

// チェックは小さく縮めた形からふわっと出る（「動きを減らす」の人には、App.vue の MotionConfig が動きを止めて最後の形で出す）
const checkFrom = { opacity: 0, scale: 0.6 };
const checkTo = { opacity: 1, scale: 1 };
</script>

<template>
    <RouterLink v-if="to" :to="to" :class="classes"><slot /></RouterLink>
    <a v-else-if="href" :href="href" target="_blank" rel="noopener noreferrer" :class="classes"><slot /><span class="sr-only">（新しいタブ）</span></a>
    <button v-else :type="type" :class="classes" :disabled="loading || undefined" :aria-busy="loading || undefined">
        <svg v-if="loading" class="size-4 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true" data-test="button-spinner">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
        <m.span v-else-if="done" class="inline-flex" aria-hidden="true" :initial="checkFrom" :animate="checkTo" :transition="TRANSITION_FAST" data-test="button-done">
            <AppIcon name="check" />
        </m.span>
        <slot />
    </button>
</template>
