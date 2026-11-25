<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppButton from '../ui/AppButton.vue';
import AppIcon from '../ui/AppIcon.vue';
import AppReveal from '../ui/AppReveal.vue';
import AppText from '../ui/AppText.vue';
import { PRESS } from '../ui/controlClasses.js';
import SectionWireframe from './SectionWireframe.vue';

// 「＋ セクションを足す」（ADR 0039 区切り 2）。そのページに置ける種類（テーマの宣言）ごとに、見た目のパターンをワイヤーの絵のカードで並べる。
// - 絵は theme.json のパターンの wireframe（核の語彙）。書いていないパターンは汎用の絵と名前
// - 絵に指を置く・キーボードで焦点を当てると、その種類とパターンを右のプレビューで試し見する（try を親へ。親が枠の中へ通知する。DB には何も書かない）。離れたら消す
// - 押すと足す（選んでいるセクションの後ろへ。足すのは親）。指で触ったときは 1 回目で試し見、カードの下の「足す」で確認してから足す
// - 1 つのページに置ける数（limit）に届いた種類と、ページの上限（40）に届いたときは足せない
// - 列の数は画面の幅でなく欄の幅で決める（左の狭い列では 2 列、狭い画面で縦に積んで広くなれば 3 列）
// - App が足したセクション（ADR 0040。店で「使う」にした App の分だけ届く）は、種類の名前の横に App の名前を添える
const props = defineProps({
    schema: { type: Object, required: true },
    page: { type: Object, required: true },
    sections: { type: Array, required: true },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['add', 'try']);

// 指を離してから試し見を消すまでの間（隣のカードへ動かすとき、消して出し直すちらつきを避ける）
const LEAVE_MS = 120;

const open = ref(false);
// 指で 1 回触ったカード（「足す」で確認する）
const armed = ref(null);
let pointer = 'mouse';
let leaveTimer = null;
let trying = null;

const full = computed(() => props.sections.length >= props.page.max_sections);
const choices = computed(() => props.page.types
    .filter((type) => props.schema[type])
    .map((type) => {
        const spec = props.schema[type];
        const used = props.sections.filter((s) => s.type === type).length;
        const patterns = Object.entries(spec.patterns ?? {});

        return {
            type,
            label: spec.label,
            app: spec.app ?? null,
            reached: spec.limit !== undefined && used >= spec.limit,
            cards: patterns.map(([pattern, label]) => ({
                pattern,
                // パターンが 1 つだけの種類は、種類の名前を絵の下に出す
                label: patterns.length > 1 ? label : spec.label,
                full: patterns.length > 1 ? `${spec.label}「${label}」` : spec.label,
                wireframe: spec.wireframes?.[pattern] ?? null,
            })),
        };
    }));

function tryOut(choice, card) {
    clearTimeout(leaveTimer);

    if (trying?.type === choice.type && trying?.pattern === card.pattern) {
        return;
    }

    trying = { type: choice.type, pattern: card.pattern, label: card.full };
    emit('try', trying);
}

function stopTrying(now = false) {
    clearTimeout(leaveTimer);
    const clear = () => {
        if (trying !== null) {
            trying = null;
            emit('try', null);
        }
    };

    if (now) {
        clear();
    } else {
        leaveTimer = setTimeout(clear, LEAVE_MS);
    }
}

function enter(event, choice, card) {
    // 指で触ったときは pointerenter でなく、押したときに試し見する
    if (event.pointerType !== 'touch') {
        tryOut(choice, card);
    }
}

function leave(event) {
    if (event.pointerType !== 'touch' && armed.value === null) {
        stopTrying();
    }
}

function press(choice, card) {
    const key = `${choice.type}:${card.pattern}`;

    // 指で触った 1 回目は試し見だけ（もう 1 度同じカードか「足す」で足す）
    if (pointer === 'touch' && armed.value?.key !== key) {
        armed.value = { key, type: choice.type, pattern: card.pattern, label: card.full };
        tryOut(choice, card);

        return;
    }

    add(choice.type, card.pattern);
}

function add(type, pattern) {
    armed.value = null;
    stopTrying(true);
    emit('add', { type, pattern });
    open.value = false;
}

function blurred() {
    if (armed.value === null) {
        stopTrying();
    }
}

function toggle() {
    open.value = !open.value;
}

// 閉じたら試し見も消す
watch(open, (value) => {
    if (!value) {
        armed.value = null;
        stopTrying(true);
    }
});

watch(() => props.disabled || full.value, (blocked) => {
    if (blocked) {
        open.value = false;
    }
});

onBeforeUnmount(() => stopTrying(true));

const cardClass = (choice, card) => [
    'flex w-full flex-col gap-1.5 rounded-lg bg-subtle p-1.5 text-left ring-1 ring-transparent outline-none',
    'hover:ring-accent focus-visible:ring-2 focus-visible:ring-accent disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:ring-transparent',
    armed.value?.key === `${choice.type}:${card.pattern}` ? 'ring-2 ring-accent' : '',
    PRESS,
];
</script>

<template>
    <div class="flex flex-col gap-3" @keydown.esc="open = false">
        <AppButton :disabled="disabled || full" :aria-expanded="open" data-test="add-section" @click="toggle">
            <AppIcon name="plus" />セクションを追加
        </AppButton>
        <AppText v-if="full" size="xs" tone="soft">1 つのページに置けるのは {{ page.max_sections }} セクションまでです。</AppText>
        <AppReveal :show="open && !full" class="@container flex flex-col gap-4" data-test="add-section-menu">
            <AppText size="xs" tone="soft">画像にマウスを重ねると、右のプレビューで確認できます。押すと、選んでいるセクションの後ろに追加します。</AppText>
            <div v-for="choice in choices" :key="choice.type" role="group" :aria-labelledby="`add-group-${choice.type}`" class="flex flex-col gap-1.5" :data-test="`add-group-${choice.type}`">
                <AppText :id="`add-group-${choice.type}`" size="sm" tone="muted">{{ choice.label }}<template v-if="choice.app">（App「{{ choice.app }}」）</template><template v-if="choice.reached">（上限に達しています）</template></AppText>
                <ul class="grid grid-cols-2 gap-2 @md:grid-cols-3">
                    <li v-for="card in choice.cards" :key="card.pattern" class="min-w-0">
                        <button
                            type="button"
                            :class="cardClass(choice, card)"
                            :disabled="choice.reached"
                            :aria-pressed="armed?.key === `${choice.type}:${card.pattern}` ? 'true' : undefined"
                            :data-test="`add-${choice.type}-${card.pattern}`"
                            @pointerdown="pointer = $event.pointerType"
                            @pointerenter="enter($event, choice, card)"
                            @pointerleave="leave"
                            @focus="tryOut(choice, card)"
                            @blur="blurred"
                            @keydown="pointer = 'keyboard'"
                            :aria-label="`${choice.label}の${card.label}`"
                            @click="press(choice, card)"
                        >
                            <SectionWireframe :name="card.wireframe" />
                            <span class="truncate text-xs font-medium text-ink-muted" aria-hidden="true">{{ card.label }}</span>
                        </button>
                    </li>
                </ul>
                <div v-if="armed && armed.type === choice.type" class="flex flex-wrap items-center gap-2">
                    <AppButton size="sm" variant="primary" data-test="add-armed" @click="add(armed.type, armed.pattern)">「{{ armed.label }}」を追加</AppButton>
                    <AppButton size="sm" variant="ghost" @click="armed = null; stopTrying(true)">やめる</AppButton>
                </div>
            </div>
        </AppReveal>
    </div>
</template>
