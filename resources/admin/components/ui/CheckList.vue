<script setup>
import { computed } from 'vue';
import AppIcon from './AppIcon.vue';

// いくつでも選べる一覧（チェックボックス）。depth で木を表す。選んだ値の配列を v-model で返す
// variant="chips": 親（depth 0）ごとに 1 行、親と子を押すと選ばれる札で横に流す（カテゴリのように数が多く短い名前の木）
const model = defineModel({ type: Array, required: true });
const props = defineProps({
    options: { type: Array, required: true }, // [{ value, label, depth }]
    legend: { type: String, required: true },
    testPrefix: { type: String, default: null },
    variant: { type: String, default: 'list' }, // list | chips
});

const INDENT = ['', 'pl-6', 'pl-12'];

// 親ごとの行に分ける（options は木の順＝親の後に子が続く）
const groups = computed(() => {
    const rows = [];

    for (const option of props.options) {
        if ((option.depth ?? 0) === 0 || rows.length === 0) {
            rows.push({ root: option, children: [] });
        } else {
            rows[rows.length - 1].children.push(option);
        }
    }

    return rows;
});

const isChosen = (value) => model.value.includes(value);
</script>

<template>
    <fieldset v-if="variant === 'chips'" class="flex flex-col gap-2.5">
        <legend class="sr-only">{{ legend }}</legend>
        <div v-for="group in groups" :key="group.root.value" class="flex flex-wrap items-center gap-1.5">
            <template v-for="(option, index) in [group.root, ...group.children]" :key="option.value">
                <AppIcon v-if="index === 1" name="chevron-right" :size="14" class="text-ink-faint" />
                <label
                    :class="[
                        'relative inline-flex h-8 cursor-pointer items-center gap-1 rounded-full px-3 text-[13px] ring-1 transition-colors',
                        'has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-accent',
                        'has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60',
                        index === 0 ? 'font-bold' : '',
                        isChosen(option.value) ? 'bg-accent-soft text-accent ring-accent' : 'bg-surface text-ink-muted ring-line hover:bg-hover hover:text-ink',
                    ]"
                >
                    <!-- 本物のチェックボックスを透明にして札の全面に重ねる（押す・キーボード・読み上げはチェックボックスのまま） -->
                    <input v-model="model" type="checkbox" :value="option.value" class="absolute inset-0 m-0 cursor-pointer appearance-none rounded-full opacity-0" :data-test="testPrefix ? `${testPrefix}-${option.value}` : null">
                    <AppIcon v-if="isChosen(option.value)" name="check" :size="14" />
                    {{ option.label }}
                </label>
            </template>
        </div>
    </fieldset>
    <fieldset v-else class="flex flex-col gap-1">
        <legend class="sr-only">{{ legend }}</legend>
        <label
            v-for="option in options"
            :key="option.value"
            :class="['flex min-h-9 cursor-pointer items-center gap-2.5 rounded-md px-2 text-[13px] text-ink-muted hover:bg-hover hover:text-ink', INDENT[option.depth] ?? 'pl-12']"
        >
            <input v-model="model" type="checkbox" :value="option.value" class="size-4 accent-accent" :data-test="testPrefix ? `${testPrefix}-${option.value}` : null">
            {{ option.label }}
        </label>
    </fieldset>
</template>
