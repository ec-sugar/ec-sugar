<script setup>
import { computed, ref, watch } from 'vue';
import AppButton from '../ui/AppButton.vue';
import AppIcon from '../ui/AppIcon.vue';
import AppReveal from '../ui/AppReveal.vue';
import AppText from '../ui/AppText.vue';
import ConfirmButton from '../ui/ConfirmButton.vue';
import PageTemplatePicker from './PageTemplatePicker.vue';

// 「雛形から作り直す」（ページの編集の画面の左の列）。雛形を選び、今の並びを置き換える前に確認してから apply を親へ。
// 置き換えるのは下書きだけで、公開するまでショップは変わらない（作り直すのはサーバー。お知らせの帯・フッターのように外枠が読むセクションは残る）
const props = defineProps({
    templates: { type: Array, required: true },
    count: { type: Number, required: true },
    disabled: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['apply']);

const open = ref(false);
const chosen = ref(null);
const template = computed(() => props.templates.find((t) => t.name === chosen.value) ?? null);

watch(open, (value) => {
    if (!value) {
        chosen.value = null;
    }
});

// 作り直し終えたら閉じる
watch(() => props.busy, (busy, was) => {
    if (was && !busy) {
        open.value = false;
    }
});
</script>

<template>
    <div v-if="templates.length > 0" class="flex flex-col gap-3" @keydown.esc="open = false">
        <AppButton variant="ghost" :disabled="disabled" :aria-expanded="open" data-test="rebuild-from-template" @click="open = !open">
            <AppIcon name="grid" />雛形から作り直す
        </AppButton>
        <AppReveal :show="open" class="flex flex-col gap-3" data-test="rebuild-menu">
            <AppText size="xs" tone="soft">雛形を選ぶと、今のセクションの並びを雛形の並びに置き換えます。下書きに保存されるだけなので、公開するまでショップは変わりません。</AppText>
            <PageTemplatePicker v-model="chosen" :templates="templates" :disabled="disabled || busy" label="作り直す雛形" />
            <div v-if="template" class="flex flex-wrap items-center gap-2">
                <ConfirmButton
                    :label="`「${template.label}」で作り直す`"
                    confirm-label="置き換える"
                    variant="primary"
                    :disabled="disabled"
                    :loading="busy"
                    test="apply-template"
                    @confirm="emit('apply', template.name)"
                >
                    今の {{ count }} セクションを「{{ template.label }}」の {{ template.rows.length }} セクションに置き換えます（お知らせバナー・フッターのように決まった場所に出るセクションは残ります）。下書きに保存されるだけなので、公開するまでショップは変わりません。
                </ConfirmButton>
            </div>
        </AppReveal>
    </div>
</template>
