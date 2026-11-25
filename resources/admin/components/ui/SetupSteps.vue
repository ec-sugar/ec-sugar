<script setup>
import AppIcon from './AppIcon.vue';

// 開設の手順（ホーム）。番号つきの手順の並びで、済んだ手順は番号の代わりに藍の丸のチェックと「済」の札。
// 済んだかはサーバーが店のデータから決める（この部品は見せるだけ）。手順ごとの操作（画面へ移るボタン）は #action、
// 手順の下に足す中身（サンプルの手順など）は #detail に、どちらも { step } を受けて親が置く。
// 狭い画面では操作を説明の下へ回す（390px でも横にはみ出さない）。色だけに頼らず、読み上げにも済・未を言う
defineProps({
    label: { type: String, required: true },
    steps: { type: Array, required: true }, // [{ key, label, description, note, done, test }]
});
</script>

<template>
    <ol :aria-label="label" class="flex flex-col">
        <li
            v-for="(step, index) in steps"
            :key="step.key"
            class="flex gap-4 border-t border-line py-4 first:border-t-0 first:pt-0 last:pb-0"
            :data-test="step.test"
            :data-done="step.done ? 'true' : 'false'"
        >
            <span
                aria-hidden="true"
                :class="[
                    'tabular flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-bold',
                    step.done ? 'bg-accent text-on-accent' : 'bg-subtle text-ink-muted ring-1 ring-line-strong',
                ]"
            >
                <AppIcon v-if="step.done" name="check" />
                <template v-else>{{ index + 1 }}</template>
            </span>
            <div class="flex min-w-0 flex-1 flex-col gap-3 sm:flex-row sm:items-start">
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="sr-only">手順 {{ index + 1 }}：</span>
                        <span :class="['text-sm font-bold', step.done ? 'text-ink-muted' : 'text-ink']">{{ step.label }}</span>
                        <span v-if="step.done" class="rounded bg-accent-soft px-1.5 py-px text-[11px] font-bold text-accent" data-test="step-done">済</span>
                        <span v-else class="sr-only">（まだです）</span>
                    </span>
                    <span class="text-[13px] leading-relaxed text-ink-soft">{{ step.description }}</span>
                    <span v-if="step.note" class="flex items-start gap-1.5 text-[13px] leading-relaxed text-ink-muted" data-test="step-note">
                        <AppIcon name="info" :size="14" class="mt-0.5 shrink-0 text-warn" />{{ step.note }}
                    </span>
                    <slot name="detail" :step="step" />
                </div>
                <div v-if="$slots.action" class="flex shrink-0 items-center gap-2"><slot name="action" :step="step" /></div>
            </div>
        </li>
    </ol>
</template>
