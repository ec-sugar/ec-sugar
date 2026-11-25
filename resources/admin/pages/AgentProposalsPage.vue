<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../api.js';
import { PROPOSAL_STATUS, PROPOSAL_TOOLS } from '../agentProposals.js';
import { formatDate } from '../format.js';
import { crumbsFor, titleOf } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { tenantName } from '../tenants.js';
import AppPanel from '../components/ui/AppPanel.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import CellTitle from '../components/ui/CellTitle.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import FilterSelect from '../components/ui/FilterSelect.vue';
import ListFilters from '../components/ui/ListFilters.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';

// AI エージェントの案（承認の一覧。ADR 0101）。店の設定が「承認してから」の店と、お金に届く操作（取消・返金・クーポン）で、
// エージェントが作った案が並ぶ。1 件の画面で中身を見て、承認か却下を押す。見るのも運用の段階から（API も 403 で断る）
const props = defineProps({ tenant: { type: String, required: true } });

const status = ref('pending');
const proposals = ref(null);
const counts = ref(null);
const error = ref(null);
const name = computed(() => tenantName(props.tenant));

watch(() => [props.tenant, status.value], async (_now, _before, onCleanup) => {
    let stale = false;
    onCleanup(() => { stale = true; });
    error.value = null;
    proposals.value = null;
    const params = new URLSearchParams(status.value ? { status: status.value } : {});

    try {
        const result = await api('GET', `/api/tenants/${encodeURIComponent(props.tenant)}/agent-proposals?${params}`);

        if (!stale) {
            proposals.value = result.proposals;
            counts.value = result.counts;
        }
    } catch (e) {
        if (!stale) {
            error.value = e;
        }
    }
}, { immediate: true });
</script>

<template>
    <PageHeader :help="helpLink('agent-proposals', tenant)" :title="titleOf('agent-proposals')" :crumbs="crumbsFor('agent-proposals', tenant, name)" />
    <ErrorState v-if="error" :error="error" />
    <AppPanel v-else>
        <ListFilters label="提案の絞り込み" :total="proposals?.length ?? null" :filtered="status !== 'pending'" @clear="status = 'pending'">
            <FilterSelect v-model="status" label="状態" data-test="proposal-status-filter">
                <option value="pending">承認待ち（{{ counts?.pending ?? 0 }}）</option>
                <option value="">すべて（{{ counts?.all ?? 0 }}）</option>
                <option v-for="value in ['approved', 'rejected', 'failed', 'expired']" :key="value" :value="value">{{ PROPOSAL_STATUS[value].label }}</option>
            </FilterSelect>
        </ListFilters>
        <LoadingText v-if="proposals === null" />
        <EmptyState v-else-if="proposals.length === 0" icon="check" title="提案はありません" data-test="proposals-empty">
            AI エージェントが変更を提案すると、ここに並びます。提案は 7 日で期限切れになります。
        </EmptyState>
        <AppTable v-else label="AI エージェントの提案" stack>
            <thead>
                <tr><th>内容</th><th>種類</th><th>作成者とアクセスキー</th><th>作成日時</th><th>状態</th></tr>
            </thead>
            <tbody>
                <tr v-for="proposal in proposals" :key="proposal.id" data-test="proposal-row">
                    <td><CellTitle :label="proposal.summary" :to="{ name: 'agent-proposal', params: { tenant, proposal: proposal.id } }" data-test="proposal-summary" /></td>
                    <td data-label="種類"><AppText as="span" nowrap>{{ PROPOSAL_TOOLS[proposal.tool] ?? proposal.tool }}</AppText></td>
                    <td data-label="作成者とアクセスキー"><AppText as="span" tone="muted">{{ proposal.operator.name ?? '—' }}・{{ proposal.token?.name ?? '取り消し済みのアクセスキー' }}{{ proposal.token?.revoked ? '（取り消し済み）' : '' }}</AppText></td>
                    <td data-label="作成日時"><AppText as="span" tone="muted" nowrap>{{ formatDate(proposal.created_at) }}</AppText></td>
                    <td data-label="状態"><StatusBadge :tone="PROPOSAL_STATUS[proposal.status]?.tone ?? 'neutral'" data-test="proposal-status">{{ proposal.status_label }}{{ proposal.progress === 'checking' ? '（確認中）' : proposal.progress === 'applying' ? '（反映中）' : '' }}</StatusBadge></td>
                </tr>
            </tbody>
        </AppTable>
    </AppPanel>
</template>
