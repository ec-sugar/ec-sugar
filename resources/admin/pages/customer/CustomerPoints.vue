<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../../api.js';
import { firstError, formatDate, shortId } from '../../format.js';
import { useOperation } from '../../operation.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppField from '../../components/ui/AppField.vue';
import AppInput from '../../components/ui/AppInput.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppTable from '../../components/ui/AppTable.vue';
import AppText from '../../components/ui/AppText.vue';
import AppTextarea from '../../components/ui/AppTextarea.vue';
import CellTitle from '../../components/ui/CellTitle.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import FormActions from '../../components/ui/FormActions.vue';
import InsetSection from '../../components/ui/InsetSection.vue';
import LoadingText from '../../components/ui/LoadingText.vue';
import NumCell from '../../components/ui/NumCell.vue';
import StatCard from '../../components/ui/StatCard.vue';
import StatusBadge from '../../components/ui/StatusBadge.vue';

// お客さんの 1 人の画面のポイント（ADR 0068 の 6）。残高・期限・付く予定・帳簿は閲覧から（数だけ。店の人の理由は運用から）。
// 手で足す・引くはお金の段階の人だけで、送るとサーバーが直前のパスキーの再確認を求める（useOperation が確認してから送り直す）。
// 段階での出し分けは見た目のためだけで、守るのはサーバー（足りない段階には 403）
const props = defineProps({
    tenant: { type: String, required: true },
    customer: { type: String, required: true },
});

const KINDS = {
    earn: '発送で付与',
    spend: '注文で利用',
    release: '取消で戻し',
    revoke: '返金で取消',
    expire: '失効',
    adjust: '手動の調整',
};
const CAUSES = { inactive: '期限', left: '退会', refund: '返金', shipping: '送料の差額' };

const points = ref(null);
const loadError = ref(null);
const draft = ref({ points: '', reason: '' });
const adjusting = useOperation();

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/customers/${encodeURIComponent(props.customer)}/points`);
const pendingTotal = computed(() => (points.value?.pending ?? []).reduce((sum, order) => sum + order.points, 0));

watch(() => [props.tenant, props.customer], load, { immediate: true });

async function load() {
    loadError.value = null;

    try {
        points.value = (await api('GET', base.value)).points;
    } catch (e) {
        loadError.value = e;
    }
}

function adjust() {
    return adjusting.run('adjust', () => api('POST', base.value, { points: Number(draft.value.points), reason: draft.value.reason }), {
        then: async () => {
            draft.value = { points: '', reason: '' };
            await load();
        },
        done: (result) => `ポイントを調整しました。残高は ${result.balance.toLocaleString('ja-JP')} ポイントです。`,
    });
}

function signed(value) {
    return `${value > 0 ? '+' : ''}${value.toLocaleString('ja-JP')}`;
}

function kindLabel(entry) {
    // App「友だち紹介」が付けた分（ADR 0075）は、手の足し引きでなく紹介として見せる
    if (entry.kind === 'adjust' && entry.cause === 'referral') {
        return '友だち紹介で付与';
    }

    const cause = entry.cause ? CAUSES[entry.cause] : null;

    return cause ? `${KINDS[entry.kind]}（${cause}）` : KINDS[entry.kind];
}
</script>

<template>
    <AppPanel title="ポイント" description="1 ポイント = 1 円。貯めて使えるのは会員だけです。最後にポイントを付与・利用した日から 1 年で失効します。" data-test="customer-points">
        <ErrorState v-if="loadError" :error="loadError" />
        <LoadingText v-else-if="points === null" />
        <template v-else>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard label="残高" :value="`${points.balance.toLocaleString('ja-JP')} ポイント`" size="sm" value-test="points-balance" />
                <StatCard label="期限" :value="points.expires_at ? formatDate(points.expires_at) : '—'" size="sm" note="最後の付与・利用から 1 年" value-test="points-expiry" />
                <StatCard label="付与予定" :value="`${pendingTotal.toLocaleString('ja-JP')} ポイント`" size="sm" note="支払い済みで発送待ちの注文" value-test="points-pending" />
            </div>

            <AppText v-if="points.history.length === 0" tone="soft" data-test="points-empty">まだポイントの履歴はありません。</AppText>
            <AppTable v-else label="ポイントの履歴" stack>
                <thead>
                    <tr><th>日時</th><th>内容</th><th class="num">ポイント</th><th class="num">残高</th><th>注文・理由</th></tr>
                </thead>
                <tbody>
                    <tr v-for="entry in points.history" :key="entry.id" data-test="points-row" :data-kind="entry.kind">
                        <td data-label="日時"><AppText as="span" tone="muted" nowrap>{{ formatDate(entry.occurred_at) }}</AppText></td>
                        <td data-label="内容">
                            <StatusBadge :tone="entry.points > 0 ? 'accent' : 'neutral'">{{ kindLabel(entry) }}</StatusBadge>
                        </td>
                        <NumCell :value="signed(entry.points)" strong data-label="ポイント" />
                        <NumCell :value="entry.balance_after.toLocaleString('ja-JP')" data-label="残高" />
                        <td data-label="注文・理由">
                            <CellTitle v-if="entry.transaction_id" :label="shortId(entry.transaction_id)" :to="{ name: 'transaction', params: { tenant, transaction: entry.transaction_id } }" />
                            <AppText v-if="entry.reason" as="span" size="sm" data-test="points-reason">{{ entry.reason }}</AppText>
                            <AppText v-if="entry.shortfall > 0" as="span" size="xs" tone="soft">残高が足りず {{ entry.shortfall.toLocaleString('ja-JP') }} ポイントは減らせませんでした</AppText>
                        </td>
                    </tr>
                </tbody>
            </AppTable>

            <InsetSection v-if="points.can_adjust && points.member" title="ポイントを調整する" description="お詫び・入力の誤りの訂正などに使います。減らすときは数字の前に「-」を付けます（残高より多くは減らせません）。押すとパスキーで本人確認をします。" data-test="points-adjust">
                <AppNotice v-if="adjusting.message.value" tone="success" data-test="points-adjust-done">{{ adjusting.message.value }}</AppNotice>
                <AppNotice v-if="adjusting.error.value" tone="error" data-test="points-adjust-error">{{ adjusting.error.value }}</AppNotice>
                <form class="flex flex-col gap-4" @submit.prevent="adjust">
                    <AppField label="調整するポイント" hint="例: 500（加算）・-200（減算）" :error="firstError(adjusting.errors.value, 'points')">
                        <AppInput v-model="draft.points" type="number" step="1" min="-1000000" max="1000000" inputmode="numeric" required data-test="points-adjust-amount" />
                    </AppField>
                    <AppField label="理由" hint="店の中の記録です（お客さんのマイページには表示されません）。200 文字まで。" :error="firstError(adjusting.errors.value, 'reason')">
                        <AppTextarea v-model="draft.reason" maxlength="200" required data-test="points-adjust-reason" />
                    </AppField>
                    <FormActions>
                        <AppButton type="submit" variant="primary" :disabled="adjusting.pending.value !== null" :loading="adjusting.pending.value === 'adjust'" data-test="points-adjust-save">ポイントを調整する</AppButton>
                    </FormActions>
                </form>
            </InsetSection>
            <AppText v-else-if="points.member" size="sm" tone="soft" data-test="points-adjust-no-permission">ポイントを手動で調整できるのは、「会計」の権限のある人だけです。</AppText>
        </template>
    </AppPanel>
</template>
