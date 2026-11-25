<script setup>
// EC-SUGAR のロゴ（ADR 0031）。元の画像と配置ガイドは docs/design/brand/、画面用の版は resources/admin/assets/brand/（余白を詰めて縮めたもの）
// tone: on-brand は看板の濃い藍の上（いつも白の版）、default は面の上（ライトは黒の版、ダークは白の版。管理画面のダークは端末の設定で切り替わる）
// variant: full は角砂糖の印と名前、mark は角砂糖の印だけ
import { computed } from 'vue';
import logo from '../../assets/brand/logo-yoko.png';
import logoWhite from '../../assets/brand/logo-yoko-white.png';
import mark from '../../assets/brand/mark.png';
import markWhite from '../../assets/brand/mark-white.png';

const props = defineProps({
    tone: { type: String, default: 'default', validator: (v) => ['default', 'on-brand'].includes(v) },
    size: { type: String, default: 'md', validator: (v) => ['md', 'lg'].includes(v) },
    variant: { type: String, default: 'full', validator: (v) => ['full', 'mark'].includes(v) },
});

const isMark = computed(() => props.variant === 'mark');
const dark = computed(() => (isMark.value ? markWhite : logoWhite));
const light = computed(() => (isMark.value ? mark : logo));
const sizeClass = computed(() => (isMark.value
    ? (props.size === 'lg' ? 'size-9' : 'size-7')
    : ['w-auto object-contain', props.size === 'lg' ? 'h-12' : 'h-9']));
</script>

<template>
    <picture class="block shrink-0 self-start">
        <source v-if="tone === 'default'" :srcset="dark" media="(prefers-color-scheme: dark)" />
        <img
            :src="tone === 'on-brand' ? dark : light"
            alt="EC-SUGAR"
            :class="['block', sizeClass]"
            :width="isMark ? 128 : 297"
            :height="isMark ? 128 : 96"
        />
    </picture>
</template>
