<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { PRESS_WIDE } from './controlClasses.js';
import { reducesMotion } from './motion.js';

// 長いフォームの節の目次（商品の登録）。押すとその節へ移り、焦点も節へ移す。
// 今見ている節に印（画面に入っている節のうち、いちばん上のもの）。保存していない変更のある節には小さな丸と「変更あり」（読み上げ用）。
// 置き場所（広い画面だけ・横に留める）は呼ぶ側が class で決める。節は id で探す（items の id は節の要素の id）
const props = defineProps({
    items: { type: Array, required: true }, // [{ id, label, changed }]
    label: { type: String, default: '目次' },
});
// 移る前に節の id を通知する（畳んだ節を開くなど）
const emit = defineEmits(['go']);

const current = ref(props.items[0]?.id ?? null);
let observer = null;
const visible = new Set();

function observe() {
    observer?.disconnect();
    visible.clear();

    if (typeof IntersectionObserver === 'undefined') {
        return;
    }

    // 画面の上の 1/3 に入っている節を「見ている」とする
    observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                visible.add(entry.target.id);
            } else {
                visible.delete(entry.target.id);
            }
        }

        const first = props.items.find((item) => visible.has(item.id));

        if (first) {
            current.value = first.id;
        }
    }, { rootMargin: '0px 0px -66% 0px' });

    for (const item of props.items) {
        const element = document.getElementById(item.id);

        if (element) {
            observer.observe(element);
        }
    }
}

onMounted(() => nextTick(observe));
onBeforeUnmount(() => observer?.disconnect());
watch(() => props.items.map((item) => item.id).join(), () => nextTick(observe));

function go(id) {
    emit('go', id);
    const element = document.getElementById(id);

    if (!element) {
        return;
    }

    current.value = id;
    element.scrollIntoView({ behavior: reducesMotion() ? 'auto' : 'smooth', block: 'start' });
    element.focus({ preventScroll: true });
}

defineExpose({ go });
</script>

<template>
    <nav :aria-label="label" class="flex-col gap-0.5 rounded-card bg-surface p-3" data-test="form-outline">
        <p class="px-2.5 pb-1 text-[11px] font-bold text-ink-soft">{{ label }}</p>
        <button
            v-for="item in items"
            :key="item.id"
            type="button"
            :aria-current="item.id === current ? 'location' : null"
            :class="[
                'flex h-9 items-center justify-between gap-2 rounded-lg px-2.5 text-left text-[13px]',
                PRESS_WIDE,
                item.id === current ? 'bg-selected font-bold text-ink' : 'text-ink-muted hover:bg-subtle hover:text-ink',
            ]"
            :data-test="`outline-${item.id}`"
            @click="go(item.id)"
        >
            <span>{{ item.label }}</span>
            <span v-if="item.changed" class="flex items-center" data-test="outline-changed">
                <span class="size-2 rounded-full bg-accent" aria-hidden="true" />
                <span class="sr-only">（変更あり）</span>
            </span>
        </button>
    </nav>
</template>
