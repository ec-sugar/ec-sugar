<script setup>
import { computed, ref } from 'vue';
import { api, describe } from '../../api.js';
import { formatDate } from '../../format.js';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppTable from '../../components/ui/AppTable.vue';
import AppText from '../../components/ui/AppText.vue';
import ConfirmButton from '../../components/ui/ConfirmButton.vue';
import FileDrop from '../../components/ui/FileDrop.vue';

// 商品の画面の App の欄のファイル（ADR 0084。App「ダウンロード販売」の売るファイル）。
// 大きなファイル（500 MB まで）を、サーバーが決めた大きさ（8 MB）の切れ端に分けて順に送る（始める → 切れ端 → 終える）。途中で落ちたら上げる記録を消す。
// 形（拡張子と中身）・数・大きさ・店の合計はサーバーが確認する（画面の accept は選びやすくするためだけ）。
// 上げる・消すはすぐ反映する（下の「保存する」は要らない）。答えの App の欄で親を置き換える（update:app）
const props = defineProps({
    tenantBase: { type: String, required: true },
    listingId: { type: String, required: true },
    app: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:app']);

const files = computed(() => props.app.files);
const base = computed(() => `${props.tenantBase}/listings/${encodeURIComponent(props.listingId)}/apps/${encodeURIComponent(props.app.plugin)}`);
const accept = computed(() => files.value.accept.map((ext) => `.${ext}`).join(','));
const busy = ref(false);
const progress = ref(null);
const message = ref(null);
const error = ref(null);

function size(bytes) {
    if (bytes >= 1024 * 1024 * 1024) {
        return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`;
    }

    return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.max(1, Math.ceil(bytes / 1024))} KB`;
}

async function send(file) {
    const { upload, chunk_bytes: chunk } = await api('POST', `${base.value}/uploads`, { name: file.name, size: file.size });

    try {
        for (let offset = 0; offset < file.size; offset += chunk) {
            progress.value = `${file.name}: ${size(Math.min(file.size, offset + chunk))} / ${size(file.size)} をアップロードしています…`;
            const body = new FormData();
            body.append('offset', String(offset));
            body.append('chunk', file.slice(offset, offset + chunk), 'chunk');
            await api('POST', `${base.value}/uploads/${encodeURIComponent(upload)}`, body);
        }

        progress.value = `${file.name}: ファイルの内容を確認しています…`;

        return (await api('POST', `${base.value}/uploads/${encodeURIComponent(upload)}/finish`)).app;
    } catch (e) {
        // 途中で落ちた: 上げる記録を消す（終える時に断られた分はサーバーがもう消している）
        await api('DELETE', `${base.value}/uploads/${encodeURIComponent(upload)}`).catch(() => {});
        throw e;
    }
}

async function upload(selected) {
    busy.value = true;
    message.value = null;
    error.value = null;
    let added = 0;
    const problems = [];

    for (const file of selected) {
        try {
            emit('update:app', await send(file));
            added++;
        } catch (e) {
            problems.push(`${file.name}: ${describe(e)}`);
        }
    }

    progress.value = null;
    busy.value = false;
    message.value = added > 0 ? `ファイルを ${added} 個アップロードしました。` : null;
    error.value = problems.length > 0 ? problems.join(' ') : null;
}

async function remove(file) {
    busy.value = true;
    message.value = null;
    error.value = null;

    try {
        emit('update:app', (await api('DELETE', `${base.value}/files/${encodeURIComponent(file.id)}`)).app);
        message.value = `${file.name} を削除しました（すでにお客さんにお渡ししたリンクは、期限まで使えます）。`;
    } catch (e) {
        error.value = describe(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-3" data-test="listing-app-files">
        <AppText size="sm" tone="soft">
            販売するファイル（{{ files.items.length }} / {{ files.max_files }}。1 つ {{ size(files.max_bytes) }} まで。アップロードできる形式: {{ files.accept.join('・') }}）。
            店の合計 {{ size(files.used_bytes) }} / {{ size(files.quota_bytes) }}。アップロードと削除はすぐ反映されます。
        </AppText>
        <AppTable v-if="files.items.length > 0" label="販売するファイル">
            <thead>
                <tr><th>ファイル</th><th class="num">サイズ</th><th>アップロードした日時</th><th><span class="sr-only">操作</span></th></tr>
            </thead>
            <tbody>
                <tr v-for="file in files.items" :key="file.id" data-test="listing-app-file">
                    <td>{{ file.name }}</td>
                    <td class="num">{{ size(file.bytes) }}</td>
                    <td>{{ formatDate(file.created_at) }}</td>
                    <td class="text-right">
                        <ConfirmButton v-if="!disabled" label="削除" confirm-label="削除" :disabled="busy" test="listing-app-file-remove" @confirm="remove(file)" />
                    </td>
                </tr>
            </tbody>
        </AppTable>
        <FileDrop
            v-if="!disabled && files.items.length < files.max_files"
            :accept="accept"
            multiple
            :disabled="busy"
            label="ファイルをアップロード"
            hint="押して選ぶか、ここへドラッグ＆ドロップします"
            data-test="listing-app-file-drop"
            @select="upload"
        />
        <AppText v-if="progress" size="sm" tone="soft" data-test="listing-app-file-progress">{{ progress }}</AppText>
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error" data-test="listing-app-file-error">{{ error }}</AppNotice>
    </div>
</template>
