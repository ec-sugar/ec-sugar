<script setup>
import { computed } from 'vue';
import { firstError } from '../format.js';
import AppField from './ui/AppField.vue';
import AppInput from './ui/AppInput.vue';
import AppTextarea from './ui/AppTextarea.vue';
import SearchSnippet from './ui/SearchSnippet.vue';

// 「検索と共有」の欄（ADR 0061）: 検索の結果に出す題と説明と、その試し見。商品・カテゴリ・ページ・トップで使う。
// 空なら自動（fallbackTitle・fallbackDescription）。題には店の名前が付く（トップは付けない。suffix: false）。
// 上限は守るのはサーバー（題 70・説明 200）。目安（題 30・説明 120）は画面に出すだけ
const title = defineModel('title', { type: String, default: '' });
const description = defineModel('description', { type: String, default: '' });
const props = defineProps({
    fallbackTitle: { type: String, default: '' },
    fallbackDescription: { type: String, default: '' },
    shopName: { type: String, default: '' },
    url: { type: String, default: null },
    suffix: { type: Boolean, default: true },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
    // 欄の名前（誤りの引き当て）。トップの設定は home_title・home_description
    titleField: { type: String, default: 'seo_title' },
    descriptionField: { type: String, default: 'seo_description' },
    test: { type: String, default: 'seo' },
    // 説明が空の時に何から作るか（画面ごと）
    autoNote: { type: String, default: '空欄なら自動で作ります。' },
});

// 説明は 1 行（改行を打っても空白に替える。サーバーは改行を断る）
const descriptionText = computed({
    get: () => description.value ?? '',
    set: (value) => {
        description.value = (value ?? '').replace(/[\r\n]+/g, ' ');
    },
});

const TITLE = { guide: 30, max: 70 };
const DESCRIPTION = { guide: 120, max: 200, auto: 120 };

function oneLine(text) {
    return (text ?? '').replace(/\s+/g, ' ').trim();
}

function count(text, limit) {
    const length = [...oneLine(text)].length;

    return length > limit.guide ? `${length} 文字（目安の ${limit.guide} 文字を超えた分は、検索結果で省略されることがあります）` : `${length} / 目安 ${limit.guide} 文字`;
}

const shownTitle = computed(() => {
    const base = oneLine(title.value) || oneLine(props.fallbackTitle) || props.shopName;

    return props.suffix && props.shopName ? `${base} | ${props.shopName}` : base;
});
const shownDescription = computed(() => {
    const text = oneLine(description.value) || oneLine(props.fallbackDescription);
    const chars = [...text];

    return chars.length > DESCRIPTION.auto && !oneLine(description.value) ? `${chars.slice(0, DESCRIPTION.auto - 1).join('')}…` : text;
});
const titleHint = computed(() => `空欄なら「${oneLine(props.fallbackTitle) || props.shopName}」。${count(title.value || props.fallbackTitle, TITLE)}`);
const descriptionHint = computed(() => (oneLine(description.value)
    ? count(description.value, DESCRIPTION)
    : `${props.autoNote}改行は詰めて 1 行で表示します`));
</script>

<template>
    <div class="flex flex-col gap-6">
        <AppField label="検索結果に出すタイトル" layout="row" optional :hint="titleHint" :error="firstError(errors, titleField)">
            <AppInput v-model="title" :maxlength="TITLE.max" :placeholder="fallbackTitle" :disabled="disabled" :aria-invalid="!!firstError(errors, titleField)" :data-test="`${test}-title`" />
        </AppField>
        <AppField label="検索結果に出す説明" layout="row" optional :hint="descriptionHint" :error="firstError(errors, descriptionField)">
            <AppTextarea v-model="descriptionText" :maxlength="DESCRIPTION.max" rows="3" :disabled="disabled" :aria-invalid="!!firstError(errors, descriptionField)" :data-test="`${test}-description`" />
        </AppField>
        <SearchSnippet :title="shownTitle" :url="url" :description="shownDescription" />
    </div>
</template>
