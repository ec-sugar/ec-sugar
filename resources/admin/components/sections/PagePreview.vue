<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// ページの本物のプレビュー（ADR 0039 区切り 2）。ショップのホストの下書きのプレビュー（署名つきのリンク。サーバーが作る）を枠（iframe）に開く。
// - 枠の中でセクションを押すと、店頭の核の部品（resources/storefront/editor.js）がセクションの ID を通知してくる。受けるのは、そのリンクのオリジンから・
//   この枠の窓から来た通知だけ（ほかの窓・ほかのオリジンからの通知は見ない）
// - 選んだセクションを枠の中へ通知する（送り先はそのリンクのオリジンだけ。* にしない）。読み込み直した後も再通知する
// - 枠は sandbox で、スクリプトと自分のオリジンだけを許す（フォームの送信・ほかの窓を開く・この画面を移らせるは許さない）
// - パソコンは列の幅いっぱい、スマホは 390px の幅で同じ描き方
// - セクションの試し見: 「＋ セクションを足す」で指した種類とパターン（trial）を枠の中へ通知し、枠の中の部品が見本の値のセクションを仮に差し込む。
//   差し込む位置は前のセクションの ID（近い順）・次のセクションの ID（近い順）で伝える。消すときは null。読み込み直した後も再通知する
// - 見た目（ADR 0097）: 見た目を下書きに保存したら（look が変わったら）、枠の中へ通知し、枠の中の部品が下書きの CSS だけを読み直して字と角の名前を付け直す（ページは読み直さない）
const props = defineProps({
    // 下書きのプレビューの URL（サーバーが作ったものだけ）。null の間は枠を出さない
    url: { type: String, default: null },
    // 読み込み直す回数（保存のたびに増える）
    reload: { type: Number, default: 0 },
    device: { type: String, default: 'desktop', validator: (v) => ['desktop', 'mobile'].includes(v) },
    // 選んでいるセクションの ID（まだ保存していないセクションは null）
    selected: { type: String, default: null },
    title: { type: String, required: true },
    // 試し見するセクション（{ type, pattern, label, after: [ID…], before: [ID…] }）。null なら試し見しない
    trial: { type: Object, default: null },
    // 保存した見た目（{ revision, font, radius }）。変わるたびに枠の中へ通知する。null なら通知しない
    look: { type: Object, default: null },
});
const emit = defineEmits(['select']);

const frame = ref(null);
const ID_SHAPE = /^[a-z0-9_-]{1,40}$/;

const origin = computed(() => {
    try {
        return props.url ? new URL(props.url).origin : null;
    } catch {
        return null;
    }
});

// 読み込み直すときは、意味の無い値（r）を足して URL を変える（署名は店・テーマ・ページ・期限だけを見るので、そのまま通る）
const src = computed(() => (props.url && origin.value ? `${props.url}&r=${props.reload}` : null));

function post(message) {
    const target = frame.value?.contentWindow;

    if (target && origin.value) {
        target.postMessage({ source: 'sugar-editor', ...message }, origin.value);
    }
}

function tell() {
    post({ type: 'select', section: props.selected });
}

function tellTrial() {
    const trial = props.trial;
    post(trial
        ? { type: 'try', section: { type: trial.type, pattern: trial.pattern }, label: trial.label, after: [...trial.after], before: [...trial.before] }
        : { type: 'try', section: null });
}

function tellLook() {
    const look = props.look;

    if (look) {
        post({ type: 'style', revision: look.revision, font: look.font ?? '', radius: look.radius ?? '' });
    }
}

function loaded() {
    tell();

    if (props.trial) {
        tellTrial();
    }
}

function receive(event) {
    if (!origin.value || event.origin !== origin.value || event.source !== frame.value?.contentWindow) {
        return;
    }

    const data = event.data;

    if (data?.source === 'sugar-preview' && data.type === 'select' && typeof data.section === 'string' && ID_SHAPE.test(data.section)) {
        emit('select', data.section);
    }
}

onMounted(() => window.addEventListener('message', receive));
onBeforeUnmount(() => window.removeEventListener('message', receive));
watch(() => props.selected, tell);
watch(() => props.trial, tellTrial);
watch(() => props.look, tellLook);
</script>

<template>
    <div class="flex justify-center rounded-card bg-subtle p-3">
        <iframe
            v-if="src"
            ref="frame"
            :src="src"
            :title="title"
            sandbox="allow-scripts allow-same-origin"
            referrerpolicy="no-referrer"
            :class="['h-[calc(100vh-9rem)] min-h-[32rem] rounded-lg bg-surface ring-1 ring-line', device === 'mobile' ? 'w-[390px] max-w-full' : 'w-full']"
            data-test="page-preview"
            @load="loaded"
        />
    </div>
</template>
