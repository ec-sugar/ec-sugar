<script setup>
import { nextTick, ref } from 'vue';
import AppButton from '../ui/AppButton.vue';
import AppIcon from '../ui/AppIcon.vue';
import AppSelect from '../ui/AppSelect.vue';
import AppText from '../ui/AppText.vue';
import { PRESS_WIDE } from '../ui/controlClasses.js';

// ページのセクションの一覧（ADR 0039 区切り 2。編集の画面の左）。上から並びの順。押すとそのセクションを選ぶ（右に設定、真ん中のプレビューにも印）。
// - 並べ替え: 行をつかんで動かす（HTML のドラッグ）か、↑ ↓ のボタンか、行の中で Alt + ↑ / ↓（BlockFrame と同じ決まり）。動かした後も同じボタンに焦点を残す
// - 隠す・出す: 目のボタン（中身は残る）
// - 選んだセクションの下に、セクションの中のブロックの並び（選ぶ・↑ ↓・外す・足す）
// 決まった並びのテーマ（fixed）では、並べ替えとブロックの操作は出さない（値と隠す・出すだけ）。消す・足すは親の画面が持つ
const sections = defineModel('sections', { type: Array, required: true });
const selection = defineModel('selection', { type: Object, required: true }); // { key, block }
const props = defineProps({
    schema: { type: Object, required: true },
    fixed: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    // 保存したときのサーバーの誤り（sections.2.values.heading など）。誤りのある行に印を付ける
    errors: { type: Object, default: () => ({}) },
});

const list = ref(null);
const dragFrom = ref(null);
const dropAt = ref(null);

const labelOf = (section) => props.schema[section.type]?.label ?? section.type;
const patternOf = (section) => {
    const patterns = props.schema[section.type]?.patterns ?? {};
    return Object.keys(patterns).length > 1 ? patterns[section.pattern] : null;
};
const blockLabel = (section, block) => props.schema[section.type]?.blocks?.[block.type]?.label ?? block.type;
const hasError = (index) => Object.keys(props.errors).some((k) => k === `sections.${index}` || k.startsWith(`sections.${index}.`));
const canMove = () => !props.fixed && !props.disabled;
// 行の見た目: 誤り（赤い輪郭）・落とす先（太い藍の輪郭）・選んでいる（藍の輪郭と面）・ふだん（指を置くと面）
function rowClass(section, index) {
    if (hasError(index)) {
        return 'ring-1 ring-danger';
    }

    if (dropAt.value === index && dragFrom.value !== index) {
        return 'ring-2 ring-accent';
    }

    return selection.value.key === section.key ? 'bg-selected ring-1 ring-accent' : 'hover:bg-hover';
}

function choose(key, block = null) {
    selection.value = { key, block };
}

async function focusRow(index, selector) {
    await nextTick();
    list.value?.children[index]?.querySelector(selector)?.focus();
}

function move(from, to) {
    if (from === to || to < 0 || to >= sections.value.length) {
        return;
    }

    const next = [...sections.value];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    sections.value = next;
}

function step(index, offset) {
    move(index, index + offset);
    const target = index + offset;
    const atEdge = (offset < 0 && target === 0) || (offset > 0 && target === sections.value.length - 1);
    focusRow(target, `[data-move="${atEdge ? (offset < 0 ? 'down' : 'up') : (offset < 0 ? 'up' : 'down')}"]`);
}

function onKey(event, index) {
    if (!canMove() || !event.altKey) {
        return;
    }

    if (event.key === 'ArrowUp' && index > 0) {
        event.preventDefault();
        step(index, -1);
    } else if (event.key === 'ArrowDown' && index < sections.value.length - 1) {
        event.preventDefault();
        step(index, 1);
    }
}

// HTML のドラッグ（ライブラリは使わない）。落とした行の位置へ動かす
function dragStart(event, index) {
    dragFrom.value = index;
    event.dataTransfer.effectAllowed = 'move';
    // Firefox はデータが無いとドラッグを始めない
    event.dataTransfer.setData('text/plain', sections.value[index].id ?? '');
}

function dragOver(event, index) {
    if (dragFrom.value === null) {
        return;
    }

    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    dropAt.value = index;
}

function drop(event, index) {
    if (dragFrom.value === null) {
        return;
    }

    event.preventDefault();
    move(dragFrom.value, index);
    dragEnd();
}

function dragEnd() {
    dragFrom.value = null;
    dropAt.value = null;
}

function toggleHidden(section) {
    section.hidden = !section.hidden;
}

// ブロック
const emptyValues = (fields) => Object.fromEntries(Object.entries(fields ?? {}).map(([key, field]) => [key, field.type === 'categories' ? [] : null]));
let blockSerial = 0;

function addBlock(section, type) {
    if (!type) {
        return;
    }

    // テーマの見本（example_blocks の中の同じ種類の最初のもの）があれば、その値を入れる
    const spec = props.schema[section.type];
    const example = (spec.example_blocks ?? []).find((b) => b.type === type);
    const values = { ...emptyValues(spec.blocks[type].fields), ...(example ? JSON.parse(JSON.stringify(example.values)) : {}) };
    section.blocks.push({ key: `new-block-${++blockSerial}`, type, values });
    choose(section.key, section.blocks.length - 1);
}

function moveBlock(section, index, offset) {
    const [block] = section.blocks.splice(index, 1);
    section.blocks.splice(index + offset, 0, block);
    choose(section.key, index + offset);
}

function removeBlock(section, index) {
    section.blocks.splice(index, 1);
    choose(section.key, null);
}

// 「＋ ブロックを足す」の選択は、足したらすぐ空に戻す
const blockChoice = ref('');

async function pickBlock(section, type) {
    blockChoice.value = type;
    addBlock(section, type);
    await nextTick();
    blockChoice.value = '';
}

const canAddBlock = (section) => Object.keys(props.schema[section.type]?.blocks ?? {}).length > 0 && section.blocks.length < (props.schema[section.type]?.max_blocks ?? 0);
</script>

<template>
    <ol ref="list" class="flex flex-col gap-1" aria-label="セクションの並び" data-test="section-list">
        <li
            v-for="(section, index) in sections"
            :key="section.key"
            :draggable="canMove()"
            :class="['flex flex-col rounded-lg', rowClass(section, index)]"
            :aria-label="`${index + 1} 番目: ${labelOf(section)}`"
            :data-test="`section-row`"
            :data-section-type="section.type"
            @keydown="onKey($event, index)"
            @dragstart="dragStart($event, index)"
            @dragover="dragOver($event, index)"
            @drop="drop($event, index)"
            @dragend="dragEnd"
        >
            <div class="flex items-center gap-1 py-1 pr-1 pl-2">
                <AppIcon v-if="canMove()" name="grip" class="cursor-grab text-ink-faint" />
                <button
                    type="button"
                    :class="['flex min-w-0 flex-1 flex-col items-start rounded-md px-1.5 py-1 text-left', PRESS_WIDE]"
                    :aria-current="selection.key === section.key && selection.block === null ? 'true' : null"
                    :title="labelOf(section)"
                    data-test="section-select"
                    @click="choose(section.key)"
                >
                    <!-- 縦に並べた中の文字は中身の幅に伸びるので、max-w-full で行の幅に収める。題は 2 行まで折り返し、それより長ければ省く（…）。
                         長い題（角砂糖の「ヒーローとショップの説明」）が ↑ ↓ のボタンに重ならない -->
                    <span :class="['line-clamp-2 max-w-full text-sm break-words', section.hidden ? 'text-ink-faint line-through' : 'font-bold text-ink']" data-test="section-label">{{ labelOf(section) }}</span>
                    <span v-if="patternOf(section) || section.hidden" class="max-w-full truncate text-xs text-ink-soft">{{ [patternOf(section), section.hidden ? '非表示' : null].filter(Boolean).join('・') }}</span>
                </button>
                <template v-if="canMove()">
                    <AppButton size="icon" variant="ghost" :disabled="index === 0" :aria-label="`${index + 1} 番目の${labelOf(section)}を上へ`" title="上へ（Alt + ↑）" data-move="up" @click="step(index, -1)"><AppIcon name="chevron-up" /></AppButton>
                    <AppButton size="icon" variant="ghost" :disabled="index === sections.length - 1" :aria-label="`${index + 1} 番目の${labelOf(section)}を下へ`" title="下へ（Alt + ↓）" data-move="down" @click="step(index, 1)"><AppIcon name="chevron-down" /></AppButton>
                </template>
                <AppButton
                    size="icon"
                    variant="ghost"
                    :disabled="disabled"
                    :aria-label="section.hidden ? `${labelOf(section)}を表示する` : `${labelOf(section)}を非表示にする`"
                    :aria-pressed="section.hidden"
                    :title="section.hidden ? '表示する' : '非表示にする'"
                    data-test="section-hide"
                    @click="toggleHidden(section)"
                >
                    <AppIcon :name="section.hidden ? 'eye-off' : 'eye'" />
                </AppButton>
            </div>

            <div v-if="selection.key === section.key && Object.keys(schema[section.type]?.blocks ?? {}).length > 0" class="flex flex-col gap-1 pr-1 pb-2 pl-7">
                <ol v-if="section.blocks.length > 0" class="flex flex-col gap-0.5" :aria-label="`${labelOf(section)}の中のブロック`">
                    <li v-for="(block, b) in section.blocks" :key="block.key" :class="['flex items-center gap-1 rounded-md', selection.block === b ? 'bg-surface ring-1 ring-accent' : '']" data-test="block-row">
                        <button
                            type="button"
                            :class="['min-w-0 flex-1 truncate rounded-md px-1.5 py-1 text-left text-[13px] text-ink-muted', PRESS_WIDE]"
                            :aria-current="selection.block === b ? 'true' : null"
                            @click="choose(section.key, b)"
                        >
                            {{ blockLabel(section, block) }}
                        </button>
                        <template v-if="!fixed && !disabled">
                            <AppButton size="sm" variant="ghost" :disabled="b === 0" :aria-label="`${b + 1} 番目のブロックを上へ`" @click="moveBlock(section, b, -1)"><AppIcon name="chevron-up" /></AppButton>
                            <AppButton size="sm" variant="ghost" :disabled="b === section.blocks.length - 1" :aria-label="`${b + 1} 番目のブロックを下へ`" @click="moveBlock(section, b, 1)"><AppIcon name="chevron-down" /></AppButton>
                            <AppButton size="sm" variant="ghost" :aria-label="`${b + 1} 番目のブロック（${blockLabel(section, block)}）を削除`" data-test="block-remove" @click="removeBlock(section, b)"><AppIcon name="x" /></AppButton>
                        </template>
                    </li>
                </ol>
                <AppText v-else size="xs" tone="soft">ブロックはまだありません。</AppText>
                <AppSelect
                    v-if="!fixed && !disabled && canAddBlock(section)"
                    :model-value="blockChoice"
                    :aria-label="`${labelOf(section)}にブロックを追加`"
                    data-test="block-add"
                    @update:model-value="pickBlock(section, $event)"
                >
                    <option value="">＋ ブロックを追加</option>
                    <option v-for="(spec, type) in schema[section.type].blocks" :key="type" :value="type">{{ spec.label }}</option>
                </AppSelect>
            </div>
        </li>
    </ol>
    <AppText v-if="!fixed && !disabled" size="xs" tone="soft">並べ替えは、行をドラッグするか、↑ ↓ のボタンか、行を選んで Alt + ↑ / ↓ で。</AppText>
</template>
