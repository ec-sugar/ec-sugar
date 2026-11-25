<script setup>
import StatusBadge from './StatusBadge.vue';

// 機械の調子の並び（運営全体の画面）。1 行に名前と一言（左）、状態の札（右。色の丸と文字）。
// 状態は ok（問題なし）・warn（注意）・bad（要対応）・unknown（まだ見ていません）。色だけで伝えず、札の文字で
defineProps({
    items: { type: Array, required: true }, // [{ key, label, status, text, test }]
});

const BADGES = {
    ok: { tone: 'ok', label: '問題なし' },
    warn: { tone: 'warn', label: '注意' },
    bad: { tone: 'danger', label: '要対応' },
    unknown: { tone: 'neutral', label: '未確認' },
};
</script>

<template>
    <ul class="flex flex-col">
        <li v-for="item in items" :key="item.key" class="flex items-center gap-3 border-t border-line py-3 first:border-t-0 first:pt-0" :data-test="item.test" :data-status="item.status">
            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="text-sm font-bold text-ink">{{ item.label }}</span>
                <span v-if="item.text" class="text-[13px] text-ink-soft">{{ item.text }}</span>
            </span>
            <StatusBadge :tone="(BADGES[item.status] ?? BADGES.unknown).tone">{{ (BADGES[item.status] ?? BADGES.unknown).label }}</StatusBadge>
        </li>
    </ul>
</template>
