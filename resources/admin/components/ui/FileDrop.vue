<script setup>
import { ref } from 'vue';
import AppIcon from './AppIcon.vue';

// ファイルを選ぶ枠。押して選ぶか、ファイルを落とす。選んだファイルは select で渡す（入力欄はその都度空に戻す）
const props = defineProps({
    accept: { type: String, default: '' },
    multiple: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    label: { type: String, required: true },
    hint: { type: String, default: null },
});
const emit = defineEmits(['select']);
const over = ref(false);

function pick(event) {
    const files = [...(event.target.files ?? [])];
    event.target.value = '';

    if (files.length > 0) {
        emit('select', files);
    }
}

function drop(event) {
    over.value = false;
    const files = [...(event.dataTransfer?.files ?? [])];

    if (!props.disabled && files.length > 0) {
        emit('select', props.multiple ? files : files.slice(0, 1));
    }
}
</script>

<template>
    <label
        :class="[
            'flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border border-dashed px-6 py-8 text-center transition-colors',
            'focus-within:ring-2 focus-within:ring-accent',
            over ? 'border-accent bg-accent-soft' : 'border-line-strong bg-surface hover:bg-hover',
            disabled ? 'pointer-events-none opacity-50' : '',
        ]"
        @dragover.prevent="over = true"
        @dragleave="over = false"
        @drop.prevent="drop"
    >
        <AppIcon name="upload" :size="20" />
        <span class="text-sm font-medium text-ink">{{ label }}</span>
        <span v-if="hint" class="text-xs text-ink-soft">{{ hint }}</span>
        <input type="file" class="sr-only" :accept="accept" :multiple="multiple" :disabled="disabled" data-test="file-input" @change="pick">
    </label>
</template>
