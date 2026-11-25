<script setup>
import { KEPT_REASONS, KEPT_TYPES } from '../samples.js';
import AppNotice from './ui/AppNotice.vue';

// サンプルをまとめて消した結果の通知: 断られた・消した数・消さずに残した物。ホームと商品の一覧で同じ文。
// removal は useSampleRemoval（samples.js）の戻り値
defineProps({ removal: { type: Object, required: true } });
</script>

<template>
    <AppNotice v-if="removal.operation.error.value" tone="error">{{ removal.operation.error.value }}</AppNotice>
    <AppNotice v-if="removal.operation.message.value" tone="success"><span data-test="samples-removed">{{ removal.operation.message.value }}</span></AppNotice>
    <AppNotice v-if="removal.result.value?.kept.length > 0" tone="info" data-test="samples-kept">
        <span>次のものは残しました（お店の物として扱います）。</span>
        <ul class="flex flex-col gap-1">
            <li v-for="(item, index) in removal.result.value.kept" :key="index">{{ KEPT_TYPES[item.type] ?? item.type }}{{ item.title ? `「${item.title}」` : '' }}（{{ KEPT_REASONS[item.reason] ?? item.reason }}）</li>
        </ul>
    </AppNotice>
</template>
