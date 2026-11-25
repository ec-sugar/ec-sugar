<script setup>
import ConfirmButton from './ui/ConfirmButton.vue';

// 「サンプルをまとめて消す」のボタン。ホームの開設の手順と商品の一覧の帯で同じ確認の文を使う。
// removal は useSampleRemoval（samples.js）の戻り値。オーナーにだけ出すのは呼ぶ側が決める（サーバーも断る）
defineProps({
    removal: { type: Object, required: true },
    label: { type: String, default: 'まとめて削除' },
});
</script>

<template>
    <ConfirmButton
        :label="label"
        confirm-label="削除"
        :disabled="removal.operation.pending.value !== null"
        :loading="removal.operation.pending.value === 'samples'"
        test="remove-samples"
        @confirm="removal.remove()"
    >
        商品のほか、カテゴリ・ショップの写真・トップページのデザイン・特商法の表記のサンプルも削除されます。元に戻せません。
    </ConfirmButton>
</template>
