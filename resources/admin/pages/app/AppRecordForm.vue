<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../../api.js';
import { fromLocalInput, toLocalInput } from '../../coupons.js';
import { fromServer, toServer } from '../../description.js';
import { firstError } from '../../format.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import FormActions from '../../components/ui/FormActions.vue';
import SettingFields from '../../components/ui/SettingFields.vue';

// App の画面の足す・直すの欄（よくある質問など。ADR 0051 の口の続き）。
// 欄は App が宣言した核の形（1 行の文・何行かの文・切り替え・選ぶ・写真・ブロック・日時）で、描くのは核の部品（SettingFields）。App の画面のコードは読み込まない。
// 送るのは親（submit に値を渡す）。誤り（422）は親から受け取り、欄の下（values.<名前>）と全体（values）に出す。
// 写真とブロックの欄（ADR 0072）があるときだけ、ショップの写真の一覧を読む（ブロックは商品の説明と同じエディタで、写真もショップの写真から選ぶ）
const props = defineProps({
    // { label, fields, values }（サーバーの form）
    form: { type: Object, required: true },
    submitLabel: { type: String, required: true },
    // 店の ID（写真の一覧と、写真を上げる API に使う）
    tenant: { type: String, required: true },
    errors: { type: Object, default: () => ({}) },
    pending: { type: Boolean, default: false },
    test: { type: String, default: 'app-form' },
});
const emit = defineEmits(['submit']);

const mediaBase = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/shop-media`);
const media = ref([]);
const needsMedia = computed(() => props.form.fields.some((spec) => spec.type === 'media' || spec.type === 'blocks'));

// 入力中の値（切り替えは 'on' / 'off'、ブロックは画面の形の並び、日時は datetime-local の文字、ほかは文字。空は ''）
function draftOf(form) {
    return Object.fromEntries(form.fields.map((spec) => {
        const value = form.values[spec.key];

        switch (spec.type) {
            case 'toggle': return [spec.key, value ? 'on' : 'off'];
            case 'blocks': return [spec.key, fromServer(value ?? [])];
            case 'datetime': return [spec.key, toLocalInput(value)];
            default: return [spec.key, value ?? ''];
        }
    }));
}

const draft = ref(draftOf(props.form));

// サーバーの値が新しくなったら（保存の後・別の記録へ移った時）入力中の値もそろえる
watch(() => props.form, (form) => { draft.value = draftOf(form); });

// 写真の一覧は、写真かブロックの欄があるときに 1 回だけ読む（読めなくても欄は使える。写真を選ぶ所だけ空）
watch(needsMedia, async (needed) => {
    if (!needed || media.value.length > 0) {
        return;
    }

    try {
        media.value = (await api('GET', mediaBase.value)).items;
    } catch {
        media.value = [];
    }
}, { immediate: true });

function submit() {
    emit('submit', Object.fromEntries(props.form.fields.map((spec) => {
        const value = draft.value[spec.key];

        switch (spec.type) {
            case 'toggle': return [spec.key, value === 'on'];
            case 'blocks': return [spec.key, toServer(value)];
            case 'datetime': return [spec.key, fromLocalInput(value)];
            default: return [spec.key, value === '' ? null : value];
        }
    })));
}

// 足した後に空へ戻す（親から呼ぶ）
function reset() {
    draft.value = draftOf(props.form);
}

defineExpose({ reset });
</script>

<template>
    <form novalidate :data-test="test" @submit.prevent="submit">
        <SettingFields
            v-model="draft"
            :fields="form.fields"
            :errors="errors"
            :toggle-labels="['はい', 'いいえ']"
            :test-prefix="test"
            :media="media"
            :media-base="needsMedia ? mediaBase : null"
            @uploaded="(item) => { media = [item, ...media]; }"
        />
        <AppNotice v-if="firstError(errors, 'values')" tone="error">{{ firstError(errors, 'values') }}</AppNotice>
        <FormActions>
            <AppButton type="submit" variant="primary" :loading="pending" :data-test="`${test}-submit`">{{ submitLabel }}</AppButton>
        </FormActions>
    </form>
</template>
