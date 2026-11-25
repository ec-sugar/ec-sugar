<script setup>
import { CONTROL } from './controlClasses.js';

const model = defineModel({ type: [String, null], default: '' });
// code: 等幅の字で書く欄（セクションの「自由な HTML」）。折り返さず、横に動く
defineProps({
    code: { type: Boolean, default: false },
});
</script>

<template>
    <textarea v-model="model" :class="[CONTROL, code ? 'min-h-72 py-2 font-mono text-xs leading-relaxed whitespace-pre' : 'min-h-28 py-2 leading-relaxed']" :spellcheck="code ? 'false' : undefined" />
</template>
