<script setup>
import AppSelect from './AppSelect.vue';

// 絞り込みの箱（ListFilters）の中の選択（状態で絞る・並べ替え）。上に小さな名前、下に選択。
// 渡された属性（data-test など）は選択に付ける
defineOptions({ inheritAttrs: false });
const model = defineModel({ type: [String, Number], default: '' });
defineProps({ label: { type: String, required: true } });
</script>

<template>
    <label class="flex min-w-0 flex-col gap-1 text-xs font-bold text-ink-soft">
        {{ label }}
        <AppSelect v-model="model" v-bind="$attrs"><slot /></AppSelect>
    </label>
</template>
