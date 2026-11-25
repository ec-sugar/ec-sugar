<script setup>
import { computed } from 'vue';
import AppIcon from './AppIcon.vue';

// 道のり（取引のフェーズの並び）。済んだ段は印、今の段は藍、先の段は薄く。今の位置は文字（aria-current）でも伝える
const props = defineProps({
    steps: { type: Array, required: true }, // [{ value, label }]
    current: { type: String, default: null },
    label: { type: String, required: true },
});

const currentIndex = computed(() => props.steps.findIndex((step) => step.value === props.current));
</script>

<template>
    <ol :aria-label="label" class="flex flex-wrap items-center gap-2">
        <template v-for="(step, index) in steps" :key="step.value">
            <li v-if="index > 0" aria-hidden="true" class="h-px w-6 bg-line-strong" />
            <li
                :aria-current="index === currentIndex ? 'step' : null"
                :class="[
                    'inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-[13px]',
                    index === currentIndex ? 'bg-accent font-medium text-on-accent' : '',
                    currentIndex >= 0 && index < currentIndex ? 'bg-subtle text-ink-muted' : '',
                    currentIndex < 0 || index > currentIndex ? 'text-ink-soft ring-1 ring-line-strong' : '',
                ]"
            >
                <AppIcon v-if="currentIndex >= 0 && index < currentIndex" name="check" :size="14" />
                {{ step.label }}
            </li>
        </template>
    </ol>
</template>
