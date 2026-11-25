<script setup>
import { computed } from 'vue';
import AppButton from '../ui/AppButton.vue';
import AppField from '../ui/AppField.vue';
import AppNotice from '../ui/AppNotice.vue';
import AppText from '../ui/AppText.vue';
import ChoicePills from '../ui/ChoicePills.vue';
import ColorField from '../ui/ColorField.vue';
import PaletteChoices from '../ui/PaletteChoices.vue';

// ページの編集の画面の左の「見た目」の札（ADR 0097）: 色の組・主な色・字の組・角の丸み。
// 選べるものはテーマの宣言（style）だけで、主な色はテーマが許すときだけ自由に決められる。変えると親が下書きに保存して、プレビューの CSS だけを読み直す。
// 読みにくいかもしれない組み合わせは、サーバーの通知（warnings）を出す（保存は止めない）。誤りの道筋は style.palette・style.accent など
const values = defineModel({ type: Object, required: true }); // { palette, accent, font, radius }
const props = defineProps({
    declaration: { type: Object, required: true }, // テーマの宣言 { palettes: [{ name, label, colors }], accent, fonts: [{ name, label }], radius: [{ name, label }] }
    warnings: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
});

const palette = computed(() => props.declaration.palettes.find((p) => p.name === values.value.palette) ?? props.declaration.palettes[0]);
const fonts = computed(() => props.declaration.fonts.map((f) => ({ value: f.name, label: f.label })));
const radius = computed(() => props.declaration.radius.map((r) => ({ value: r.name, label: r.label })));
const error = (key) => props.errors[`style.${key}`]?.[0] ?? null;

function set(key, value) {
    values.value = { ...values.value, [key]: value };
}
</script>

<template>
    <div class="flex flex-col gap-5" data-test="style-panel">
        <AppField as="div" label="カラーパレット" :error="error('palette')" hint="テーマが用意したカラーパレットから選びます。">
            <PaletteChoices :model-value="values.palette" :palettes="declaration.palettes" legend="カラーパレット" :disabled="disabled" @update:model-value="set('palette', $event)" />
        </AppField>

        <AppField v-if="declaration.accent" as="div" label="主な色" :error="error('accent')" hint="ボタンやリンクの色です。ボタンの文字の色は、読みやすい方（白か黒）を自動で選びます。">
            <ColorField :model-value="values.accent" :fallback="palette.colors.accent" label="主な色" :disabled="disabled" :invalid="error('accent') !== null" test-prefix="accent" @update:model-value="set('accent', $event)" />
            <div v-if="values.accent !== null && !disabled">
                <AppButton size="sm" variant="ghost" data-test="accent-reset" @click="set('accent', null)">パレットの色に戻す</AppButton>
            </div>
        </AppField>

        <AppNotice v-for="warning in warnings" :key="warning.code" tone="warning" :data-test="`style-warning-${warning.code}`">{{ warning.message }}</AppNotice>

        <AppField v-if="fonts.length > 1" as="div" label="フォント" :error="error('font')">
            <ChoicePills :model-value="values.font" :options="fonts" legend="フォント" test-prefix="font" :disabled="disabled" @update:model-value="set('font', $event)" />
        </AppField>

        <AppField v-if="radius.length > 1" as="div" label="角の丸み" :error="error('radius')">
            <ChoicePills :model-value="values.radius" :options="radius" legend="角の丸み" test-prefix="radius" :disabled="disabled" @update:model-value="set('radius', $event)" />
        </AppField>

        <AppText size="xs" tone="soft">変更すると下書きに保存され、中央のプレビューに反映されます。お客さんに見えるのは「公開する」を押してからです。</AppText>
    </div>
</template>
