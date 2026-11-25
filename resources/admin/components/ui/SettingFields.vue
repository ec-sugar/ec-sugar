<script setup>
import { firstError } from '../../format.js';
import DescriptionEditor from '../listing/DescriptionEditor.vue';
import PhotoPicker from '../sections/PhotoPicker.vue';
import AppField from './AppField.vue';
import AppInput from './AppInput.vue';
import AppSelect from './AppSelect.vue';
import AppTextarea from './AppTextarea.vue';
import ChoicePills from './ChoicePills.vue';

// 核の型の欄（文・切り替え・選ぶ）を宣言から描く（ADR 0034 の App の設定と、ADR 0050 の商品の画面の App の欄）。
// App は欄の形（SettingSpec）だけを宣言し、画面のコードは持ち込まない。値は「名前 => 入力中の値」で、切り替えは 'on' / 'off'、ほかは文字（空は入れていない）。
// 誤りは errors の `${errorPrefix}.<名前>` を欄の下に出す。秘密の項目は中身を持たず、secrets に入っているかだけを受け取る。
// disabled は段階の足りない人に見せるとき（値は見えるが変えられない）。
// App の画面の足す・直すの欄も同じ部品で描く: 何行かの文（textarea）があり、選択肢は並びを保つ [値, 名前] の並びで来る。
// 写真（media）はショップの写真から 1 枚を選ぶ（値は写真の ID。ロゴなど）。写真の一覧と API は親が media・mediaBase で渡す。
// App の画面の欄には、ブロック（blocks。商品の説明と同じエディタ。値は画面の形の並び）と日時（datetime。手元の時刻の datetime-local の文字）もある（ADR 0072）。
// 値の写し替え（サーバーの形との行き来）は親（AppRecordForm）が受け持つ
const model = defineModel({ type: Object, required: true });
const props = defineProps({
    // [{ key, type, label, help, required, secret, max, options, default }]
    fields: { type: Array, required: true },
    errors: { type: Object, default: () => ({}) },
    errorPrefix: { type: String, default: 'values' },
    secrets: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
    // 切り替えの 2 つの言葉（App の設定は「使う・使わない」、商品の欄は「はい・いいえ」）
    toggleLabels: { type: Array, default: () => ['使う', '使わない'] },
    testPrefix: { type: String, default: 'setting' },
    // ショップの写真（{ id, alt }）とその API（写真の欄があるときだけ）
    media: { type: Array, default: () => [] },
    mediaBase: { type: String, default: null },
});
const emit = defineEmits(['uploaded']);

function set(key, value) {
    model.value = { ...model.value, [key]: value };
}

// 選択肢を [値, 名前] の並びにそろえる（設定の欄は「値 => 名前」、App の画面の欄は並び）
function optionsOf(spec) {
    return Array.isArray(spec.options) ? spec.options : Object.entries(spec.options ?? {});
}

function hint(spec) {
    if (spec.secret && props.secrets[spec.key]) {
        return '保存済みです（変更するときだけ入力してください）。';
    }

    return spec.help ?? null;
}
</script>

<template>
    <AppField
        v-for="spec in fields"
        :key="spec.key"
        :as="spec.type === 'toggle' || spec.type === 'media' || spec.type === 'blocks' ? 'div' : 'label'"
        :label="spec.label"
        layout="row"
        :optional="!spec.required && ['text', 'textarea', 'datetime', 'blocks'].includes(spec.type)"
        :hint="hint(spec)"
        :error="firstError(errors, `${errorPrefix}.${spec.key}`)"
    >
        <ChoicePills
            v-if="spec.type === 'toggle'"
            :model-value="model[spec.key]"
            :legend="spec.label"
            :options="[{ value: 'on', label: toggleLabels[0] }, { value: 'off', label: toggleLabels[1] }]"
            :disabled="disabled"
            :test-prefix="`${testPrefix}-${spec.key}`"
            @update:model-value="(value) => set(spec.key, value)"
        />
        <PhotoPicker
            v-else-if="spec.type === 'media' && mediaBase"
            :model-value="model[spec.key] || null"
            :label="spec.label"
            :media="media"
            :base="mediaBase"
            :disabled="disabled"
            :test="`${testPrefix}-${spec.key}`"
            @update:model-value="(value) => set(spec.key, value ?? '')"
            @uploaded="(item) => emit('uploaded', item)"
        />
        <DescriptionEditor
            v-else-if="spec.type === 'blocks' && mediaBase"
            :model-value="model[spec.key]"
            :limits="spec.limits"
            :media="media"
            :media-base="mediaBase"
            :errors="errors"
            :error-prefix="`${errorPrefix}.${spec.key}`"
            :disabled="disabled"
            :data-test="`${testPrefix}-${spec.key}`"
            @update:model-value="(value) => set(spec.key, value)"
            @uploaded="(item) => emit('uploaded', item)"
        />
        <AppInput
            v-else-if="spec.type === 'datetime'"
            :model-value="model[spec.key]"
            type="datetime-local"
            class="max-w-64"
            :disabled="disabled"
            :data-test="`${testPrefix}-${spec.key}`"
            @update:model-value="(value) => set(spec.key, value)"
        />
        <AppSelect v-else-if="spec.type === 'choice'" :model-value="model[spec.key]" :disabled="disabled" :data-test="`${testPrefix}-${spec.key}`" @update:model-value="(value) => set(spec.key, value)">
            <option v-if="!spec.default" value="">選ばない</option>
            <option v-for="[value, label] in optionsOf(spec)" :key="value" :value="value">{{ label }}</option>
        </AppSelect>
        <AppTextarea
            v-else-if="spec.type === 'textarea'"
            :model-value="model[spec.key]"
            :maxlength="spec.max"
            :disabled="disabled"
            :data-test="`${testPrefix}-${spec.key}`"
            @update:model-value="(value) => set(spec.key, value)"
        />
        <AppInput
            v-else
            :model-value="model[spec.key]"
            :type="spec.secret ? 'password' : 'text'"
            :maxlength="spec.max"
            :disabled="disabled"
            autocomplete="off"
            :data-test="`${testPrefix}-${spec.key}`"
            @update:model-value="(value) => set(spec.key, value)"
        />
    </AppField>
</template>
