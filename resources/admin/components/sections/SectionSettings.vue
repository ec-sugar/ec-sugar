<script setup>
import { computed } from 'vue';
import AppText from '../ui/AppText.vue';
import ChoicePills from '../ui/ChoicePills.vue';
import InsetSection from '../ui/InsetSection.vue';
import SectionEditor from './SectionEditor.vue';
import SectionField from './SectionField.vue';

// 選んだセクションの設定（ADR 0039 区切り 2。編集の画面の右）。見た目のパターンの選び直し・ショップに出す・欄・繰り返しの組（SectionEditor）。
// ブロックを選んでいるときは、そのブロックの欄だけ。誤りの道筋はページの API の形（sections.{何番目}.values.heading など）
const section = defineModel({ type: Object, required: true });
const props = defineProps({
    spec: { type: Object, required: true },
    index: { type: Number, required: true },
    // 選んでいるブロックの何番目か（セクションそのものなら null）
    block: { type: Number, default: null },
    errors: { type: Object, required: true },
    context: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['uploaded']);

const patterns = computed(() => Object.entries(props.spec.patterns ?? {}).map(([value, label]) => ({ value, label })));
const blockSpec = computed(() => (props.block === null ? null : props.spec.blocks?.[section.value.blocks[props.block]?.type] ?? null));
</script>

<template>
    <div v-if="blockSpec" class="flex flex-col gap-4">
        <InsetSection :title="`${spec.label}の中の${blockSpec.label}`" data-test="block-settings">
            <SectionField
                v-for="(field, key) in blockSpec.fields"
                :key="key"
                v-model="section.blocks[block].values[key]"
                :spec="field"
                :path="`sections.${index}.blocks.${block}.values.${key}`"
                :errors="errors"
                :context="context"
                :disabled="disabled"
                :test="`block-field-${key}`"
                @uploaded="emit('uploaded', $event)"
            />
        </InsetSection>
    </div>
    <div v-else class="flex flex-col gap-4" data-test="section-settings">
        <div v-if="patterns.length > 1" class="flex flex-col gap-2">
            <AppText size="sm" tone="muted">見た目のパターン</AppText>
            <ChoicePills v-if="!disabled" v-model="section.pattern" :options="patterns" :legend="`${spec.label}の見た目のパターン`" test-prefix="pattern" />
            <AppText v-else size="sm">{{ spec.patterns[section.pattern] }}</AppText>
        </div>
        <SectionEditor v-model="section" :name="String(index)" :spec="spec" :errors="errors" :context="context" :disabled="disabled" narrow @uploaded="emit('uploaded', $event)" />
        <AppText size="xs" tone="soft">空欄の項目はショップに表示されません（見出しが空欄のときは、テーマが店の名前などを表示します）。</AppText>
    </div>
</template>
