<script setup>
import { computed, ref, useId } from 'vue';
import AppIcon from './AppIcon.vue';

// 小さな札の並びに値を足していく入力（色・サイズの値など）。カンマ（, と 、）か Enter で札にし、欄を離れたときも残りの文字を札にする。
// 空の欄で Backspace を押すと最後の札を外す。重なる値・空の値は足さない。
// AppField（label 要素）の中に置かない: label の中のボタンは、名前を押しただけで押されてしまう。名前はこの部品が出す
const values = defineModel({ type: Array, required: true });
const props = defineProps({
    label: { type: String, required: true },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    placeholder: { type: String, default: null },
    maxlength: { type: Number, default: 40 },
    max: { type: Number, default: 30 },
    disabled: { type: Boolean, default: false },
    test: { type: String, default: null },
});

const id = useId();
const text = ref('');
const full = computed(() => values.value.length >= props.max);

// 文字を札にする（カンマで分かれていれば分ける）
function commit() {
    const parts = text.value.split(/[,、]/).map((part) => part.trim()).filter((part) => part !== '');
    const next = [...values.value];

    for (const part of parts) {
        if (next.length < props.max && !next.includes(part)) {
            next.push(part);
        }
    }

    text.value = '';

    if (next.length !== values.value.length) {
        values.value = next;
    }
}

function onKeydown(event) {
    // 日本語の変換中の Enter は確定なので、札にしない
    if (event.isComposing) {
        return;
    }

    if (event.key === 'Enter' || event.key === ',') {
        event.preventDefault();
        commit();
    } else if (event.key === 'Backspace' && text.value === '' && values.value.length > 0) {
        values.value = values.value.slice(0, -1);
    }
}

function onInput() {
    // 「、」は変換の後に入るので、入った時点で札にする
    if (/[,、]/.test(text.value)) {
        commit();
    }
}

function remove(index) {
    values.value = values.value.filter((_, i) => i !== index);
}
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <span :id="`${id}-label`" class="text-[13px] font-bold text-ink-muted">{{ label }}</span>
        <div
            :class="[
                'flex min-h-10 flex-wrap items-center gap-1.5 rounded-lg bg-subtle px-2 py-1.5 ring-1 transition-colors focus-within:bg-surface focus-within:ring-accent',
                error ? 'bg-danger-soft ring-danger' : 'ring-transparent',
                disabled ? 'opacity-60' : '',
            ]"
            :data-test="test"
        >
            <ul class="contents" :aria-labelledby="`${id}-label`">
                <li v-for="(value, index) in values" :key="value" class="inline-flex h-7 items-center gap-1 rounded-full bg-surface pr-1 pl-3 text-[13px] text-ink ring-1 ring-line" data-test="tag">
                    <span>{{ value }}</span>
                    <button
                        v-if="!disabled"
                        type="button"
                        class="inline-flex size-5 items-center justify-center rounded-full text-ink-soft hover:bg-hover hover:text-ink"
                        :aria-label="`「${value}」を削除`"
                        :title="`「${value}」を削除`"
                        @click="remove(index)"
                    >
                        <AppIcon name="x" :size="12" />
                    </button>
                </li>
            </ul>
            <input
                v-if="!disabled && !full"
                v-model="text"
                type="text"
                class="h-7 min-w-24 flex-1 bg-transparent px-1 text-sm text-ink placeholder:text-ink-faint focus:outline-none"
                :maxlength="maxlength * 4"
                :placeholder="values.length === 0 ? placeholder : null"
                :aria-labelledby="`${id}-label`"
                :aria-describedby="error || hint ? `${id}-note` : null"
                :aria-invalid="error ? 'true' : null"
                :data-test="test ? `${test}-input` : null"
                @keydown="onKeydown"
                @input="onInput"
                @blur="commit"
            >
        </div>
        <span v-if="error" :id="`${id}-note`" class="text-xs text-danger" role="alert">{{ error }}</span>
        <span v-else-if="hint" :id="`${id}-note`" class="text-xs text-ink-soft">{{ hint }}</span>
    </div>
</template>
