<script setup>
import { CONTROL } from './controlClasses.js';

// 1 行の入力欄。number のときは数（または空なら null）を返す
const model = defineModel({ type: [String, Number, null], default: '' });
defineProps({ type: { type: String, default: 'text' } });
</script>

<template>
    <input v-if="type === 'number'" v-model.number="model" type="number" :class="[CONTROL, 'h-10 tabular']">
    <input v-else v-model="model" :type="type" :class="[CONTROL, 'h-10']">
</template>
