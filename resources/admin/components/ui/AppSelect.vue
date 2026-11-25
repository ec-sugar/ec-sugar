<script setup>
import { CONTROL } from './controlClasses.js';

const model = defineModel({ type: [String, Number, null], default: '' });
</script>

<template>
    <select v-model="model" :class="[CONTROL, 'h-10 pr-8']"><slot /></select>
</template>
