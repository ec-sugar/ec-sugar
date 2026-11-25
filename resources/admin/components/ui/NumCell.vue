<script setup>
import { computed } from 'vue';

// 表の数の欄（右揃え・等幅）。0 は薄く、主な数は強く
const props = defineProps({
    value: { type: [Number, String], required: true },
    strong: { type: Boolean, default: false },
    soft: { type: Boolean, default: false },
});

const classes = computed(() => [
    'num',
    props.strong ? 'font-medium' : '',
    props.value === 0 ? 'text-ink-faint' : (props.soft ? 'text-ink-muted' : 'text-ink'),
]);
</script>

<template>
    <td :class="classes">{{ value }}</td>
</template>
