<script setup>
// 説明に載せる画面の写しと、その短い説明。写しは自分のオリジンの画像だけ。
// width と height（写しの画素の数）を渡すと、読み込む前から場所を空けておく（節へ移った後に、上の写しが読み込まれて位置がずれない）
defineProps({
    src: { type: String, required: true },
    alt: { type: String, required: true },
    caption: { type: String, required: true },
    width: { type: Number, default: null },
    height: { type: Number, default: null },
});
</script>

<template>
    <figure class="flex flex-col gap-2">
        <img :src="src" :alt="alt" :width="width" :height="height" class="h-auto max-w-full rounded-lg ring-1 ring-line" loading="lazy">
        <figcaption class="text-xs text-ink-soft">{{ caption }}</figcaption>
    </figure>
</template>
