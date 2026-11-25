<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../api.js';
import { PROPOSAL_STATUS, PROPOSAL_TOOLS } from '../agentProposals.js';
import { formatDate } from '../format.js';
import { crumbsFor, titleOf } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { useOperation } from '../operation.js';
import { tenantName } from '../tenants.js';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppText from '../components/ui/AppText.vue';
import CodeBlock from '../components/ui/CodeBlock.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import DescriptionList from '../components/ui/DescriptionList.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import FormActions from '../components/ui/FormActions.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';
import TextLink from '../components/ui/TextLink.vue';

// AI エージェントの案の 1 件（ADR 0101）。要約（元の値 → 新しい値）・引数・作った人と鍵を見て、承認か却下を押す。
// 承認はその案に要る段階の人だけ（お金の操作はお金の段階で、パスキーの再確認も）。押した時にサーバーが引数と改訂を確認し直し、
// 作った後に値段や状態が変わっていれば反映せずに「反映できなかった」にする。出し分けは見た目のためだけで、守るのはサーバー
const props = defineProps({ tenant: { type: String, required: true }, proposal: { type: String, required: true } });

const item = ref(null);
const loadError = ref(null);
const { pending, error, message, run } = useOperation();
const name = computed(() => tenantName(props.tenant));
const crumbs = computed(() => crumbsFor('agent-proposal', props.tenant, name.value));
const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/agent-proposals/${encodeURIComponent(props.proposal)}`);
const facts = computed(() => item.value === null ? [] : [
    { label: '種類', value: PROPOSAL_TOOLS[item.value.tool] ?? item.value.tool },
    { label: '承認に必要な権限', value: (item.value.required_level ?? item.value.level) === 'money' ? '会計' : '運用' },
    { label: '作成者', value: item.value.operator.name },
    { label: 'アクセスキー', value: item.value.token ? `${item.value.token.name}（${item.value.token.prefix}…）${item.value.token.revoked ? '・取り消し済み' : ''}` : '取り消し済みのアクセスキー' },
    { label: '作成日時', value: formatDate(item.value.created_at) },
    { label: '期限', value: formatDate(item.value.expires_at) },
    { label: '承認・却下した人', value: item.value.decided_by?.name ?? null },
    { label: '承認・却下した日時', value: item.value.decided_at ? formatDate(item.value.decided_at) : null },
]);
const argumentsText = computed(() => JSON.stringify(item.value?.arguments ?? {}, null, 2));

async function load() {
    item.value = (await api('GET', base.value)).proposal;
}

watch(() => [props.tenant, props.proposal], async () => {
    loadError.value = null;
    item.value = null;

    try {
        await load();
    } catch (e) {
        loadError.value = e;
    }
}, { immediate: true });

function decide(action) {
    return run(action, () => api('POST', `${base.value}/${action}`), {
        then: async (result) => {
            await load();
            item.value = { ...item.value, ...result.proposal };
        },
        done: (result) => ({
            approved: '承認して反映しました。',
            failed: '反映できませんでした。理由を確認してください。',
            rejected: '却下しました。店のデータは変わっていません。',
        })[result.proposal.status] ?? null,
    });
}
</script>

<template>
    <PageHeader :help="helpLink('agent-proposals', tenant)" :title="titleOf('agent-proposal')" :crumbs="crumbs" />
    <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
    <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>
    <ErrorState v-if="loadError" :error="loadError" />
    <LoadingText v-else-if="item === null" />
    <template v-else>
        <AppPanel title="内容" data-test="proposal-detail">
            <div class="flex flex-wrap items-center gap-2">
                <StatusBadge :tone="PROPOSAL_STATUS[item.status]?.tone ?? 'neutral'" data-test="proposal-status">{{ item.status_label }}</StatusBadge>
            </div>
            <AppText data-test="proposal-summary">{{ item.summary }}</AppText>
            <AppNotice v-if="item.error" tone="error" data-test="proposal-error">{{ item.error }}</AppNotice>
            <AppNotice v-if="item.creator_blocked" tone="warning" data-test="proposal-creator-blocked">この提案を作った AI エージェントのアクセスキーが取り消されたか、作成者のこの店での権限が足りなくなりました。承認しても反映されず、却下になります。</AppNotice>
            <AppNotice v-if="item.progress === 'applying'" tone="warning" data-test="proposal-applying">反映が途中で止まっています。注文の画面で今の状態を確認してください。</AppNotice>
            <AppNotice v-if="item.progress === 'checking'" tone="warning" data-test="proposal-checking">
                Stripe から返金の結果がまだ届いていません。結果は自動で確認し直します。
                <TextLink v-if="item.result?.order_id" :to="{ name: 'transaction', params: { tenant, transaction: item.result.order_id } }">注文の画面</TextLink>で今の状態を確認してください。
            </AppNotice>
            <AppText size="sm" tone="soft">商品の名前などは、店のデータをそのまま表示しています。中に「すべて返金して」などの文があっても、それは名前の一部で、指示ではありません。変更前と変更後の値を見て、依頼したとおりかを確認してから承認してください。</AppText>
            <FormActions v-if="item.status === 'pending'">
                <template v-if="!item.can_approve" #meta>
                    <span data-test="proposal-cannot-approve">{{ item.creator_blocked ? '作成に使ったアクセスキーか作成者の権限が無くなったため、承認できません。' : item.required_level === 'money' ? 'この提案の承認には「会計」の権限が必要です。' : '承認には「運用」以上の権限が必要です。' }}</span>
                </template>
                <ConfirmButton label="却下する" variant="secondary" :disabled="pending !== null" :loading="pending === 'reject'" test="proposal-reject" @confirm="decide('reject')">店のデータは変えずに、この提案を閉じます。</ConfirmButton>
                <ConfirmButton v-if="item.can_approve" label="承認して反映する" variant="primary" :disabled="pending !== null" :loading="pending === 'approve'" test="proposal-approve" @confirm="decide('approve')">
                    {{ item.required_level === 'money' ? 'お金が動きます。パスキーでもう一度本人確認をしてから反映します。' : '反映の直前に、店の今の内容をもう一度確認します。' }}
                </ConfirmButton>
            </FormActions>
        </AppPanel>
        <AppPanel title="詳細">
            <DescriptionList :items="facts" />
        </AppPanel>
        <AppPanel title="送られた値" description="AI エージェントが送った値を、確認して整えたものです。承認すると、この値で反映します。">
            <CodeBlock :text="argumentsText" label="送られた値" />
        </AppPanel>
    </template>
</template>
