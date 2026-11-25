<script setup>
import { computed, onMounted } from 'vue';
import { domAnimation, LazyMotion, m, useReducedMotion } from 'motion-v';
import AppIcon from './AppIcon.vue';
import { markDone, SHIFT, TRANSITION } from './motion.js';

// お知らせ。成功・案内は status（読み上げは控えめ）、誤り・警告は alert。色だけでなくアイコンと文で伝える
// live を外すと、画面を開いた時からある注意書き（操作の結果でない。例: パスキーが 1 本だけ）として note にする（開くたびに読み上げない）
const props = defineProps({
    tone: { type: String, default: 'info', validator: (v) => ['info', 'success', 'warning', 'error'].includes(v) },
    live: { type: Boolean, default: true },
});

const TONES = {
    info: { box: 'bg-info-soft text-info-ink', icon: 'info', role: 'status' },
    success: { box: 'bg-ok-soft text-ok-ink', icon: 'check', role: 'status' },
    warning: { box: 'bg-warn-soft text-warn-ink', icon: 'alert', role: 'alert' },
    error: { box: 'bg-danger-soft text-danger-ink', icon: 'alert', role: 'alert' },
};

// 呼ぶ側の属性（data-test など）は、外の囲み（LazyMotion。属性を受け流さない）ではなく、通知の箱に付ける
defineOptions({ inheritAttrs: false });

const tone = computed(() => TONES[props.tone]);

// 出るときに下から少しだけ浮かせて出す（Motion。ADR 0037）。ビルドを小さくするため、動きの機能だけ（domAnimation。ドラッグや配置の動きは入れない）を読む。消すときは同じ道を戻る（exit＝消える動き。呼ぶ側が AnimatePresence＝消える間だけ残す囲み で包んだときに効く）。
// 「視差効果を減らす」を選んだ人には動かさない（initial を false にすると、すぐ最後の形で出る）
const reduced = useReducedMotion();
const hidden = { opacity: 0, y: SHIFT * 2 };
const shown = { opacity: 1, y: 0 };
const transition = TRANSITION;

// 済んだ通知が出たことを伝える（回していたボタンが、中にチェックを一瞬出す）
onMounted(() => {
    if (props.tone === 'success' && props.live) {
        markDone();
    }
});
</script>

<template>
    <LazyMotion :features="domAnimation">
        <m.div
            v-bind="$attrs"
            :role="live ? tone.role : 'note'"
            :class="['flex items-start gap-2.5 rounded-lg px-4 py-3 text-sm', tone.box]"
            :initial="reduced ? false : hidden"
            :animate="shown"
            :exit="reduced ? shown : hidden"
            :transition="reduced ? { duration: 0 } : transition"
        >
            <AppIcon :name="tone.icon" class="mt-0.5" />
            <div class="flex min-w-0 flex-1 flex-col gap-2"><slot /></div>
        </m.div>
    </LazyMotion>
</template>
