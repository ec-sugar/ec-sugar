<script setup>
import { PRESS } from '../ui/controlClasses.js';
import SectionWireframe from './SectionWireframe.vue';

// ページの雛形を選ぶ。雛形ごとに、入るセクションの並びをワイヤーの絵の縦の積み重ねで見せる（見本帳と同じ形）。
// 雛形の中身はサーバー（PageTemplates）が、そのテーマのそのページに合わせて読んだもの: テーマのセクション（役目で選んだもの）・役目の代わりの核のセクション・核のセクション。
// 置けずに飛ばすセクションがあれば、名前を添える（1 つも置けない雛形は押せない）。選ぶだけで、作る・作り直すのは親（v-model は雛形の名前。白紙は null）
// - blank: 「白紙」も選べる（ページを作るとき。テーマの最初の並び）
// - 列の数は欄の幅で決める（ページの一覧の広い欄では 3 列、編集の画面の左の狭い列では 1 列）
const props = defineProps({
    templates: { type: Array, required: true },
    modelValue: { type: String, default: null },
    blank: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    label: { type: String, default: 'ページの雛形' },
    // 項目の名前と補足を出すとき（ページを作るフォームの中。AppField は <label> で包むので、ボタンの並びには使わない）
    title: { type: String, default: null },
    hint: { type: String, default: null },
});
const headingId = `page-templates-${Math.random().toString(36).slice(2, 8)}`;
const emit = defineEmits(['update:modelValue']);

function choose(name) {
    if (!props.disabled) {
        emit('update:modelValue', name);
    }
}

// 矢印のキーで隣へ（ラジオの組と同じ動き）
function move(event, index) {
    const names = [...(props.blank ? [null] : []), ...props.templates.map((t) => t.name)];
    const step = event.key === 'ArrowDown' || event.key === 'ArrowRight' ? 1 : -1;
    const next = names[(index + step + names.length) % names.length];
    choose(next);
    event.currentTarget.parentElement?.parentElement?.querySelectorAll('[role="radio"]')[(index + step + names.length) % names.length]?.focus();
}

const cardClass = (chosen) => [
    'flex h-full w-full flex-col gap-2 rounded-lg bg-surface p-3 text-left ring-1 outline-none',
    'hover:ring-accent focus-visible:ring-2 focus-visible:ring-accent disabled:cursor-not-allowed disabled:opacity-50',
    chosen ? 'ring-2 ring-accent' : 'ring-line',
    PRESS,
];
const fromLabel = { fallback: '（代わりのセクション）' };
</script>

<template>
    <div class="@container flex flex-col gap-1.5" data-test="page-templates">
        <span v-if="title" :id="headingId" class="text-[13px] font-bold text-ink-muted">{{ title }}</span>
        <span v-if="hint" class="text-xs text-ink-soft">{{ hint }}</span>
        <ul role="radiogroup" :aria-label="title ? undefined : label" :aria-labelledby="title ? headingId : undefined" class="grid grid-cols-1 gap-3 @xl:grid-cols-2 @4xl:grid-cols-3">
            <li v-if="blank" class="min-w-0">
                <button
                    type="button"
                    role="radio"
                    :aria-checked="modelValue === null ? 'true' : 'false'"
                    :tabindex="modelValue === null ? 0 : -1"
                    :disabled="disabled"
                    :class="cardClass(modelValue === null)"
                    data-test="template-blank"
                    @click="choose(null)"
                    @keydown.down.prevent="move($event, 0)"
                    @keydown.right.prevent="move($event, 0)"
                    @keydown.up.prevent="move($event, 0)"
                    @keydown.left.prevent="move($event, 0)"
                >
                    <span class="text-sm font-semibold text-ink">白紙</span>
                    <span class="text-xs text-ink-soft">テーマの初期の構成（見出しと文など）から始めて、自分でセクションを追加します</span>
                </button>
            </li>
            <li v-for="(template, i) in templates" :key="template.name" class="min-w-0">
                <button
                    type="button"
                    role="radio"
                    :aria-checked="modelValue === template.name ? 'true' : 'false'"
                    :tabindex="modelValue === template.name || (modelValue === null && !blank && i === 0) ? 0 : -1"
                    :disabled="disabled || template.rows.length === 0"
                    :class="cardClass(modelValue === template.name)"
                    :data-test="`template-${template.name}`"
                    @click="choose(template.name)"
                    @keydown.down.prevent="move($event, i + (blank ? 1 : 0))"
                    @keydown.right.prevent="move($event, i + (blank ? 1 : 0))"
                    @keydown.up.prevent="move($event, i + (blank ? 1 : 0))"
                    @keydown.left.prevent="move($event, i + (blank ? 1 : 0))"
                >
                    <span class="text-sm font-semibold text-ink">{{ template.label }}</span>
                    <span class="text-xs text-ink-soft">{{ template.use }}</span>
                    <span class="flex flex-col gap-1" aria-hidden="true">
                        <span v-for="(row, r) in template.rows" :key="r" class="grid grid-cols-[4.5rem_minmax(0,1fr)] items-center gap-2" data-test="template-row">
                            <SectionWireframe :name="row.wireframe" />
                            <span class="truncate text-xs text-ink-muted">{{ row.label }}{{ fromLabel[row.from] ?? '' }}</span>
                        </span>
                    </span>
                    <span class="sr-only">セクションは {{ template.rows.map((row) => row.label).join('・') }}</span>
                    <span v-if="template.skipped.length > 0" class="text-xs text-ink-soft" data-test="template-skipped">このテーマでは使えないため入りません: {{ template.skipped.join('・') }}</span>
                </button>
            </li>
        </ul>
    </div>
</template>
