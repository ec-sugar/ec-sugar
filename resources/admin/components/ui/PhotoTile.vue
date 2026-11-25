<script setup>
// 商品の写真の札。写真（縦長 4:5 の枠に収める）と何枚目か、下に操作（slot）を置く。1 枚目は店頭の一覧に出る写真
defineProps({
    src: { type: String, required: true },
    alt: { type: String, default: '' },
    index: { type: Number, required: true },
});
</script>

<template>
    <li class="flex flex-col gap-3 rounded-xl p-3 ring-1 ring-line" data-test="photo-tile">
        <div class="relative aspect-[4/5] overflow-hidden rounded-lg bg-subtle">
            <img :src="src" :alt="alt" loading="lazy" class="size-full object-cover">
            <span class="absolute top-2 left-2 rounded-full bg-surface px-2 py-0.5 text-xs font-medium text-ink shadow-xs">
                {{ index === 0 ? '1 枚目（一覧に表示）' : `${index + 1} 枚目` }}
            </span>
        </div>
        <slot />
    </li>
</template>
