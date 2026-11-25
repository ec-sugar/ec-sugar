<script setup>
import AppIcon from './AppIcon.vue';

// 手順の中の小さな手順（開設の手順の「最初の商品」の中の、サンプルを見る → 自分の商品に置き換える → まとめて消す）。
// 薄い灰の面に 1 行ずつ、済んだものはチェック、まだのものは空の丸。行ごとの操作は #action（{ item } を受けて親が置く）。
// 狭い画面では操作を名前の下へ回す。読み上げにも済・未を言う
defineProps({
    label: { type: String, required: true },
    items: { type: Array, required: true }, // [{ key, label, done, test }]
});
</script>

<template>
    <ol :aria-label="label" class="mt-1 flex flex-col divide-y divide-line rounded-xl bg-subtle">
        <li v-for="item in items" :key="item.key" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center" :data-test="item.test" :data-done="item.done ? 'true' : 'false'">
            <span class="flex min-w-0 flex-1 items-start gap-2.5 text-sm text-ink">
                <AppIcon v-if="item.done" name="check" :size="16" class="mt-0.5 shrink-0 text-accent" />
                <span v-else aria-hidden="true" class="mt-1 size-3.5 shrink-0 rounded-full ring-1 ring-line-strong" />
                <span>{{ item.label }}<span class="sr-only">{{ item.done ? '（済）' : '（まだです）' }}</span></span>
            </span>
            <span v-if="$slots.action" class="flex flex-wrap items-center gap-2 pl-6 sm:pl-0"><slot name="action" :item="item" /></span>
        </li>
    </ol>
</template>
