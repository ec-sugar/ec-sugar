<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../api.js';
import { crumbsFor, titleOf } from '../navigation.js';
import { formatDate } from '../format.js';
import { tenantName } from '../tenants.js';
import AppPanel from '../components/ui/AppPanel.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import CellTitle from '../components/ui/CellTitle.vue';
import CodeText from '../components/ui/CodeText.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import PageHeader from '../components/ui/PageHeader.vue';

// 処理を追う（ADR 0087）。処理の ID（管理画面の応答の X-Request-Id・ログの trace_id・運営ダッシュボードのエラー）で、
// この店の関係する記録を時刻の順に並べる。オーナーだけ（API が断る）。読むだけ。返ってくるのは種類・ID・時刻・状態だけ
const props = defineProps({
    tenant: { type: String, required: true },
    trace: { type: String, required: true },
});

const data = ref(null);
const error = ref(null);
const crumbs = computed(() => crumbsFor('trace', props.tenant, tenantName(props.tenant)));

async function load() {
    data.value = null;
    error.value = null;

    try {
        data.value = await api('GET', `/api/tenants/${encodeURIComponent(props.tenant)}/traces/${encodeURIComponent(props.trace)}`);
    } catch (e) {
        error.value = e;
    }
}

watch(() => [props.tenant, props.trace], load, { immediate: true });

const short = (name) => name.slice(name.lastIndexOf('\\') + 1);

// どの記録も「いつ・どの記録・何が・何の ID」の 1 行にそろえ、時刻の順に並べる
const rows = computed(() => {
    const d = data.value;

    if (!d) {
        return [];
    }

    return [
        ...d.events.map((e) => ({ key: `event-${e.sequence}`, at: e.at, source: 'イベント', what: e.type, ref: `${e.aggregate_type} ${e.aggregate_id}`, by: e.actor })),
        ...d.audit.map((a) => ({ key: `audit-${a.sequence}`, at: a.at, source: '監査ログ', what: a.type, ref: a.operator_id ? `メンバー ${a.operator_id}` : '', by: null })),
        ...d.mails.map((m) => ({ key: `mail-${m.id}`, at: m.at, source: '送ったメール', what: `${m.kind}（${m.status}）`, ref: `注文 ${m.transaction_id}`, by: null })),
        ...d.checkouts.map((c) => ({ key: `checkout-${c.id}`, at: c.at, source: '支払いの画面', what: `${c.attempt} 回目`, ref: c.idempotency_key, by: null })),
        ...d.refunds.map((r) => ({ key: `refund-${r.id}`, at: r.at, source: '返金', what: `${r.purpose}（${r.status}）`, ref: r.idempotency_key, by: null })),
        ...d.webhooks.map((w) => ({ key: `webhook-${w.provider}-${w.event_id}`, at: w.at, source: '決済代行の通知', what: `${w.type}（${w.outcome}）`, ref: w.event_id, by: null })),
        ...d.incidents.map((i, index) => ({ key: `incident-${index}`, at: i.at, source: 'エラー', what: short(i.exception), ref: i.location, by: null })),
        ...d.failed_jobs.map((j) => ({ key: `job-${j.uuid}`, at: j.at, source: '失敗したバックグラウンド処理', what: short(j.job), ref: j.uuid, by: null })),
    ].sort((a, b) => (a.at ?? '').localeCompare(b.at ?? ''));
});
</script>

<template>
    <PageHeader :title="titleOf('trace')" :crumbs="crumbs" />
    <ErrorState v-if="error" :error="error" />
    <LoadingText v-else-if="data === null" />
    <AppPanel v-else title="関係する記録" description="同じ処理の ID が付いた、この店の記録です（イベント・監査ログ・送ったメール・支払い・返金・決済代行の通知・エラー・失敗したバックグラウンド処理）。内容・宛先・エラーの文は表示しません。" data-test="trace-records">
        <AppText size="sm" tone="soft">処理の ID <CodeText data-test="trace-id">{{ data.trace_id }}</CodeText></AppText>
        <EmptyState v-if="rows.length === 0" icon="search" title="この ID の記録は、この店にはありません" data-test="trace-empty">ID のコピー間違いか、ほかの店の処理かもしれません。</EmptyState>
        <AppTable v-else label="関係する記録" stack>
            <thead>
                <tr><th>日時</th><th>記録</th><th>内容</th><th>ID</th></tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.key" data-test="trace-row">
                    <td data-label="日時"><AppText as="span" tone="muted" nowrap>{{ formatDate(row.at) }}</AppText></td>
                    <td data-label="記録">{{ row.source }}</td>
                    <td data-label="内容"><CellTitle :label="row.what" :sub="row.by" /></td>
                    <td data-label="ID"><CodeText>{{ row.ref || '—' }}</CodeText></td>
                </tr>
            </tbody>
        </AppTable>
    </AppPanel>
</template>
