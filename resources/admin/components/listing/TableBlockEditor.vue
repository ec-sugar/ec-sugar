<script setup>
import { computed } from 'vue';
import AppButton from '../ui/AppButton.vue';
import AppCheckbox from '../ui/AppCheckbox.vue';
import AppField from '../ui/AppField.vue';
import AppInput from '../ui/AppInput.vue';

// 説明の「表」のブロック: 題・1 行目を見出しにするか・ますの文。行と列は上限まで足せる（上限はサーバーの listing-options から）
const block = defineModel({ type: Object, required: true });
const props = defineProps({
    limits: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
    position: { type: Number, required: true },
});

const columns = computed(() => block.value.rows[0]?.length ?? 1);
// 列の数ごとの並べ方（Tailwind のクラスは書いたものだけが作られるので、決まった名前から選ぶ）
const GRID = ['grid-cols-1', 'grid-cols-1', 'grid-cols-2', 'grid-cols-3', 'grid-cols-4', 'grid-cols-5', 'grid-cols-6'];

function addRow() {
    block.value.rows.push(Array(columns.value).fill(''));
}

function addColumn() {
    block.value.rows.forEach((row) => row.push(''));
}

function removeRow() {
    block.value.rows.pop();
}

function removeColumn() {
    block.value.rows.forEach((row) => row.pop());
}

function cellLabel(r, c) {
    const head = block.value.header && r === 0 ? '見出しの' : '';
    return `${props.position} 番目の表の${head}${r + 1} 行目 ${c + 1} 列目`;
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <AppField label="表のタイトル" optional>
                <AppInput v-model="block.caption" :maxlength="limits.caption" :disabled="disabled" data-test="table-caption" />
            </AppField>
            <div class="flex items-end">
                <AppCheckbox v-model="block.header" label="1 行目を見出しにする" :disabled="disabled" test="table-header" />
            </div>
        </div>
        <div class="overflow-x-auto">
            <div :class="['grid min-w-[36rem] gap-2', GRID[columns] ?? 'grid-cols-6']" data-test="table-cells">
                <template v-for="(row, r) in block.rows" :key="r">
                    <AppInput v-for="(cell, c) in row" :key="`${r}-${c}`" v-model="row[c]" :maxlength="limits.cell" :aria-label="cellLabel(r, c)" :disabled="disabled" data-test="table-cell" />
                </template>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <AppButton size="sm" :disabled="disabled || block.rows.length >= limits.table_rows" data-test="table-add-row" @click="addRow">行を追加</AppButton>
            <AppButton size="sm" :disabled="disabled || columns >= limits.table_columns" data-test="table-add-column" @click="addColumn">列を追加</AppButton>
            <AppButton size="sm" variant="ghost" :disabled="disabled || block.rows.length <= 1" @click="removeRow">最後の行を削除</AppButton>
            <AppButton size="sm" variant="ghost" :disabled="disabled || columns <= 1" @click="removeColumn">最後の列を削除</AppButton>
        </div>
    </div>
</template>
