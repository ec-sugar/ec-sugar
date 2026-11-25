<script setup>
import { useReducedMotion } from 'motion-v';

// 開き閉じする欄の中身。show が真の間だけ描き、開く時は上から数 px でふわっと出て、閉じる時は同じ道を戻って消える。
// Vue の組み込みの Transition と CSS で動かす（motion-v の AnimatePresence＝消える間だけ残す囲み は、これだけのためには重い。gzip で +4.4 kB）。
// 呼ぶ側の属性（class・data-test など）は中身の箱に付く。「動きを減らす」を選んだ人には動かさず、すぐ出してすぐ消す（消える間も残さない）
defineProps({ show: { type: Boolean, required: true } });
defineOptions({ inheritAttrs: false });

const reduced = useReducedMotion();
</script>

<template>
    <Transition
        :css="!reduced"
        enter-active-class="transition-[opacity,translate] duration-150 ease-out"
        enter-from-class="-translate-y-1 opacity-0"
        leave-active-class="transition-[opacity,translate] duration-150 ease-out"
        leave-to-class="-translate-y-1 opacity-0"
    >
        <div v-if="show" v-bind="$attrs"><slot /></div>
    </Transition>
</template>
