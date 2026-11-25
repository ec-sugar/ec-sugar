<script setup>
import CopyButton from './CopyButton.vue';

// 写して貼る機械の文（設定の例・コマンド・一度だけ見せる鍵）。薄い灰の面に等幅で、空白の無い長い文字もどこでも折り返す
// （狭い画面でも横に動かさない）。copy を付けると下に「写す」のボタン（CopyButton）。中身は文字として描くだけ（HTML として差し込まない）
defineProps({
    text: { type: String, required: true },
    label: { type: String, required: true },
    copy: { type: Boolean, default: false },
    copyLabel: { type: String, default: 'コピー' },
    // 鍵のように、1 回押せば全体を選べるもの
    selectAll: { type: Boolean, default: false },
});
</script>

<template>
    <figure :aria-label="label" class="m-0 flex min-w-0 flex-col">
        <pre
            :class="['m-0 rounded-xl bg-subtle p-4 font-mono text-[13px] leading-relaxed whitespace-pre-wrap break-all text-ink', selectAll ? 'select-all' : '']"
            data-test="code-block"
        >{{ text }}</pre>
        <CopyButton v-if="copy" :text="text" :label="copyLabel" />
    </figure>
</template>
