<script setup>
import { ref } from 'vue';
import AppIcon from './AppIcon.vue';
import AppInput from './AppInput.vue';
import KeyHint from './KeyHint.vue';

// 検索欄（虫眼鏡と、ショートカットのキーの印）。Enter で submit を出す。focus() で外から検索欄へ移れる。
// 渡された属性（data-test など）は入力欄に付ける。wide は箱の幅いっぱいに広げる（一覧の絞り込みの箱の中）
defineOptions({ inheritAttrs: false });
const model = defineModel({ type: String, default: '' });
defineProps({
    label: { type: String, required: true },
    shortcut: { type: String, default: null },
    wide: { type: Boolean, default: false },
});
const emit = defineEmits(['submit']);
const input = ref(null);

defineExpose({ focus: () => input.value?.$el.focus() });
</script>

<template>
    <form role="search" :class="['relative flex w-full items-center', wide ? '' : 'max-w-xs']" @submit.prevent="emit('submit')">
        <AppIcon name="search" class="pointer-events-none absolute left-3 text-ink-faint" />
        <AppInput ref="input" v-model="model" type="search" maxlength="100" :placeholder="label" :aria-label="label" class="pr-10 pl-9" v-bind="$attrs" />
        <KeyHint v-if="shortcut" class="pointer-events-none absolute right-3 max-md:hidden" aria-hidden="true">{{ shortcut }}</KeyHint>
    </form>
</template>
