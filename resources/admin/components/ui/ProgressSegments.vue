<script setup>
import { computed } from 'vue';

// 進み具合の帯（開設の手順）。全体の数だけ区切った帯で、済んだ数だけ藍に塗る。
// 幅を style で書かない（CSP）ので、割合でなく区切りの数で見せる。数は文字でも出し、読み上げには progressbar で伝える
const props = defineProps({
    done: { type: Number, required: true },
    total: { type: Number, required: true },
    label: { type: String, required: true },
});

const segments = computed(() => Array.from({ length: props.total }, (_, index) => index < props.done));
</script>

<template>
    <div class="flex items-center gap-3">
        <div
            role="progressbar"
            :aria-label="label"
            aria-valuemin="0"
            :aria-valuemax="total"
            :aria-valuenow="done"
            :aria-valuetext="`${total} のうち ${done} 済`"
            class="flex flex-1 gap-1"
        >
            <span v-for="(filled, index) in segments" :key="index" :class="['h-1.5 flex-1 rounded-full', filled ? 'bg-accent' : 'bg-line-strong']" />
        </div>
        <span class="tabular shrink-0 text-[13px] font-bold text-ink-muted" data-test="setup-progress">{{ done }} / {{ total }} 済</span>
    </div>
</template>
