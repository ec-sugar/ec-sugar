<script setup>
import TextLink from './TextLink.vue';

// 強調とリンクの入った 1 つの文（説明のページの本文など）。HTML を受け取らず、決まった形の部品の並びだけを描く（HTML をそのまま差し込まない。CSP と Trusted Types）。
//   text: 文字列か、部品の並び。部品は文字列（そのまま）・{ strong: '…' }（太字）・{ to: 経路, text: '…' }（画面の中のリンク）
// 外の枠を持たないので、AppText などの中に置く
defineProps({
    text: { type: [String, Array], required: true },
});
</script>

<template>
    <template v-if="typeof text === 'string'">{{ text }}</template>
    <template v-for="(part, index) in text" v-else :key="index">
        <template v-if="typeof part === 'string'">{{ part }}</template>
        <strong v-else-if="part.strong !== undefined" class="font-bold">{{ part.strong }}</strong>
        <TextLink v-else-if="part.to" :to="part.to">{{ part.text }}</TextLink>
        <template v-else>{{ part.text ?? '' }}</template>
    </template>
</template>
