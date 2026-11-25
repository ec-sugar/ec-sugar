<script setup>
import { download } from '../../api.js';
import { useOperation } from '../../operation.js';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppRecordForm from './AppRecordForm.vue';

// App の画面の CSV の書き出し（配送の CSV など。ADR 0051 の口の続き）。欄は App が宣言した核の形で、描くのは核の部品（AppRecordForm）。
// 送るとサーバーが CSV を返し、ブラウザがファイルとして保存する（画面は移らない）。届け先などが入るので、サーバーは運用の段階だけに許し、書き出したことを記録に残す
const props = defineProps({
    tenant: { type: String, required: true },
    plugin: { type: String, required: true },
    // { label, fields, values }（一覧の答えの export）
    form: { type: Object, required: true },
});
const emit = defineEmits(['exported']);
const exporting = useOperation();

async function submit(values) {
    await exporting.run('export', () => download(`/api/tenants/${encodeURIComponent(props.tenant)}/apps/${encodeURIComponent(props.plugin)}/export`, { values }), {
        // 書き出した印で「まだ書き出していない」の件数が変わるので、親に読み直してもらう
        then: () => emit('exported'),
        done: (name) => `${name} を書き出しました。ブラウザのダウンロードフォルダをご覧ください。`,
    });
}
</script>

<template>
    <AppNotice v-if="exporting.message.value" tone="success" data-test="app-export-done">{{ exporting.message.value }}</AppNotice>
    <AppNotice v-if="exporting.error.value" tone="error">{{ exporting.error.value }}</AppNotice>
    <AppRecordForm :form="form" submit-label="書き出す" :errors="exporting.errors.value" :pending="exporting.pending.value !== null" test="app-export-form" @submit="submit" />
</template>
