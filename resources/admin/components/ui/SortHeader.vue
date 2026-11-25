<script setup>
import { computed } from 'vue';
import AppIcon from './AppIcon.vue';

// 並べ替えのできる列の見出し。sort は「-stock」（多い順）「title」（昇順）の形。押すたびに向きを入れ替える
const sort = defineModel('sort', { type: String, required: true });
const props = defineProps({
    field: { type: String, required: true },
    label: { type: String, required: true },
    numeric: { type: Boolean, default: false },
    // 初めて押したときの向き（数は多い順から見たいことが多い）
    firstDescending: { type: Boolean, default: false },
    // 多い順（新しい順）だけ。押すとその列の多い順にし、もう一度押しても向きを変えない（お客さんの一覧のように、サーバーが多い順だけを受ける列）
    descendingOnly: { type: Boolean, default: false },
});

const active = computed(() => sort.value === props.field || sort.value === `-${props.field}`);
const descending = computed(() => sort.value === `-${props.field}`);
const ariaSort = computed(() => (active.value ? (descending.value ? 'descending' : 'ascending') : 'none'));

function toggle() {
    if (props.descendingOnly) {
        sort.value = `-${props.field}`;
    } else if (!active.value) {
        sort.value = props.firstDescending ? `-${props.field}` : props.field;
    } else {
        sort.value = descending.value ? props.field : `-${props.field}`;
    }
}
</script>

<template>
    <th :aria-sort="ariaSort" :class="numeric ? 'num' : ''">
        <button type="button" :class="['inline-flex items-center gap-1 hover:text-ink', active ? 'text-ink' : '']" :data-test="`sort-${field}`" @click="toggle">
            {{ label }}
            <AppIcon :name="active ? (descending ? 'chevron-down' : 'chevron-up') : 'chevron-up-down'" :size="12" />
        </button>
    </th>
</template>
