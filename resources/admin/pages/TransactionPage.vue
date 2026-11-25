<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { api, describe } from '../api.js';
import {
    firstError, formatAmount, formatDate, formatDay, formatRate, FULFILLMENT_METHOD_LABELS, FULFILLMENT_STATUS_LABELS,
    ORDER_STATUS_LABELS, ORDER_STATUS_TONES, PHASE_LABELS, QUOTE_STATUS_LABELS, REFUND_STATE_LABELS, REFUND_STATE_TONES, ROLE_LABELS, shortId, TRANSACTION_STATUS_LABELS, TRANSACTION_STATUS_TONES,
} from '../format.js';
import { useOperation } from '../operation.js';
import { crumbsFor } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { canInTenant, tenantName } from '../tenants.js';
import AmountSummary from '../components/ui/AmountSummary.vue';
import AppButton from '../components/ui/AppButton.vue';
import AppCheckbox from '../components/ui/AppCheckbox.vue';
import AppField from '../components/ui/AppField.vue';
import AppInput from '../components/ui/AppInput.vue';
import AppSelect from '../components/ui/AppSelect.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import FormActions from '../components/ui/FormActions.vue';
import AppIcon from '../components/ui/AppIcon.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppTable from '../components/ui/AppTable.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import AppText from '../components/ui/AppText.vue';
import CellTitle from '../components/ui/CellTitle.vue';
import DescriptionList from '../components/ui/DescriptionList.vue';
import InsetSection from '../components/ui/InsetSection.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import NumCell from '../components/ui/NumCell.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';
import StepPath from '../components/ui/StepPath.vue';
import TransactionApps from './transaction/TransactionApps.vue';
import TransactionEvents from './transaction/TransactionEvents.vue';
import TransactionLedger from './transaction/TransactionLedger.vue';
import TransactionReturn from './transaction/TransactionReturn.vue';

// 注文の詳細（画面の言葉は「注文」。コードと API の名前は Transaction のまま）。個人情報は「表示する」を押したときだけ別の API から取る（見た事実はサーバーの監査ログに残る）
const props = defineProps({
    tenant: { type: String, required: true },
    transaction: { type: String, required: true },
});
const detail = ref(null);
const error = ref(null);
const personal = ref(null);
const personalBusy = ref(false);
const personalError = ref(null);
// 注文の操作
const shipForm = reactive({ fulfillment_id: '', carrier: '', tracking_number: '' });
const { pending: operating, errors: operationErrors, error: operationError, message: operationMessage, run } = useOperation();
// 送り先を待つ受渡（贈り物の受け取り待ち。ADR 0055）と、発売日の前の予約の品の受渡（ADR 0073）は発送できないので選ばせない
const pendingFulfillments = computed(() => (detail.value?.fulfillments ?? []).filter((f) => f.status === 'pending' && !f.awaiting_address && !f.held_for_release));
// 贈り物の様子の色（ADR 0055）
const GIFT_TONES = { preparing: 'neutral', waiting: 'warn', claimed: 'accent', shipped: 'accent', cancelling: 'warn', stopped: 'danger', cancelled: 'neutral', expired: 'neutral' };

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}`);
// App の注文の書類（納品書・領収書など）。印刷用のページを新しいタブで開く。届け先や名前が載るので、運用の段階の人にだけ出す（守るのはサーバー）
const documents = ref([]);
const documentLinks = computed(() => (detail.value?.kind === 'sale' && canInTenant(props.tenant, 'operate')
    ? documents.value.map((doc) => ({ ...doc, href: `${base.value}/apps/${encodeURIComponent(doc.plugin)}/documents/${encodeURIComponent(doc.key)}?ids=${encodeURIComponent(props.transaction)}` }))
    : []));

watch(() => props.tenant, async () => {
    documents.value = [];

    try {
        documents.value = (await api('GET', `${base.value}/order-documents`)).documents;
    } catch {
        // 書類のボタンが出ないだけ（注文の画面は開ける）
    }
}, { immediate: true });
const money = (amount) => formatAmount(amount, detail.value?.currency ?? 'JPY');
const crumbs = computed(() => crumbsFor('transaction', props.tenant, tenantName(props.tenant), shortId(props.transaction)));
const steps = computed(() => (detail.value?.path ?? []).map((value) => ({ value, label: PHASE_LABELS[value] ?? value })));
// オプションの入れた文字を、従う商品の行の名前と一緒に（明細の何行目かも添え、同じ商品の行が 2 つあっても見分ける）
const addonTextItems = computed(() => (personal.value?.addon_texts ?? []).map((entry) => {
    const parent = (detail.value?.lines ?? []).find((line) => line.position === entry.parent_position);

    return { label: `${parent?.description ?? '商品'}（明細の ${entry.parent_position} 行目）／${entry.description}`, value: entry.text };
}));
// 注文の項目（ADR 0066）。文の答えは、下の「個人情報」を表示したときにそちらで見る
const orderFieldItems = computed(() => (detail.value?.order_fields ?? []).map((field) => ({
    label: field.name,
    value: field.private ? '（自由記述の回答。「個人情報」を表示すると表示されます）' : field.value,
})));
const crossCutting = computed(() => (detail.value && !detail.value.path.includes(detail.value.phase) ? detail.value.phase : null));
const summary = computed(() => {
    const t = detail.value;

    return [
        { label: '種類', value: t.kind_label },
        { label: '販売者', value: t.seller?.name },
        { label: '顧客', value: t.has_customer ? 'あり（下の「個人情報」で表示）' : 'なし' },
        { label: '支払いの流れ', value: `${ROLE_LABELS[t.payer] ?? t.payer} → ${ROLE_LABELS[t.payee] ?? t.payee}` },
        { label: '作成', value: formatDate(t.created_at) },
        { label: '合意', value: t.agreed_at ? formatDate(t.agreed_at) : null },
        { label: 'お支払いの方法', value: t.payment?.method_label ?? null },
        { label: '入金日', value: t.paid_at ? formatDate(t.paid_at) : null },
        { label: '出荷日', value: t.shipped_at ? formatDate(t.shipped_at) : null },
        { label: '完了', value: t.completed_at ? formatDate(t.completed_at) : null },
        { label: '終了', value: t.closed_at ? formatDate(t.closed_at) : null },
        { label: '注文の ID', value: t.id, mono: true },
        { label: '親の注文', value: t.parent_id, mono: true },
    ];
});

watch(() => [props.tenant, props.transaction], async () => {
    detail.value = null;
    personal.value = null;
    error.value = null;

    try {
        fill((await api('GET', `${base.value}/transactions/${encodeURIComponent(props.transaction)}`)).transaction);
    } catch (e) {
        error.value = e;
    }
}, { immediate: true });

function fill(transaction) {
    detail.value = transaction;
    Object.assign(shipForm, { fulfillment_id: pendingFulfillments.value[0]?.id ?? '', carrier: '', tracking_number: '' });
}

function operate(name, path, body, done) {
    return run(name, () => api('POST', `${base.value}/transactions/${encodeURIComponent(props.transaction)}/${path}`, body), { done, then: (result) => fill(result.transaction) });
}

function ship() {
    return operate('ship', 'ship', {
        fulfillment_id: shipForm.fulfillment_id,
        carrier: shipForm.carrier === '' ? null : shipForm.carrier,
        tracking_number: shipForm.tracking_number.trim() === '' ? null : shipForm.tracking_number.trim(),
    }, '発送を登録しました。');
}

// 返金が要る支払い（二重に払われた・取り消した注文に届いた）を返金する（お金が動くので、パスキーでもう一度確認する）
const STRAY_REASONS = { duplicate: '同じ注文が二重に支払われた', late: '取り消した注文に支払いが届いた' };

function refundStray(stray) {
    return operate(`stray-${stray.id}`, `stray-payments/${stray.id}/refund`, {}, `${money(stray.amount)} を Stripe で返金しました。`);
}

// 止めた返金の片付け。店の人が Stripe のダッシュボードで確認してから選ぶ（お金が動くので、パスキーでもう一度確認する）
const resolveAmounts = reactive({});

function resolveAmount(refund) {
    return resolveAmounts[refund.id] ?? String(refund.amount);
}

function resolveRefund(refund, outcome) {
    const amount = outcome === 'recorded' ? Number.parseInt(resolveAmount(refund), 10) : null;
    const done = outcome === 'recorded'
        ? (refund.purpose === 'cancel' ? `${money(amount)} の返金を記録し、注文を取り消しました。` : `${money(amount)} の返金を記録しました。`)
        : '返金は行われなかったものとして、この返金の記録を閉じました。';

    return operate(`resolve-${refund.id}`, `refunds/${refund.id}/resolve`, { outcome, amount: Number.isNaN(amount) ? null : amount }, done);
}

// 断られた贈り物の送料の差額の返金を頼み直す（ADR 0064。お金が動くので、パスキーでもう一度確認する）。通知は新しい返金の答えで変える
// 使ったギフトカードの額をカードへ戻す（ADR 0076。お金が動くので、パスキーでもう一度確認する）
const giftCardRelease = ref('');

function releaseGiftCard() {
    const amount = Number.parseInt(giftCardRelease.value, 10);

    return operate('release-gift-card', 'gift-card/release', { amount: Number.isNaN(amount) ? null : amount }, () => {
        giftCardRelease.value = '';

        return `${money(amount)} をギフトカードの残高に戻しました。`;
    });
}

function retryShippingRefund() {
    return operate('retry-shipping-refund', 'shipping-refund/retry', {}, (result) => {
        const refund = [...(result.transaction.refunds ?? [])].reverse().find((r) => r.purpose === 'shipping');

        if (refund?.status === 'failed') {
            return `Stripe で、再び返金が拒否されました（${refund.failure_reason ?? '理由のコードなし'}）。Stripe のダッシュボードで確認してください。`;
        }

        return refund?.status === 'succeeded'
            ? `送料の差額 ${money(refund.amount)} を Stripe で返金しました。`
            : '送料の差額の返金を Stripe に依頼しました（確定すると自動で反映されます）。';
    });
}

// 取消（お金が動くので、パスキーでもう一度確認する）
function cancel() {
    // 通知は返金の答えで変える（保留なら「手続き中」。ADR 0027）
    return operate('cancel', 'cancel', {}, (result) => {
        const refund = [...(result.transaction.refunds ?? [])].reverse().find((r) => r.purpose === 'cancel');

        if (!refund) {
            return '注文を取り消しました。在庫を戻しました。';
        }

        return refund.status === 'pending'
            ? `注文を取り消しました。${money(refund.amount)} の返金は Stripe で手続き中です（確定すると自動で反映されます）。在庫を戻しました。`
            : `注文を取り消し、${money(refund.amount)} を Stripe で返金しました。在庫を戻しました。`;
    });
}

// 返金の記録の状態（ADR 0027）
const REFUND_STATES = {
    requested: { tone: 'warn', label: '処理中（Stripe の応答待ち）' },
    pending: { tone: 'warn', label: '手続き中' },
    succeeded: { tone: 'ok', label: '返金済み' },
    failed: { tone: 'danger', label: '失敗' },
    reversed: { tone: 'danger', label: '返金後に取り消された（要対応）' },
    held: { tone: 'danger', label: '金額が違う（要対応）' },
};
const REFUND_PURPOSES = { cancel: '取消の返金', stray: '返金が必要な支払いの返金', external: 'Stripe の画面で行った返金', shipping: '贈り物の送料の差額', return: '返品・一部返金' };

// 断られた返金（返品・一部返金と、取り消した注文の取消の返金）を頼み直す（ADR 0048 の 3。新しい冪等キー。お金が動くので、パスキーでもう一度確認する）
function retryRefund(refund, channel) {
    return operate(`retry-${refund.id}`, `refunds/${refund.id}/retry`, { channel }, (result) => {
        const latest = [...(result.transaction.refunds ?? [])].reverse().find((r) => r.purpose === refund.purpose);

        if (channel === 'outside') {
            return `${money(refund.amount)} を Stripe 以外で返金したと記録しました。`;
        }

        if (latest?.status === 'failed') {
            return `Stripe で、再び返金が拒否されました（${latest.failure_reason ?? '理由のコードなし'}）。Stripe のダッシュボードで確認してください。`;
        }

        return latest?.status === 'succeeded' ? `${money(refund.amount)} を Stripe で返金しました。` : '返金を Stripe に依頼しました（確定すると自動で反映されます）。';
    });
}

// 止めた返金は理由で言い分ける（額が違う・Stripe の画面で直接した返金。ADR 0027）
function refundState(refund) {
    if (refund.resolution === 'recorded') {
        return { tone: 'ok', label: '確認済み（記録）' };
    }

    if (refund.resolution === 'dismissed') {
        return { tone: 'neutral', label: '返金なし' };
    }

    if (refund.resolution === 'retried') {
        return { tone: 'neutral', label: '失敗（再依頼済み）' };
    }

    if (refund.channel === 'outside') {
        return { tone: 'ok', label: 'Stripe 以外で返金（記録）' };
    }

    if (refund.status === 'held' && refund.failure_reason === 'external_refund') {
        return { tone: 'danger', label: '記録との照合が必要（要確認）' };
    }

    return REFUND_STATES[refund.status] ?? { tone: 'neutral', label: refund.status };
}

function complete() {
    return operate('complete', 'complete', {}, '完了にしました（お届け済み）。在庫を確定しました。');
}

// サービス（役務）だけの注文は発送しない。作業を終えたら「提供済みにする」で完了まで進める
function deliverService() {
    return operate('deliver-service', 'deliver-service', {}, '提供済みにしました。注文は完了です。');
}

// 銀行振込・代金引換（ADR 0074）。入金の確認・受取拒否・返金の記録はお金が動くので、パスキーでもう一度確認する
const directForm = reactive({ amount: '', acceptDifference: false, restock: true, refundAmount: '' });
const isCod = computed(() => detail.value?.payment?.method === 'cod');
const receivedAmount = computed(() => Number.parseInt(String(directForm.amount === '' ? detail.value?.total_amount ?? '' : directForm.amount), 10));
const amountDiffers = computed(() => Number.isSafeInteger(receivedAmount.value) && receivedAmount.value !== detail.value?.total_amount);

function confirmDirect() {
    const amount = receivedAmount.value;

    return operate('confirm-payment', 'direct-payment/confirm', { amount: Number.isNaN(amount) ? null : amount, accept_difference: directForm.acceptDifference },
        () => {
            Object.assign(directForm, { amount: '', acceptDifference: false });

            return isCod.value ? '代引きの入金を記録しました。' : '入金を記録しました。注文は「入金済み」になり、お客さんへご注文の確認のメールを送ります。';
        });
}

function refuseCod() {
    return operate('refuse-cod', 'cod/refuse', { restock: directForm.restock },
        directForm.restock ? '受取拒否で取り消しました。在庫を戻しました。' : '受取拒否で取り消しました（在庫には戻していません）。');
}

function recordDirectRefund() {
    const amount = Number.parseInt(String(directForm.refundAmount), 10);

    return operate('direct-refund', 'direct-refunds', { amount: Number.isNaN(amount) ? null : amount }, () => {
        directForm.refundAmount = '';

        return `${money(amount)} の返金を記録しました。`;
    });
}

// 返金の種類の名前（銀行振込・代引きの注文の外でした返金は、店の人が記録した返金。ADR 0074）
function refundPurpose(refund) {
    return refund.purpose === 'external' && detail.value?.payment?.direct ? '店が記録した返金' : (REFUND_PURPOSES[refund.purpose] ?? refund.purpose);
}

async function showPersonal() {
    personalBusy.value = true;
    personalError.value = null;

    try {
        personal.value = (await api('GET', `${base.value}/transactions/${encodeURIComponent(props.transaction)}/personal`)).personal;
    } catch (e) {
        personalError.value = describe(e);
    } finally {
        personalBusy.value = false;
    }
}

function addressText(address) {
    return address ? Object.values(address).filter(Boolean).join(' ') : null;
}
</script>

<template>
    <PageHeader :help="helpLink('transaction', tenant)" :title="`注文 ${shortId(transaction)}`" :crumbs="crumbs">
        <template v-if="detail" #actions>
            <StatusBadge v-if="detail.order_status" :tone="ORDER_STATUS_TONES[detail.order_status]" data-test="order-status">{{ ORDER_STATUS_LABELS[detail.order_status] ?? detail.order_status }}</StatusBadge>
            <StatusBadge :tone="TRANSACTION_STATUS_TONES[detail.status]" data-test="transaction-status">{{ TRANSACTION_STATUS_LABELS[detail.status] ?? detail.status }}</StatusBadge>
            <StatusBadge v-if="detail.refund_summary?.state" :tone="REFUND_STATE_TONES[detail.refund_summary.state]" data-test="refund-summary-state">{{ REFUND_STATE_LABELS[detail.refund_summary.state] }}（{{ money(detail.refund_summary.refunded) }}）</StatusBadge>
            <StatusBadge v-if="detail.payment?.test" tone="warn" data-test="test-payment-status">テストの支払い</StatusBadge>
            <StatusBadge v-if="detail.age_check" tone="accent" data-test="age-check-status">{{ detail.age_check.label }}{{ detail.age_check.recipient ? '・受け取る方も' : '' }}</StatusBadge>
            <StatusBadge v-if="detail.gift" :tone="GIFT_TONES[detail.gift.status] ?? 'neutral'" data-test="gift-status">{{ detail.gift.label }}{{ detail.gift.expires_on ? `（期限 ${detail.gift.expires_on}）` : '' }}</StatusBadge>
            <AppButton v-for="doc in documentLinks" :key="`${doc.plugin}-${doc.key}`" size="sm" :href="doc.href" :data-test="`order-document-${doc.key}`"><AppIcon name="file" />{{ doc.label }}</AppButton>
        </template>
    </PageHeader>
    <ErrorState v-if="error" :error="error" />
    <LoadingText v-else-if="detail === null" />

    <template v-else>
        <AppNotice v-if="detail.payment?.test" :live="false" data-test="test-payment-notice">
            Stripe のテストモードで支払われた注文です（公開前のテスト）。本物のお金は動いていません。商品は発送せず、確認が終わったら取り消してください（取り消すとテストモードで返金し、在庫を元に戻します）。
        </AppNotice>
        <AppNotice v-if="detail.payment?.awaiting && detail.payment?.method === 'bank_transfer'" data-test="bank-transfer-notice">
            銀行振込のお振込みを待っています（期限 {{ formatDate(detail.payment.pay_by) }}）。入金を確認できたら「入金を確認した」を押すと「入金済み」になり、発送できます。期限を過ぎて 1 日たっても確認できないときは、注文を自動で取り消し、在庫・クーポン・ポイントを元に戻します。
        </AppNotice>
        <AppNotice v-else-if="detail.payment?.on_delivery" data-test="cod-notice">
            代金引換の注文です。代金を受け取る前に発送します。運送会社から代金が入金されたら「代引きの入金を確認した」を押します。お客さんが受け取らなかったときは「受取拒否で取り消す」を押します。
        </AppNotice>
        <AppNotice v-else-if="detail.payment?.awaiting" data-test="payment-awaiting-notice">
            コンビニでのお支払いを待っています（期限 {{ formatDate(detail.payment.pay_by) }}）。支払われると「入金済み」になります。期限までに支払われないときは、注文を自動で取り消し、在庫とクーポンの使用回数を元に戻します。注文を取り消すと、お客さんの払込票も使えなくなります。
        </AppNotice>
        <AppNotice v-if="detail.gift?.status === 'waiting'" data-test="gift-notice">
            贈り物の注文です。贈った相手が受け取り用のリンクからお届け先を入力するまで、発送できません（期限 {{ detail.gift.expires_on }}。期限を過ぎると、自動で取り消して全額を返金します）。取り消すと全額を返金し、受け取り用のリンクも使えなくなります。
        </AppNotice>
        <AppNotice v-else-if="detail.gift?.status === 'stopped'" tone="error" data-test="gift-notice">
            贈り物の取り消しで返金ができなかったため、処理を止めています。下の「返金の記録」を確認し、もう一度「注文を取り消す」を押してください（同じ返金の続きから進みます）。
        </AppNotice>
        <AppPanel v-if="(canInTenant(tenant, 'operate') && (detail.actions?.ship || detail.actions?.complete || detail.actions?.deliver_service)) || (canInTenant(tenant, 'money') && (detail.actions?.cancel || detail.actions?.confirm_payment || detail.actions?.refuse_cod || detail.actions?.record_refund)) || operationMessage" title="注文の操作" description="発送を登録すると、お客さんの注文は「発送済み」になります。お届けが済んだら「完了にする」で在庫を確定します。" data-test="order-actions">
            <AppNotice v-if="operationMessage" tone="success">{{ operationMessage }}</AppNotice>
            <AppNotice v-if="operationError" tone="error">{{ operationError }}</AppNotice>

            <InsetSection v-if="detail.actions?.confirm_payment && canInTenant(tenant, 'money')" :title="isCod ? '代引きの入金を確認した' : '入金を確認した'" :description="isCod ? '運送会社から入金された額を入力します。注文の額と違うときは、差額をお客さん・運送会社と調整したうえで、注文の額で入金済みにします。' : '口座に入金された額を入力します。注文の額と違うときは、過不足をお客さんと直接やり取りして調整したうえで、注文の額で入金済みにします。'" data-test="confirm-payment">
                <div class="flex flex-col gap-4 md:flex-row md:items-end">
                    <AppField label="受け取った額（円）" :error="firstError(operationErrors, 'amount')">
                        <AppInput :model-value="directForm.amount === '' ? String(detail.total_amount) : directForm.amount" inputmode="numeric" autocomplete="off" maxlength="9" data-test="confirm-payment-amount" @update:model-value="(value) => (directForm.amount = value)" />
                    </AppField>
                    <AppCheckbox v-if="amountDiffers" v-model="directForm.acceptDifference" :label="`注文の額（${money(detail.total_amount)}）との差額を確認した`" test="confirm-payment-accept" />
                    <FormActions>
                        <ConfirmButton :label="isCod ? '代引きの入金を確認した' : '入金を確認した'" confirm-label="入金済みにする" variant="primary" size="md" :disabled="operating !== null" :loading="operating === 'confirm-payment'" test="confirm-payment" @confirm="confirmDirect">
                            {{ money(detail.total_amount) }} の入金として記録します。{{ isCod ? '' : 'お客さんへご注文の確認のメールを送り、発送できるようになります。' }}元に戻せません。
                        </ConfirmButton>
                    </FormActions>
                </div>
            </InsetSection>

            <InsetSection v-if="detail.actions?.refuse_cod && canInTenant(tenant, 'money')" title="受取拒否で取り消す" description="お客さんが代引きの荷物を受け取らなかったときに押します。注文を取り消し、使われたクーポンとポイントを元に戻します。戻ってきた商品をまた販売できるなら、在庫に戻します。" data-test="refuse-cod">
                <div class="flex flex-col gap-4 md:flex-row md:items-end">
                    <AppCheckbox v-model="directForm.restock" label="戻ってきた商品を在庫に戻す" test="refuse-cod-restock" />
                    <FormActions>
                        <ConfirmButton label="受取拒否で取り消す" confirm-label="取り消す" size="md" :disabled="operating !== null" :loading="operating === 'refuse-cod'" test="refuse-cod" @confirm="refuseCod">
                            受取拒否で注文を取り消します。{{ directForm.restock ? '商品は在庫に戻します。' : '商品は在庫に戻しません（破損・不良として在庫から減らします）。' }}元に戻せません。
                        </ConfirmButton>
                    </FormActions>
                </div>
            </InsetSection>

            <InsetSection v-if="detail.actions?.record_refund && canInTenant(tenant, 'money')" title="返金を記録する" :description="`銀行振込・代金引換で受け取った代金は、この画面から返金できません。お客さんの口座へお店から振り込んでから、返金した額を記録します（受け取った代金のうち、まだ返金していない額は ${money(detail.direct_refundable)}）。全額を返金した後に「注文を取り消す」で取り消せます。`" data-test="direct-refund">
                <div class="flex flex-col gap-4 md:flex-row md:items-end">
                    <AppField label="返金した額（円）" :error="firstError(operationErrors, 'amount')">
                        <AppInput v-model="directForm.refundAmount" inputmode="numeric" autocomplete="off" maxlength="9" data-test="direct-refund-amount" />
                    </AppField>
                    <FormActions>
                        <ConfirmButton label="返金を記録する" :confirm-label="`${money(Number.parseInt(directForm.refundAmount, 10) || 0)} の返金を記録する`" variant="primary" size="md" :disabled="operating !== null" :loading="operating === 'direct-refund'" test="direct-refund" @confirm="recordDirectRefund">
                            お客さんへの振込が済んでいますか？　記録すると元に戻せません。
                        </ConfirmButton>
                    </FormActions>
                </div>
            </InsetSection>

            <form v-if="detail.actions?.ship && canInTenant(tenant, 'operate')" class="flex flex-col gap-5" novalidate data-test="ship-form" @submit.prevent="ship">
                <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
                    <AppField v-if="pendingFulfillments.length > 1" label="発送する荷物" :error="firstError(operationErrors, 'fulfillment_id')">
                        <AppSelect v-model="shipForm.fulfillment_id" data-test="ship-fulfillment">
                            <option v-for="f in pendingFulfillments" :key="f.id" :value="f.id">{{ f.location ?? '拠点なし' }}{{ f.shipping_method ? `・${f.shipping_method}` : '' }}（{{ shortId(f.id) }}）</option>
                        </AppSelect>
                    </AppField>
                    <AppField label="運送会社" optional :error="firstError(operationErrors, 'carrier')">
                        <AppSelect v-model="shipForm.carrier" data-test="ship-carrier">
                            <option value="">選ばない</option>
                            <option v-for="(name, id) in detail.carriers" :key="id" :value="id">{{ name }}</option>
                        </AppSelect>
                    </AppField>
                    <AppField label="お問い合わせ番号（追跡番号）" optional hint="運送会社の番号。英数字とハイフン（お客さんへの通知に使います）" :error="firstError(operationErrors, 'tracking_number')">
                        <AppInput v-model="shipForm.tracking_number" inputmode="latin" autocomplete="off" maxlength="64" data-test="ship-tracking" />
                    </AppField>
                </div>
                <FormActions>
                    <AppButton type="submit" variant="primary" :disabled="operating !== null" :loading="operating === 'ship'" data-test="ship">発送を登録する</AppButton>
                </FormActions>
            </form>

            <FormActions v-if="detail.actions?.deliver_service && canInTenant(tenant, 'operate')">
                <ConfirmButton label="提供済みにする" confirm-label="提供済みにする" variant="primary" size="md" :disabled="operating !== null" :loading="operating === 'deliver-service'" test="deliver-service" @confirm="deliverService">
                    サービスの作業は済みましたか？　提供済みにすると注文は完了になり、元に戻せません。
                </ConfirmButton>
            </FormActions>

            <FormActions v-if="detail.actions?.complete && canInTenant(tenant, 'operate')">
                <ConfirmButton label="完了にする（お届け済み）" confirm-label="完了にする" variant="primary" size="md" :disabled="operating !== null" :loading="operating === 'complete'" test="complete" @confirm="complete">
                    お届けが済んだ注文ですか？　完了にすると在庫を確定し、元に戻せません。
                </ConfirmButton>
            </FormActions>

            <FormActions v-if="detail.actions?.cancel && canInTenant(tenant, 'money')">
                <ConfirmButton label="注文を取り消す" :confirm-label="detail.refundable_amount > 0 ? '返金して取り消す' : '取り消す'" size="md" :disabled="operating !== null" :loading="operating === 'cancel'" test="cancel-order" confirm-test="confirm-cancel" @confirm="cancel">
                    <span data-test="cancel-confirm-text">{{ detail.refundable_amount > 0 ? `${money(detail.refundable_amount)} を Stripe でお客さんに返金し、注文を取り消します。` : (detail.payment?.direct ? '注文を取り消します（受け取ったお金があれば、先に返金を記録してください）。' : 'まだ支払われていない注文を取り消します（お客さんの支払いの画面も使えなくなります）。') }}元に戻せません。</span>
                </ConfirmButton>
            </FormActions>
        </AppPanel>

        <AppPanel v-if="detail.refunds?.length > 0" title="返金の記録" description="Stripe に依頼した返金です。「手続き中」は Stripe での確定待ちで、確定すると自動で反映されます。「処理中」は Stripe からの応答を確認できなかったもので、「注文を取り消す」をもう一度押すか、10 分ごとの自動の再確認で続きから進みます（二重には返金されません）。「失敗」「返金後に取り消された」はお客さんに返金されていないので、Stripe のダッシュボードで確認してください。「金額が違う」は、Stripe の返金額が依頼した額と違ったため止めています（発送も取消もできません）。Stripe のダッシュボードで返金を確認してください。" data-test="refunds">
            <AppTable label="返金の記録">
                <thead>
                    <tr><th>種類</th><th class="text-right">金額</th><th>状態</th><th>理由</th><th>依頼した日時</th><th>確定した日時</th></tr>
                </thead>
                <tbody>
                    <tr v-for="refund in detail.refunds" :key="refund.id" data-test="refund-row">
                        <td>{{ refundPurpose(refund) }}</td>
                        <NumCell :value="money(refund.amount)" />
                        <td><StatusBadge :tone="refundState(refund).tone" data-test="refund-state">{{ refundState(refund).label }}</StatusBadge></td>
                        <td><AppText as="span" tone="soft" size="xs" mono>{{ refund.failure_reason ?? '—' }}</AppText></td>
                        <td><AppText as="span" tone="muted" nowrap>{{ formatDate(refund.created_at) }}</AppText></td>
                        <td><AppText as="span" tone="muted" nowrap>{{ formatDate(refund.settled_at) }}</AppText></td>
                    </tr>
                </tbody>
            </AppTable>

            <InsetSection v-for="refund in detail.refunds.filter((r) => r.resolvable && canInTenant(tenant, 'money'))" :key="`resolve-${refund.id}`" :title="`止めている返金を確定する（${REFUND_PURPOSES[refund.purpose] ?? refund.purpose}）`" description="Stripe のダッシュボードでこの返金を確認してから選びます。返金があれば、その額で記録します（会計の記録に返金を記入し、取消の返金なら注文も取り消します）。返金が無ければ「返金は無かった」を押して閉じます。" data-test="resolve-refund">
                <div class="flex flex-col gap-4 md:flex-row md:items-end">
                    <AppField label="Stripe で確認した返金の額（円）" :error="firstError(operationErrors, 'amount')">
                        <AppInput :model-value="resolveAmount(refund)" inputmode="numeric" autocomplete="off" maxlength="9" data-test="resolve-amount" @update:model-value="(value) => (resolveAmounts[refund.id] = value)" />
                    </AppField>
                    <FormActions>
                        <ConfirmButton label="この額で記録する" :confirm-label="`${money(Number.parseInt(resolveAmount(refund), 10) || 0)} の返金を記録する`" variant="primary" size="md" :disabled="operating !== null" :loading="operating === `resolve-${refund.id}`" test="resolve-recorded" @confirm="resolveRefund(refund, 'recorded')">
                            Stripe でこの額の返金があることを確認しましたか？　記録すると元に戻せません。
                        </ConfirmButton>
                        <ConfirmButton label="返金は無かった" confirm-label="返金は無かったとして閉じる" size="md" :disabled="operating !== null" :loading="operating === `resolve-${refund.id}`" test="resolve-dismissed" @confirm="resolveRefund(refund, 'dismissed')">
                            Stripe にこの返金が無いことを確認しましたか？{{ refund.purpose === 'cancel' ? '　注文は取り消さずに残ります（取消をもう一度押すと、新しく返金を依頼します）。' : '' }}
                        </ConfirmButton>
                    </FormActions>
                </div>
            </InsetSection>
            <InsetSection v-for="refund in detail.refunds.filter((r) => r.retryable && canInTenant(tenant, 'money'))" :key="`retry-${refund.id}`" :title="`返金をやり直す（${REFUND_PURPOSES[refund.purpose] ?? refund.purpose}・${money(refund.amount)}）`" description="Stripe で返金が拒否されました（お客さんに返金されていません）。Stripe のダッシュボードで理由を確認してから、もう一度依頼してください。返品の返金を振込など Stripe 以外の方法でお店から返したときは、「Stripe 以外で返金した（記録だけ）」を選べます。" data-test="retry-refund">
                <FormActions>
                    <ConfirmButton label="Stripe でもう一度返金する" :confirm-label="`${money(refund.amount)} をもう一度返金する`" variant="primary" size="md" :disabled="operating !== null" :loading="operating === `retry-${refund.id}`" test="retry-refund-provider" @confirm="retryRefund(refund, 'provider')">
                        {{ money(refund.amount) }} を Stripe でお客さんにもう一度返金します（拒否された記録は「失敗（再依頼済み）」として残ります）。
                    </ConfirmButton>
                    <ConfirmButton v-if="refund.purpose === 'return'" label="Stripe 以外で返金した（記録だけ）" :confirm-label="`${money(refund.amount)} を返金したと記録する`" size="md" :disabled="operating !== null" :loading="operating === `retry-${refund.id}`" test="retry-refund-outside" @confirm="retryRefund(refund, 'outside')">
                        お店から {{ money(refund.amount) }} を返金しましたか？　Stripe では処理せず、返金として記録だけします。
                    </ConfirmButton>
                </FormActions>
            </InsetSection>
            <InsetSection v-if="detail.actions?.release_gift_card && canInTenant(tenant, 'money')" title="ギフトカードへ戻す" :description="`この注文でギフトカード（末尾 ${detail.gift_card.last4}）から支払われた ${money(detail.gift_card.releasable)} を、カードの残高に戻せます。Stripe の画面で返金した時などに、戻す額を入力します（自動では戻りません。1 回だけ）。押すとパスキーで本人確認をします。`" data-test="release-gift-card">
                <form class="flex flex-col gap-4" @submit.prevent="releaseGiftCard">
                    <AppField label="カードへ戻す額（円）" :error="firstError(operationErrors, 'amount')">
                        <AppInput v-model="giftCardRelease" type="number" min="1" :max="detail.gift_card.releasable" step="1" inputmode="numeric" required data-test="release-gift-card-amount" />
                    </AppField>
                    <FormActions>
                        <AppButton type="submit" variant="primary" :disabled="operating !== null" :loading="operating === 'release-gift-card'" data-test="release-gift-card-save">ギフトカードへ戻す</AppButton>
                    </FormActions>
                </form>
            </InsetSection>
            <InsetSection v-if="detail.actions?.retry_shipping_refund && canInTenant(tenant, 'money')" title="送料の差額をもう一度返金する" description="贈り物の送料の差額の返金が Stripe で拒否されました。Stripe のダッシュボードで理由を確認し、返金できる状態にしてから、もう一度依頼してください（拒否された記録は「失敗（再依頼済み）」として残ります）。注文を取り消すと、返金できていない差額も含めて全額を返金します。" data-test="retry-shipping-refund">
                <FormActions>
                    <ConfirmButton label="差額をもう一度返金する" confirm-label="もう一度返金する" variant="primary" size="md" :disabled="operating !== null" :loading="operating === 'retry-shipping-refund'" test="retry-shipping-refund" @confirm="retryShippingRefund">
                        送料の差額を Stripe でお客さんにもう一度返金します。
                    </ConfirmButton>
                </FormActions>
            </InsetSection>
        </AppPanel>

        <TransactionReturn :tenant="tenant" :transaction="detail" @updated="fill" />

        <!-- App の 1 件（ダウンロードの回数など）。個人情報は出さない。操作は App の画面で -->
        <TransactionApps v-if="detail.kind === 'sale'" :tenant="tenant" :transaction="transaction" />

        <AppPanel v-if="detail.stray_payments?.length > 0" title="返金が必要な支払い" description="注文の代金とは別に届いた支払いです（売上の記録には入れていません）。お客さんに返金してください。" data-test="stray-payments">
            <AppTable label="返金が必要な支払い">
                <thead>
                    <tr><th>理由</th><th class="text-right">金額</th><th>受け取った日</th><th>状態</th><th><span class="sr-only">操作</span></th></tr>
                </thead>
                <tbody>
                    <tr v-for="stray in detail.stray_payments" :key="stray.id" data-test="stray-row">
                        <td>{{ STRAY_REASONS[stray.reason] ?? stray.reason }}</td>
                        <NumCell :value="money(stray.amount)" />
                        <td><AppText as="span" tone="muted" nowrap>{{ formatDate(stray.created_at) }}</AppText></td>
                        <td>
                            <StatusBadge :tone="stray.status === 'refunded' ? 'ok' : 'warn'">{{ stray.status === 'refunded' ? '返金済み' : '要返金' }}</StatusBadge>
                        </td>
                        <td>
                            <ConfirmButton v-if="stray.status === 'pending' && stray.refundable && canInTenant(tenant, 'money')" label="返金する" :confirm-label="`${money(stray.amount)} を返金する`" :disabled="operating !== null" :loading="operating === `stray-${stray.id}`" test="refund-stray" @confirm="refundStray(stray)" />
                            <AppText v-else-if="stray.status === 'pending'" as="span" tone="soft">Stripe のダッシュボードで返金</AppText>
                        </td>
                    </tr>
                </tbody>
            </AppTable>
        </AppPanel>

        <AppPanel title="概要">
            <StepPath :steps="steps" :current="detail.phase" label="注文の進み具合" />
            <AppNotice v-if="crossCutting" tone="warning">
                この注文は「{{ PHASE_LABELS[crossCutting] ?? crossCutting }}」の段階にあります<template v-if="detail.suspended_phase">（{{ PHASE_LABELS[detail.suspended_phase] }}から）</template>。
            </AppNotice>
            <DescriptionList :items="summary" />
        </AppPanel>

        <AppPanel title="明細">
            <AppTable label="明細">
                <thead>
                    <tr><th>内容</th><th class="num">数量</th><th class="num">単価</th><th class="num">税率</th><th class="num">金額</th></tr>
                </thead>
                <tbody>
                    <tr v-for="line in detail.lines" :key="line.id" data-test="line-row">
                        <td><CellTitle :label="line.description" :sub="line.line_type === 'adjustment' ? `調整（${line.kind ?? '—'}）` : line.location" /></td>
                        <NumCell :value="line.quantity" />
                        <NumCell :value="money(line.unit_amount)" soft />
                        <NumCell :value="formatRate(line.tax_rate_bp)" soft />
                        <NumCell :value="money(line.amount)" strong />
                    </tr>
                </tbody>
            </AppTable>
            <AmountSummary :rows="[
                { label: detail.prices_include_tax ? '小計（税抜き）' : '小計', value: money(detail.subtotal_amount) },
                { label: '消費税', value: money(detail.tax_amount) },
                { label: '合計', value: money(detail.total_amount) },
                // ギフトカード（ADR 0076）: 支払いの一部。合計は変えず、お支払いの額が減る
                ...(detail.gift_card ? [
                    { label: `ギフトカード（末尾 ${detail.gift_card.last4}）`, value: `−${money(detail.gift_card.amount)}` },
                    { label: 'お支払い', value: money(detail.gift_card.due) },
                    ...(detail.gift_card.released > 0 ? [{ label: 'カードへ戻した額', value: money(detail.gift_card.released) }] : []),
                ] : []),
                ...(detail.tax_by_rate ?? []).map((r) => ({ label: `${formatRate(r.rate_bp)}${r.reduced ? '（軽減税率）' : ''} 対象`, value: `${money(r.gross)}（内消費税 ${money(r.tax)}）` })),
            ]" />
        </AppPanel>

        <AppPanel v-if="detail.quotes.length > 0" title="見積もり・査定">
            <AppTable label="見積もり・査定">
                <thead>
                    <tr><th>販売者</th><th>状態</th><th>期限</th><th>決まった日時</th><th class="num">合計</th></tr>
                </thead>
                <tbody>
                    <tr v-for="quote in detail.quotes" :key="quote.id">
                        <td><CellTitle :label="quote.seller.name ?? '—'" /></td>
                        <td><AppText as="span" tone="muted">{{ QUOTE_STATUS_LABELS[quote.status] ?? quote.status }}</AppText></td>
                        <td><AppText as="span" tone="muted" nowrap>{{ formatDate(quote.valid_until) }}</AppText></td>
                        <td><AppText as="span" tone="muted" nowrap>{{ formatDate(quote.decided_at) }}</AppText></td>
                        <NumCell :value="money(quote.total_amount)" strong />
                    </tr>
                </tbody>
            </AppTable>
        </AppPanel>

        <TransactionLedger :journals="detail.journals" :balances="detail.balances" :currency="detail.currency" />

        <AppPanel v-if="detail.fulfillments.length > 0" title="配送">
            <AppTable label="配送">
                <thead>
                    <tr><th>方法</th><th>状態</th><th>拠点</th><th>お届けの希望</th><th>運送会社</th><th>発送</th><th>完了</th></tr>
                </thead>
                <tbody>
                    <tr v-for="fulfillment in detail.fulfillments" :key="fulfillment.id">
                        <td><CellTitle :label="fulfillment.shipping_method ?? FULFILLMENT_METHOD_LABELS[fulfillment.method] ?? fulfillment.method" :sub="fulfillment.has_address ? 'お届け先あり（個人情報）' : null" data-test="fulfillment-method" /></td>
                        <td><AppText as="span" tone="muted">{{ FULFILLMENT_STATUS_LABELS[fulfillment.status] ?? fulfillment.status }}</AppText></td>
                        <td><AppText as="span" tone="muted">{{ fulfillment.location ?? '—' }}</AppText></td>
                        <!-- お客さんが注文の入力で選んだお届けの日と時間帯（ADR 0059）。送り状に写す -->
                        <!-- 予約の品のある注文（ADR 0073）は、発売日（発送を始める日）を添える。その日まで発送できない -->
                        <td data-test="fulfillment-delivery">
                            <AppText as="span" :tone="fulfillment.delivery ? 'default' : 'muted'">{{ fulfillment.delivery?.label ?? '指定なし' }}</AppText>
                            <AppText v-if="fulfillment.ships_on" size="sm" tone="muted" data-test="fulfillment-ships-on">{{ formatDay(fulfillment.ships_on) }} から発送（予約販売の商品の発売日）</AppText>
                        </td>
                        <td><AppText as="span" tone="muted">{{ detail.carriers?.[fulfillment.carrier] ?? fulfillment.carrier ?? '—' }}</AppText></td>
                        <td><AppText as="span" tone="muted" nowrap>{{ formatDate(fulfillment.shipped_at) }}</AppText></td>
                        <td><AppText as="span" tone="muted" nowrap>{{ formatDate(fulfillment.completed_at) }}</AppText></td>
                    </tr>
                </tbody>
            </AppTable>
        </AppPanel>

        <!-- 注文の項目（ADR 0066。注文の時の欄の名前で）。自由な文は個人情報を含みうるので、下の「個人情報」でだけ見せる -->
        <AppPanel v-if="detail.order_fields?.length > 0" title="注文の項目" description="お客さんが注文の入力で回答した項目です（設定の「注文の項目」で追加した欄）。自由記述の回答は、名前や事情が書かれることがあるので、「個人情報」を表示したときだけ表示されます。" data-test="order-fields">
            <DescriptionList :items="orderFieldItems" />
        </AppPanel>

        <AppPanel v-if="detail.sent_mails?.length > 0" title="メールの送信の履歴" description="お客さんへ送った注文の通知です。宛先は保存していません（送信するときにお客さんの情報から読み込みます）。" data-test="sent-mails">
            <AppTable label="メールの送信の履歴">
                <thead>
                    <tr><th>件名</th><th>状態</th><th>送った日時</th></tr>
                </thead>
                <tbody>
                    <tr v-for="mail in detail.sent_mails" :key="`${mail.kind}-${mail.subject}`" data-test="sent-mail-row">
                        <td>{{ mail.subject }}</td>
                        <td><StatusBadge :tone="mail.status === 'sent' ? 'ok' : 'warn'">{{ mail.status === 'sent' ? '送信済み' : '送信中' }}</StatusBadge></td>
                        <td><AppText as="span" tone="muted" nowrap>{{ formatDate(mail.sent_at) }}</AppText></td>
                    </tr>
                </tbody>
            </AppTable>
        </AppPanel>

        <AppPanel title="個人情報" description="顧客の名前・連絡先・住所と、お届け先・追跡番号、名入れなどのお客さんが入力した文字、注文の項目の自由記述の回答です。表示すると、誰がいつ見たかが記録に残ります。">
            <template #actions>
                <AppText v-if="!canInTenant(tenant, 'operate')" tone="soft">個人情報を表示できるのは、「運用」以上の権限のある人だけです。</AppText>
                <AppButton v-else-if="personal === null" :disabled="personalBusy" data-test="show-personal" @click="showPersonal">
                    <AppIcon name="key" />個人情報を表示する
                </AppButton>
                <AppButton v-else variant="ghost" data-test="hide-personal" @click="personal = null">隠す</AppButton>
            </template>
            <AppNotice v-if="personalError" tone="error">{{ personalError }}</AppNotice>
            <template v-if="personal">
                <AppText v-if="personal.customer === null" tone="soft">この注文に顧客はいません。</AppText>
                <DescriptionList
                    v-else
                    data-test="personal-customer"
                    :items="[
                        { label: '名前', value: personal.customer.name },
                        { label: 'メール', value: personal.customer.email },
                        { label: '電話', value: personal.customer.phone },
                        { label: '住所', value: addressText(personal.customer.address) },
                    ]"
                />
                <DescriptionList
                    v-for="(fulfillment, index) in personal.fulfillments"
                    :key="fulfillment.id"
                    :items="[
                        { label: `お届け先 ${index + 1}`, value: addressText(fulfillment.address) },
                        { label: '追跡番号', value: fulfillment.tracking_number, mono: true },
                    ]"
                />
                <!-- オプションの入れた文字（名入れなど。ADR 0036）。人の名前になりうるので、ここ（見た記録が残る）でだけ見せる -->
                <InsetSection v-if="personal.addon_texts?.length > 0" title="名入れなど、お客さんが入力した文字" data-test="personal-addon-texts">
                    <DescriptionList :items="addonTextItems" />
                </InsetSection>
                <!-- 注文の項目の文の答え（ご要望など。ADR 0066）。名前や事情が書かれうるので、ここ（見た記録が残る）でだけ見せる -->
                <InsetSection v-if="personal.order_fields?.length > 0" title="注文の項目の自由記述の回答" data-test="personal-order-fields">
                    <DescriptionList :items="personal.order_fields.map((f) => ({ label: f.name, value: f.value }))" />
                </InsetSection>
                <!-- App の料金の備考（ラッピング・のしと名入れ。ADR 0078）。名入れは人の名前になりうるので、ここ（見た記録が残る）でだけ見せる -->
                <InsetSection v-if="personal.charge_notes?.length > 0" title="ラッピング・のし" data-test="personal-charge-notes">
                    <DescriptionList :items="personal.charge_notes.map((n) => ({ label: n.name, value: n.value }))" />
                </InsetSection>
            </template>
        </AppPanel>

        <TransactionEvents :events="detail.events" :verify-path="`${base}/events/verify`" />
    </template>
</template>
