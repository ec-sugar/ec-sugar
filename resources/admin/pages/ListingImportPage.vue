<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { api, describe } from '../api.js';
import { crumbsFor, titleOf } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { canInTenant, tenantName } from '../tenants.js';
import AppButton from '../components/ui/AppButton.vue';
import AppIcon from '../components/ui/AppIcon.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import FileDrop from '../components/ui/FileDrop.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import NumCell from '../components/ui/NumCell.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import ProgressSegments from '../components/ui/ProgressSegments.vue';
import StatCard from '../components/ui/StatCard.vue';

// 商品の CSV の取り込み（docs/design/product-csv.md）。ファイルを選ぶと記録が 1 つでき、確認も反映もサーバーのキューの仕事で進む。
// 画面は記録を読み直して待つ（確認中・反映の待ち・反映中の間だけ）。誤りが 1 つでもあれば反映のボタンを出さない（サーバーも 409 で断る）。
// 反映は商品 1 つずつ入るので、止まったら「どこまで入ったか」と理由を出し、「もう一度取り込む」で同じ CSV を確認からやり直す（入った分は変わらない）。
// 写真: 写真の列の URL と、一緒に選んだ ZIP の中のファイル。反映は商品を全部入れた後に写真を 1 枚ずつ入れる（取れない写真は飛ばして理由を出す）
const props = defineProps({ tenant: { type: String, required: true } });

const POLL_MS = 1500;
const WAITING = ['checking', 'queued', 'running'];

const current = ref(null);
const loading = ref(true);
const sending = ref(false);
const error = ref(null);
// 一緒に上げる写真の ZIP（CSV を選んだときに一緒に送る）
const zip = ref(null);
let timer = null;

const canOperate = computed(() => canInTenant(props.tenant, 'operate'));
const crumbs = computed(() => crumbsFor('listing-import', props.tenant, tenantName(props.tenant)));
const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/listings-csv`);
const report = computed(() => current.value?.report ?? null);
const changes = computed(() => (report.value ? report.value.counts.create + report.value.counts.update : 0));
const photoReport = computed(() => report.value?.photos ?? null);
const photosToAdd = computed(() => photoReport.value?.add ?? 0);
const hasPhotoReport = computed(() => photoReport.value !== null && photoReport.value.add + photoReport.value.keep + photoReport.value.warnings_total > 0);
const moreWarnings = computed(() => (photoReport.value ? photoReport.value.warnings_total - photoReport.value.warnings.length : 0));
const applyLabel = computed(() => (changes.value > 0 ? `${changes.value} 行を反映する` : `写真 ${photosToAdd.value} 枚を反映する`));
const photoResult = computed(() => current.value?.photos ?? null);
const moreErrors = computed(() => (report.value ? report.value.errors_total - report.value.errors.length : 0));
const status = computed(() => current.value?.status ?? null);
// 帯は 20 に区切って、入った行の割合だけ塗る（幅を style で書かない）
const progress = computed(() => {
    const record = current.value;

    if (record?.photo_phase && record.photos?.total > 0) {
        return Math.floor(((record.photos_done ?? 0) / record.photos.total) * 20);
    }

    return record && record.rows > 0 ? Math.floor((record.applied_rows / record.rows) * 20) : 0;
});

function stopPolling() {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
}

function follow(importRecord) {
    current.value = importRecord;
    stopPolling();

    if (importRecord && WAITING.includes(importRecord.status)) {
        timer = setTimeout(reload, POLL_MS);
    }
}

async function reload() {
    timer = null;

    try {
        follow((await api('GET', `${base.value}/imports/${current.value.id}`)).import);
    } catch (e) {
        error.value = describe(e);
    }
}

// 開いたときは、いちばん新しい取り込みを出す（反映中・止まったものに戻れる）。済んだ・断った・古くなったものは出さない
async function load() {
    loading.value = true;
    error.value = null;

    try {
        const newest = (await api('GET', `${base.value}/imports`)).imports[0] ?? null;
        follow(newest && ['checking', 'checked', 'queued', 'running', 'stopped'].includes(newest.status) ? newest : null);
    } catch (e) {
        error.value = describe(e);
    } finally {
        loading.value = false;
    }
}

async function send(run) {
    sending.value = true;
    error.value = null;

    try {
        follow((await run()).import);
    } catch (e) {
        error.value = describe(e);
    } finally {
        sending.value = false;
    }
}

function choose(files) {
    const file = files[0] ?? null;

    if (file === null) {
        return;
    }

    const data = new FormData();
    data.append('file', file);

    if (zip.value !== null) {
        data.append('photos', zip.value);
    }

    send(() => api('POST', `${base.value}/check`, data));
}

function chooseZip(files) {
    zip.value = files[0] ?? null;
}

const apply = () => send(() => api('POST', `${base.value}/imports/${current.value.id}/apply`));
const retry = () => send(() => api('POST', `${base.value}/imports/${current.value.id}/retry`));
const startOver = () => {
    zip.value = null;
    follow(null);
};

watch(() => props.tenant, () => (canOperate.value ? load() : (loading.value = false)), { immediate: true });
onBeforeUnmount(stopPolling);
</script>

<template>
    <PageHeader :help="helpLink('listing-import', tenant)" :title="titleOf('listing-import')" :crumbs="crumbs" />
    <AppNotice v-if="error" tone="error" data-test="import-error">{{ error }}</AppNotice>

    <AppText v-if="!canOperate" tone="soft" data-test="import-read-only">CSV で取り込めるのは「運用」以上の権限のある人だけです。</AppText>
    <LoadingText v-else-if="loading" />
    <template v-else>
        <AppPanel v-if="current === null || status === 'rejected'" title="CSV のファイル" description="書き出した CSV と同じ列名にしてください。1 回に 2,000 行・5MB まで。CSV で商品を削除することはできません。">
            <AppText size="sm" tone="soft">
                写真は「写真1」〜「写真10」の列に、https の URL か、一緒に選ぶ ZIP の中のファイル名を書きます（写真は 1 回に 2,000 枚まで）。ZIP を使うときは、先に ZIP を選んでから CSV を選びます。
            </AppText>
            <div v-if="zip" class="flex flex-wrap items-center gap-3" data-test="zip-chosen">
                <AppText size="sm"><AppIcon name="image" />写真の ZIP: {{ zip.name }}</AppText>
                <AppButton size="sm" :disabled="sending" data-test="zip-clear" @click="zip = null">選択を解除</AppButton>
            </div>
            <FileDrop
                v-else
                label="写真の ZIP を選ぶ（任意）"
                hint="JPEG・PNG・WebP の写真をまとめた ZIP。90MB・2,500 ファイルまで"
                accept=".zip,application/zip"
                :disabled="sending"
                data-test="zip-file"
                @select="chooseZip"
            />
            <FileDrop
                label="CSV のファイルを選ぶ"
                hint="ここにファイルをドラッグ＆ドロップしても選べます"
                accept=".csv,text/csv"
                :disabled="sending"
                data-test="csv-file"
                @select="choose"
            />
            <LoadingText v-if="sending" />
        </AppPanel>

        <AppPanel v-if="status === 'checking'" title="確認しています" data-test="import-checking">
            <AppText tone="soft">{{ current.rows }} 行を、商品ごとに確認しています。まだ商品は変更していません。この画面のままお待ちください。</AppText>
            <LoadingText />
        </AppPanel>

        <AppPanel
            v-if="report && ['checked', 'rejected'].includes(status)"
            title="確認した結果"
            :description="`${report.rows} 行を読み込みました。まだ商品は変更していません。`"
            data-test="import-report"
        >
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <StatCard size="sm" label="追加する行" :value="report.counts.create" value-test="count-create" />
                <StatCard size="sm" label="更新する行" :value="report.counts.update" value-test="count-update" />
                <StatCard size="sm" label="変わらない行" :value="report.counts.skip" value-test="count-skip" />
                <StatCard size="sm" label="誤りのある行" :value="report.counts.error" value-test="count-error" />
            </div>
            <AppText size="sm" tone="soft">新しい商品 {{ report.products.create }} 件・更新する商品 {{ report.products.update }} 件</AppText>
            <template v-if="hasPhotoReport">
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4" data-test="import-photos">
                    <StatCard size="sm" label="追加する写真" :value="photoReport.add" value-test="photo-add" />
                    <StatCard size="sm" label="登録済みの写真" :value="photoReport.keep" value-test="photo-keep" />
                    <StatCard size="sm" label="取り込めない写真" :value="photoReport.warnings_total" value-test="photo-warnings" />
                </div>
                <AppText size="sm" tone="soft">写真は反映するときにダウンロードします。取得できない写真は飛ばして、商品だけを登録します（理由は後で表示します）。</AppText>
                <template v-if="photoReport.warnings_total > 0">
                    <AppTable label="写真を取得できない行" stack>
                        <thead>
                            <tr><th class="num">行</th><th>列</th><th>理由</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in photoReport.warnings" :key="index" data-test="photo-warning-row">
                                <NumCell :value="row.line" data-label="行" />
                                <td data-label="列">{{ row.column }}</td>
                                <td data-label="理由">{{ row.message }}</td>
                            </tr>
                        </tbody>
                    </AppTable>
                    <AppText v-if="moreWarnings > 0" size="sm" tone="soft">ほかに {{ moreWarnings }} 枚の写真を取り込めません。</AppText>
                </template>
            </template>
            <AppNotice v-if="report.extra_variants > 0" tone="info" :live="false">
                色・サイズなどの選択肢を追加するため、CSV に無い組み合わせの種類も {{ report.extra_variants }} 個作られます（在庫 0・販売中）。
            </AppNotice>

            <template v-if="report.errors_total > 0">
                <AppNotice tone="warning" :live="false" data-test="import-has-errors">
                    誤りのある行があるので、反映できません。CSV を修正してから、上でもう一度選んでください。
                </AppNotice>
                <AppTable label="誤りのある行" stack>
                    <thead>
                        <tr><th class="num">行</th><th>列</th><th>理由</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in report.errors" :key="index" data-test="import-error-row">
                            <NumCell :value="row.line" data-label="行" />
                            <td data-label="列">{{ row.column ?? '—' }}</td>
                            <td data-label="理由">{{ row.message }}</td>
                        </tr>
                    </tbody>
                </AppTable>
                <AppText v-if="moreErrors > 0" size="sm" tone="soft">ほかに {{ moreErrors }} 件の誤りがあります。上の行を修正してから、もう一度確認してください。</AppText>
            </template>
            <div v-else class="flex flex-wrap items-center gap-3">
                <AppText v-if="changes === 0 && photosToAdd === 0" tone="soft" data-test="import-nothing">変更のある行はありません。</AppText>
                <AppButton v-else variant="primary" :loading="sending" data-test="import-apply" @click="apply">
                    <AppIcon name="check" />{{ applyLabel }}
                </AppButton>
                <AppButton :disabled="sending" data-test="import-start-over" @click="startOver">別のファイルを選ぶ</AppButton>
            </div>
        </AppPanel>

        <AppPanel v-if="['queued', 'running'].includes(status)" title="反映しています" data-test="import-running">
            <ProgressSegments :done="progress" :total="20" label="反映の進み具合" />
            <AppText v-if="current.photo_phase" tone="soft" data-test="import-photo-phase">
                商品の登録は終わりました。写真 {{ current.photos_done ?? 0 }} / {{ current.photos?.total ?? 0 }} 枚。1 枚ずつダウンロードして追加しています（写真が多いときは、1 分あたりの上限に合わせて待ちながら進みます）。この画面を閉じても、反映は続きます。
            </AppText>
            <AppText v-else tone="soft">
                {{ current.applied_rows }} / {{ current.rows }} 行。商品を 1 つずつ登録しています。この画面を閉じても、反映は続きます。
            </AppText>
        </AppPanel>

        <template v-if="status === 'done'">
            <AppNotice tone="success" data-test="import-done">
                反映しました（追加 {{ current.applied.create }} 行・更新 {{ current.applied.update }} 行・変更なし {{ current.applied.skip }} 行）。
                <template v-if="photoResult">写真は {{ photoResult.added }} 枚を追加しました（登録済みの写真 {{ photoResult.kept }} 枚）。</template>
            </AppNotice>
            <template v-if="photoResult && photoResult.failed > 0">
                <AppNotice tone="warning" :live="false" data-test="import-photo-failed">
                    追加できなかった写真が {{ photoResult.failed }} 枚あります。商品は登録済みです。写真は商品の画面で追加するか、修正した CSV をもう一度取り込んでください（追加済みの写真は二重になりません）。
                </AppNotice>
                <AppTable label="追加できなかった写真" stack>
                    <thead>
                        <tr><th class="num">行</th><th>列</th><th>理由</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in photoResult.problems" :key="index" data-test="photo-problem-row">
                            <NumCell :value="row.line" data-label="行" />
                            <td data-label="列">{{ row.column }}</td>
                            <td data-label="理由">{{ row.message }}</td>
                        </tr>
                    </tbody>
                </AppTable>
            </template>
            <div class="flex flex-wrap gap-3">
                <AppButton :to="{ name: 'listings', params: { tenant } }">商品の一覧へ</AppButton>
                <AppButton data-test="import-start-over" @click="startOver">別のファイルを取り込む</AppButton>
            </div>
        </template>

        <AppPanel v-if="status === 'stopped'" title="反映が途中で止まりました" data-test="import-stopped">
            <AppNotice tone="warning" :live="false">
                {{ current.applied_rows }} 行を反映しました。<template v-if="current.stopped_line">{{ current.stopped_line }} 行目の商品で止まりました。</template>{{ current.stopped_reason }}
            </AppNotice>
            <AppText tone="soft">反映済みの商品はそのままです。原因を解決してから「もう一度取り込む」を押すと、同じ CSV を確認し直し、残りだけを反映します（反映済みの行は「変わらない行」に数えます）。</AppText>
            <div class="flex flex-wrap gap-3">
                <AppButton v-if="current.retryable" variant="primary" :loading="sending" data-test="import-retry" @click="retry">
                    <AppIcon name="refresh" />もう一度取り込む
                </AppButton>
                <AppButton :disabled="sending" data-test="import-start-over" @click="startOver">別のファイルを選ぶ</AppButton>
            </div>
        </AppPanel>
    </template>
</template>
