<script setup>
import { RouterLink } from 'vue-router';
import AppIcon from './AppIcon.vue';

// App の札（店の App の画面で、目的ごとのまとまりに並べる。絵・名前・一言・状態を 1 枚の札に）。
// 絵（目的のアイコン）・名前・一言の説明・#status（使う／使わない・設定がそろっているか）・#actions（使う・使わないのボタン）。
// 札のどこを押しても to（App ごとの設定の画面）へ移る: 名前のリンクの押せる所を札の全体に広げ（after の幕）、#actions だけはその上に置く
// （ボタンの中にリンクを入れ子にしない。読み上げでは名前がリンク、ボタンはボタン）
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
    to: { type: [String, Object], required: true },
    icon: { type: String, default: 'plug' },
});
</script>

<template>
    <!-- 札そのものは縮めない（中の「使う」のボタンを押したときに札ごと縮まないように。ボタンは自分で縮む） -->
    <article class="relative flex min-w-0 flex-col gap-3 rounded-xl bg-surface p-4 ring-1 ring-line transition-[background-color,box-shadow] duration-150 ease-out hover:bg-subtle hover:ring-line-strong focus-within:ring-2 focus-within:ring-accent">
        <div class="flex min-w-0 items-start gap-3">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent" aria-hidden="true">
                <AppIcon :name="icon" :size="20" />
            </span>
            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <h3 class="text-sm font-bold break-words text-ink">
                    <RouterLink :to="to" class="text-ink no-underline after:absolute after:inset-0 after:rounded-xl after:content-[''] focus-visible:outline-none" data-test="plugin-card-link">{{ title }}</RouterLink>
                </h3>
                <p v-if="description" class="text-xs leading-relaxed break-words text-ink-soft" data-test="plugin-card-description">{{ description }}</p>
            </div>
        </div>
        <div v-if="$slots.status" class="flex flex-wrap items-center gap-1.5"><slot name="status" /></div>
        <div v-if="$slots.actions" class="relative z-10 mt-auto flex flex-wrap items-center justify-end gap-2"><slot name="actions" /></div>
    </article>
</template>
