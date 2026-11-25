<script setup>
import { computed } from 'vue';
import { cellText, linkRoute, statusTone } from '../../appPages.js';
import StatusBadge from './StatusBadge.vue';
import TextLink from './TextLink.vue';

// App の画面（ADR 0051）の 1 つの値。形は核が決めた型（type）だけで、描くのはこの部品（App の画面のコードは読み込まない）。
// - text・number・money・date・datetime: 文字だけ（money は「¥5,400」、日付は管理画面のほかの画面と同じ形）。HTML として差し込まない
// - status: 状態の札（色の種類 good・warn・bad・muted を札の色に。意味は文字で）
// - link: 核の画面（お客さん・注文・商品）へのリンク。行き先は種類と ID から核の経路の名前で作り、URL を受け取らない。知らない種類は文字だけ
// 値が無ければ「—」
const props = defineProps({
    type: { type: String, required: true },
    value: { type: [String, Number, Object], default: null },
    tenant: { type: String, required: true },
});

const view = computed(() => {
    const text = cellText(props.type, props.value);

    if (props.value === null || props.value === undefined) {
        return { kind: 'text', text };
    }

    if (props.type === 'status') {
        return { kind: 'status', text, tone: statusTone(props.value?.tone) };
    }

    if (props.type === 'link') {
        const to = linkRoute(props.value, props.tenant);

        return to === null ? { kind: 'text', text } : { kind: 'link', text, to };
    }

    return { kind: 'text', text };
});
</script>

<template>
    <StatusBadge v-if="view.kind === 'status'" :tone="view.tone">{{ view.text }}</StatusBadge>
    <TextLink v-else-if="view.kind === 'link'" :to="view.to">{{ view.text }}</TextLink>
    <template v-else>{{ view.text }}</template>
</template>
