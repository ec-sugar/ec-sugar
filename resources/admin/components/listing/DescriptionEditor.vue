<script setup>
import { nextTick, ref } from 'vue';
import { BLOCK_LABELS, BLOCK_TYPES, errorAt, moved, newBlock } from '../../description.js';
import AppButton from '../ui/AppButton.vue';
import AppField from '../ui/AppField.vue';
import AppIcon from '../ui/AppIcon.vue';
import AppInput from '../ui/AppInput.vue';
import AppText from '../ui/AppText.vue';
import AppTextarea from '../ui/AppTextarea.vue';
import BlockFrame from '../ui/BlockFrame.vue';
import ChoicePills from '../ui/ChoicePills.vue';
import PhotoPicker from '../sections/PhotoPicker.vue';
import TableBlockEditor from './TableBlockEditor.vue';

// 商品の説明のエディタ（ADR 0032）: 段落・見出し・箇条書き・写真・表・注意書きのブロックを足す・並べ替える・消す。
// 中身は HTML でなく決まった種類のブロック。写真はショップの写真から選ぶ（セクションの写真の欄と同じ選び方）。
// 並べ替えはボタンか、ブロックの中で Alt + ↑ / ↓。動かした後も同じボタンに焦点を残す（キーボードで続けて動かせる）
const blocks = defineModel({ type: Array, required: true });
const props = defineProps({
    limits: { type: Object, required: true },
    // ショップの写真（{ id, alt }）と、その API
    media: { type: Array, required: true },
    mediaBase: { type: String, required: true },
    // サーバーの誤り（description_blocks.3.text など）
    errors: { type: Object, default: () => ({}) },
    // 誤りの道筋の頭（商品の説明は description_blocks、App の画面のブロックの欄は values.<名前>。ADR 0072）
    errorPrefix: { type: String, default: 'description_blocks' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['uploaded']);

const list = ref(null);
const HEADING_LEVELS = [{ value: 2, label: '大' }, { value: 3, label: '小' }];
const LIST_STYLES = [{ value: 'bullet', label: '記号' }, { value: 'number', label: '番号' }];
const NOTE_TONES = [{ value: 'info', label: 'お知らせ' }, { value: 'warning', label: 'ご注意' }];

function imageCount() {
    return blocks.value.filter((b) => b.type === 'image').length;
}

function canAdd(type) {
    return blocks.value.length < props.limits.blocks && (type !== 'image' || imageCount() < props.limits.images);
}

// 焦点を index 番目のブロックの中へ（selector が無ければ最初の入力欄）
async function focusBlock(index, selector = 'input, textarea, button:not([disabled])') {
    await nextTick();
    const item = list.value?.children[index];
    item?.querySelector(selector)?.focus();
}

function add(type) {
    blocks.value = [...blocks.value, newBlock(type)];
    focusBlock(blocks.value.length - 1, 'input, textarea, [data-test="photo-open"]');
}

function move(index, step) {
    blocks.value = moved(blocks.value, index, step);
    const target = index + step;
    // 端に着いて押せなくなったら、もう一方のボタンへ
    const atEdge = (step < 0 && target === 0) || (step > 0 && target === blocks.value.length - 1);
    focusBlock(target, `[data-move="${atEdge ? (step < 0 ? 'down' : 'up') : (step < 0 ? 'up' : 'down')}"]`);
}

function remove(index) {
    blocks.value = blocks.value.filter((_, i) => i !== index);

    if (blocks.value.length > 0) {
        focusBlock(Math.min(index, blocks.value.length - 1), '[data-move="remove"]');
    }
}

// そのブロックへのサーバーの誤りの最初の 1 つ（保存したときに返る）
function errorOf(index) {
    return errorAt(props.errors, `${props.errorPrefix}.${index}`);
}
</script>

<template>
    <div class="flex flex-col gap-4" data-test="description-editor">
        <AppText v-if="blocks.length === 0" size="sm" tone="soft">説明はまだありません。下のボタンで段落や写真を追加してください。</AppText>
        <ol ref="list" class="flex flex-col gap-3" aria-label="説明のブロック">
            <BlockFrame
                v-for="(block, index) in blocks"
                :key="block.key"
                :label="BLOCK_LABELS[block.type]"
                :position="index + 1"
                :total="blocks.length"
                :error="errorOf(index)"
                :disabled="disabled"
                :test="`block-${block.type}`"
                @up="move(index, -1)"
                @down="move(index, 1)"
                @remove="remove(index)"
            >
                <AppTextarea v-if="block.type === 'paragraph'" v-model="block.text" rows="4" :maxlength="limits.text" :aria-label="`${index + 1} 番目の段落`" :disabled="disabled" data-test="block-text" />

                <div v-else-if="block.type === 'heading'" class="flex flex-col gap-3">
                    <ChoicePills v-model="block.level" :options="HEADING_LEVELS" :legend="`${index + 1} 番目の見出しの大きさ`" />
                    <AppInput v-model="block.text" :maxlength="limits.heading" :aria-label="`${index + 1} 番目の見出し`" :disabled="disabled" data-test="block-text" />
                </div>

                <div v-else-if="block.type === 'list'" class="flex flex-col gap-3">
                    <ChoicePills v-model="block.style" :options="LIST_STYLES" :legend="`${index + 1} 番目の箇条書きの種類`" />
                    <AppField label="1 行に 1 つ" :hint="`${limits.list_items} 行まで。空の行は除きます`">
                        <AppTextarea v-model="block.lines" rows="4" :disabled="disabled" data-test="block-lines" />
                    </AppField>
                </div>

                <div v-else-if="block.type === 'image'" class="flex flex-col gap-3">
                    <PhotoPicker v-model="block.media_id" :label="`${index + 1} 番目の写真`" :media="media" :base="mediaBase" :disabled="disabled" test="block-photo" @uploaded="emit('uploaded', $event)" />
                    <AppField label="写真の説明" optional hint="写真の下に小さく表示されます">
                        <AppInput v-model="block.caption" :maxlength="limits.caption" :disabled="disabled" data-test="block-caption" />
                    </AppField>
                </div>

                <TableBlockEditor v-else-if="block.type === 'table'" v-model="blocks[index]" :limits="limits" :position="index + 1" :disabled="disabled" />

                <div v-else-if="block.type === 'note'" class="flex flex-col gap-3">
                    <ChoicePills v-model="block.tone" :options="NOTE_TONES" :legend="`${index + 1} 番目の注意書きの種類`" />
                    <AppTextarea v-model="block.text" rows="3" :maxlength="limits.text" :aria-label="`${index + 1} 番目の注意書き`" :disabled="disabled" data-test="block-text" />
                </div>
            </BlockFrame>
        </ol>

        <div v-if="!disabled" class="flex flex-wrap items-center gap-2" data-test="add-block">
            <AppText size="sm" tone="soft">追加:</AppText>
            <AppButton v-for="item in BLOCK_TYPES" :key="item.type" size="sm" :disabled="!canAdd(item.type)" :data-test="`add-${item.type}`" @click="add(item.type)">
                <AppIcon name="plus" />{{ item.label }}
            </AppButton>
        </div>
        <AppText size="sm" tone="soft">入力した文はそのまま表示されます（HTML のタグは使えません）。並べ替えは ↑ ↓ のボタンか、ブロックの中で Alt + ↑ / ↓。ブロックは {{ limits.blocks }} 個まで。</AppText>
    </div>
</template>
