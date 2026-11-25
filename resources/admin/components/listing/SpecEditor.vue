<script setup>
import { nextTick, ref } from 'vue';
import { errorAt, moved, newSpec } from '../../description.js';
import AppButton from '../ui/AppButton.vue';
import AppIcon from '../ui/AppIcon.vue';
import AppInput from '../ui/AppInput.vue';
import AppText from '../ui/AppText.vue';
import BlockFrame from '../ui/BlockFrame.vue';

// 仕様の表（素材・サイズ・原産国など、項目と値の組。ADR 0032）: 行を足す・並べ替える・消す。上限はサーバーの listing-options から
const specs = defineModel({ type: Array, required: true });
const props = defineProps({
    limits: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
});

const list = ref(null);

async function focusRow(index, selector) {
    await nextTick();
    list.value?.children[index]?.querySelector(selector)?.focus();
}

function add() {
    specs.value = [...specs.value, newSpec()];
    focusRow(specs.value.length - 1, 'input');
}

function move(index, step) {
    specs.value = moved(specs.value, index, step);
    const target = index + step;
    const atEdge = (step < 0 && target === 0) || (step > 0 && target === specs.value.length - 1);
    focusRow(target, `[data-move="${atEdge ? (step < 0 ? 'down' : 'up') : (step < 0 ? 'up' : 'down')}"]`);
}

function remove(index) {
    specs.value = specs.value.filter((_, i) => i !== index);
}
</script>

<template>
    <div class="flex flex-col gap-4" data-test="spec-editor">
        <AppText v-if="specs.length === 0" size="sm" tone="soft">仕様はまだありません。「行を追加」で、素材・サイズ・原産国などを項目と値の組で入れられます。</AppText>
        <ol ref="list" class="flex flex-col gap-3" aria-label="仕様の表の行">
            <BlockFrame
                v-for="(spec, index) in specs"
                :key="spec.key"
                label="行"
                :position="index + 1"
                :total="specs.length"
                :error="errorAt(props.errors, `specs.${index}`)"
                :disabled="disabled"
                test="spec-row"
                @up="move(index, -1)"
                @down="move(index, 1)"
                @remove="remove(index)"
            >
                <div class="grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                    <AppInput v-model="spec.label" :maxlength="limits.spec_label" placeholder="項目（素材など）" :aria-label="`${index + 1} 行目の項目`" :disabled="disabled" data-test="spec-label" />
                    <AppInput v-model="spec.value" :maxlength="limits.spec_value" placeholder="値（綿 100% など）" :aria-label="`${index + 1} 行目の値`" :disabled="disabled" data-test="spec-value" />
                </div>
            </BlockFrame>
        </ol>
        <div v-if="!disabled" class="flex">
            <AppButton size="sm" :disabled="specs.length >= limits.specs" data-test="add-spec" @click="add"><AppIcon name="plus" />行を追加</AppButton>
        </div>
    </div>
</template>
