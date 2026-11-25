<script setup>
import { ref } from 'vue';
import { api } from '../../api.js';
import { firstError } from '../../format.js';
import { useOperation } from '../../operation.js';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppText from '../../components/ui/AppText.vue';
import FileDrop from '../../components/ui/FileDrop.vue';
import AppRecordForm from './AppRecordForm.vue';

// App の画面のファイルの取り込み（配送の CSV の送り状番号など。ADR 0051 の口の続き）。ファイル（FileDrop）と、App が宣言した欄（核の部品 AppRecordForm）。
// 取り込むとサーバーは確認の記録を作るだけ（状態は変えない）なので、作った 1 件の画面へ進み、そこで中身を見てから操作を押す（親が created で移る）
const props = defineProps({
    tenant: { type: String, required: true },
    plugin: { type: String, required: true },
    // { label, fields, values }（一覧の答えの import）
    form: { type: Object, required: true },
});
const emit = defineEmits(['created']);
const file = ref(null);
const missing = ref(false);
const importing = useOperation();
// サーバーの上限（app/Core/Plugin/Services/AdminFiles.php の IMPORT_MAX_BYTES）。送る前の目安で、守るのはサーバー
const MAX_BYTES = 1024 * 1024;

function choose(files) {
    file.value = files[0] ?? null;
    missing.value = false;
}

async function submit(values) {
    if (file.value === null) {
        missing.value = true;

        return;
    }

    const body = new FormData();
    body.append('file', file.value);

    for (const [key, value] of Object.entries(values)) {
        if (typeof value === 'string') {
            body.append(`values[${key}]`, value);
        }
    }

    await importing.run('import', () => api('POST', `/api/tenants/${encodeURIComponent(props.tenant)}/apps/${encodeURIComponent(props.plugin)}/imports`, body), {
        then: (result) => emit('created', result.id),
    });
}
</script>

<template>
    <AppNotice v-if="importing.error.value" tone="error">{{ importing.error.value }}</AppNotice>
    <FileDrop accept=".csv,.txt,text/csv,text/plain" label="CSV のファイルを選ぶ（ここにドラッグ＆ドロップしても選べます）" hint="1 MB まで。文字コードは Shift_JIS か UTF-8。" :disabled="importing.pending.value !== null" data-test="app-import-file" @select="choose" />
    <AppText v-if="file" tone="muted" data-test="app-import-file-name">{{ file.name }}<template v-if="file.size > MAX_BYTES">（大きすぎます。1 MB までにしてください）</template></AppText>
    <AppNotice v-if="missing" tone="error">ファイルを選んでください。</AppNotice>
    <AppNotice v-if="firstError(importing.errors.value, 'file')" tone="error" data-test="app-import-file-error">{{ firstError(importing.errors.value, 'file') }}</AppNotice>
    <AppRecordForm :form="form" submit-label="取り込んで確認する" :errors="importing.errors.value" :pending="importing.pending.value !== null" test="app-import-form" @submit="submit" />
</template>
