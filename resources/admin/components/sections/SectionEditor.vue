<script setup>
import AppButton from '../ui/AppButton.vue';
import AppText from '../ui/AppText.vue';
import InsetSection from '../ui/InsetSection.vue';
import SectionField from './SectionField.vue';

// セクション 1 つの編集（ADR 0028 区切り 2b）: ショップに出す・隠す、欄、繰り返しの組（足す・並べ替える・外す）。
// 形はテーマの宣言（spec）どおりで、値は { hidden, values, items }。親の画面が全部のセクションをまとめて下書きに保存する
const model = defineModel({ type: Object, required: true });
const props = defineProps({
    name: { type: String, required: true },
    spec: { type: Object, required: true },
    errors: { type: Object, required: true },
    context: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
    // 狭い列（ページの編集の画面の右）では、欄を 1 列に並べる
    narrow: { type: Boolean, default: false },
});
const emit = defineEmits(['uploaded']);

const emptyItem = () => Object.fromEntries(Object.entries(props.spec.items.fields).map(([key, field]) => [key, field.type === 'categories' ? [] : null]));

function moveItem(index, offset) {
    const [item] = model.value.items.splice(index, 1);
    model.value.items.splice(index + offset, 0, item);
}

const itemLabel = (index) => `${props.spec.items?.label ?? '項目'} ${index + 1}`;
</script>

<template>
    <InsetSection :title="spec.label" :data-test="`section-${name}`">
        <label class="flex items-center gap-2">
            <input v-model="model.hidden" type="checkbox" :true-value="false" :false-value="true" :disabled="disabled" class="size-4" :data-test="`section-${name}-shown`">
            <AppText as="span" size="sm">ショップに表示する</AppText>
        </label>
        <AppText v-if="model.hidden" size="xs" tone="soft">非表示にしています。内容は残っているので、いつでも表示に戻せます。</AppText>

        <div v-if="Object.keys(spec.fields).length > 0" :class="['grid grid-cols-1 gap-5', narrow ? '' : 'md:grid-cols-2']">
            <SectionField
                v-for="(field, key) in spec.fields"
                :key="key"
                v-model="model.values[key]"
                :class="!narrow && ['image', 'products', 'categories'].includes(field.type) ? 'md:col-span-2' : null"
                :spec="field"
                :path="`sections.${name}.values.${key}`"
                :errors="errors"
                :context="context"
                :disabled="disabled"
                :test="`field-${name}-${key}`"
                @uploaded="emit('uploaded', $event)"
            />
        </div>

        <div v-if="spec.items" class="flex flex-col gap-4">
            <AppText v-if="model.items.length === 0" size="sm" tone="soft">{{ spec.items.label ?? '項目' }}はまだありません（{{ spec.items.max }} 個まで）。</AppText>
            <InsetSection v-for="(item, index) in model.items" :key="index" :title="itemLabel(index)" :data-test="`section-${name}-item`">
                <div :class="['grid grid-cols-1 gap-5', narrow ? '' : 'md:grid-cols-2']">
                    <SectionField
                        v-for="(field, key) in spec.items.fields"
                        :key="key"
                        v-model="item[key]"
                        :class="!narrow && ['image', 'products', 'categories'].includes(field.type) ? 'md:col-span-2' : null"
                        :spec="field"
                        :path="`sections.${name}.items.${index}.${key}`"
                        :errors="errors"
                        :context="context"
                        :disabled="disabled"
                        :test="`field-${name}-${index}-${key}`"
                        @uploaded="emit('uploaded', $event)"
                    />
                </div>
                <div class="flex justify-end gap-1">
                    <AppButton size="sm" variant="ghost" :disabled="disabled || index === 0" :aria-label="`${itemLabel(index)}を上へ`" @click="moveItem(index, -1)">上へ</AppButton>
                    <AppButton size="sm" variant="ghost" :disabled="disabled || index === model.items.length - 1" :aria-label="`${itemLabel(index)}を下へ`" @click="moveItem(index, 1)">下へ</AppButton>
                    <AppButton size="sm" variant="ghost" :disabled="disabled" :aria-label="`${itemLabel(index)}を削除`" @click="model.items.splice(index, 1)">削除</AppButton>
                </div>
            </InsetSection>
            <AppText v-if="errors[`sections.${name}.items`]" size="xs" tone="danger" role="alert">{{ errors[`sections.${name}.items`][0] }}</AppText>
            <div v-if="model.items.length < spec.items.max">
                <AppButton size="sm" :disabled="disabled" :data-test="`section-${name}-add`" @click="model.items.push(emptyItem())">{{ spec.items.label ?? '項目' }}を追加</AppButton>
            </div>
        </div>
    </InsetSection>
</template>
