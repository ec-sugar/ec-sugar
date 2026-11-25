<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { api, describe } from '../../api.js';
import { errorsOf, firstError, formatAmount, formatDate, formatRate } from '../../format.js';
import { useOperation } from '../../operation.js';
import { canInTenant } from '../../tenants.js';
import AmountSummary from '../../components/ui/AmountSummary.vue';
import AppButton from '../../components/ui/AppButton.vue';
import AppCheckbox from '../../components/ui/AppCheckbox.vue';
import AppField from '../../components/ui/AppField.vue';
import AppInput from '../../components/ui/AppInput.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppSelect from '../../components/ui/AppSelect.vue';
import AppTable from '../../components/ui/AppTable.vue';
import AppText from '../../components/ui/AppText.vue';
import CellTitle from '../../components/ui/CellTitle.vue';
import ConfirmButton from '../../components/ui/ConfirmButton.vue';
import FormActions from '../../components/ui/FormActions.vue';
import InsetSection from '../../components/ui/InsetSection.vue';
import NumCell from '../../components/ui/NumCell.vue';
import StatusBadge from '../../components/ui/StatusBadge.vue';

// 返品・一部返金・済んだ注文の返金（ADR 0048）。行と数・送料・オプション・在庫に戻すか・金額だけ・理由・返し方を選ぶ →
// 確認の画面（返す額の内訳・税率ごと）→ 実行（お金の段階。パスキーでもう一度確認する）。額はサーバーが明細から決め、実行の時に確認した合計と照らす。
// 品の返す額の既定は「品の額と返せる残りの小さい方」で、店の人は 0 から既定までに下げられる（0 なら品だけ在庫に戻す）。
// 返金の履歴（日時・額・状態・誰が）は誰でも見られる（お金の段階でなくても）
const props = defineProps({
    tenant: { type: String, required: true },
    transaction: { type: Object, required: true },
});
const emit = defineEmits(['updated']);

const { pending, error, message, run } = useOperation();
const preview = ref(null);
const previewError = ref(null);
const previewing = ref(false);
const form = reactive({ items: {}, shipping: false, amount: '', goods: '', reason: 'returned', channel: 'provider' });

const returns = computed(() => props.transaction.returns ?? null);
const money = (amount) => formatAmount(amount, props.transaction.currency ?? 'JPY');
const canRefund = computed(() => canInTenant(props.tenant, 'money') && returns.value?.returnable);
const history = computed(() => returns.value?.history ?? []);
const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/transactions/${encodeURIComponent(props.transaction.id)}`);
const REFUND_STATES = { requested: '応答待ち', pending: '手続き中', held: '保留中（要確認）', succeeded: '返金済み', failed: '失敗', reversed: '返金後に取り消された（要対応）' };
const REFUND_TONES = { requested: 'warn', pending: 'warn', held: 'danger', succeeded: 'ok', failed: 'danger', reversed: 'danger' };
const LINE_KINDS = { item: '商品', addon: 'オプション', shipping: '送料', amount: '金額だけ' };

// 注文が変わったら選び直す（返せる数が変わる）
watch(() => props.transaction, () => {
    form.items = Object.fromEntries((returns.value?.items ?? []).map((item) => [item.position, { quantity: 0, restock: false, addons: item.addons.length > 0 }]));
    form.channel = returns.value?.provider ? 'provider' : 'outside';
    form.goods = '';
    preview.value = null;
}, { immediate: true });

// 空なら null（既定の額）。数でない入力もそのまま送って、サーバーの入力の検査で欄の下に出す
function goodsAmount() {
    const text = String(form.goods).trim();

    return text === '' ? null : (/^\d+$/.test(text) ? Number.parseInt(text, 10) : text);
}

function body() {
    const amount = Number.parseInt(String(form.amount).trim(), 10);

    return {
        items: (returns.value?.items ?? []).filter((item) => (form.items[item.position]?.quantity ?? 0) > 0).map((item) => ({
            position: item.position,
            quantity: form.items[item.position].quantity,
            restock: form.items[item.position].restock,
            addons: form.items[item.position].addons,
        })),
        shipping: form.shipping,
        amount: Number.isNaN(amount) ? 0 : amount,
        goods_amount: goodsAmount(),
        reason: form.reason,
        channel: form.channel,
    };
}

// 確認の画面（書き込まない）
async function check() {
    previewing.value = true;
    previewError.value = null;
    preview.value = null;

    try {
        preview.value = (await api('POST', `${base.value}/returns/preview`, body())).plan;
    } catch (e) {
        previewError.value = e;
    } finally {
        previewing.value = false;
    }
}

function refund() {
    const total = preview.value.total;
    const outside = form.channel === 'outside';

    return run('return', () => api('POST', `${base.value}/returns`, { ...body(), expected_total: total }), {
        then: (result) => emit('updated', result.transaction),
        done: (result) => {
            const latest = result.transaction.returns?.history?.[0]?.refund;

            if (total === 0) {
                return '返品を記録しました（返金額は 0 円のため、お金は動いていません）。';
            }

            if (outside) {
                return `${money(total)} を Stripe 以外で返金したと記録しました。`;
            }

            return latest?.status === 'pending' || latest?.status === 'requested'
                ? `${money(total)} の返金を Stripe に依頼しました（確定すると自動で反映されます）。`
                : `${money(total)} を Stripe で返金しました。お客さんに返金のお知らせのメールを送ります。`;
        },
    });
}

// 確認の画面の誤り: 欄ごとの誤りは欄の下に、ほかは 1 つの通知に
const fieldErrors = computed(() => (previewError.value ? errorsOf(previewError.value) : {}));
const previewMessage = computed(() => (previewError.value && Object.keys(fieldErrors.value).length === 0 ? describe(previewError.value) : null));
// 品の返す額の目安（確認した後に、品の額と上限〔＝既定〕を出す）
const goodsNote = computed(() => {
    const goods = preview.value?.goods;

    if (!goods || goods.full === 0) {
        return null;
    }

    return goods.limit < goods.full
        ? `商品の額 ${money(goods.full)} のうち、返金できるのは ${money(goods.limit)} までです（まだ返金できる残りの額。Stripe で返金するときは、Stripe で受け取った額まで）。今回の返金額は ${money(goods.amount)}。`
        : `商品の額 ${money(goods.full)}。今回の返金額は ${money(goods.amount)}。`;
});
</script>

<template>
    <AppPanel v-if="returns && (canRefund || history.length > 0 || returns.blocked)" title="返品・返金" description="発送した注文（完了した注文も）の一部または全部を返金します。商品と数を選ぶと、値引きと利用ポイントの分を引いた実際の支払額で返金します。送料・オプションの料金を返金するかも選べます。注文の状態はそのままで、返金した額に応じて「一部返金」「全額返金」と表示されます。" data-test="returns">
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>
        <AppNotice v-if="returns.blocked && canInTenant(tenant, 'money')" tone="warning" data-test="returns-blocked">{{ returns.blocked }}</AppNotice>

        <form v-if="canRefund" class="flex flex-col gap-5" novalidate data-test="return-form" @submit.prevent="check">
            <AppTable v-if="returns.items.length > 0" label="返品する商品">
                <thead>
                    <tr><th>商品</th><th class="num">発送した数</th><th class="num">返品済みの数</th><th class="num">返品する数</th><th>在庫・オプション</th></tr>
                </thead>
                <tbody>
                    <tr v-for="item in returns.items" :key="item.position" data-test="return-item">
                        <td><CellTitle :label="item.description" :sub="`明細の ${item.position} 行目・実際の支払額 ${money(item.net)}`" /></td>
                        <NumCell :value="item.shipped" />
                        <NumCell :value="item.returned" soft />
                        <td>
                            <AppInput v-model="form.items[item.position].quantity" type="number" min="0" :max="item.returnable" :disabled="item.returnable === 0" :aria-label="`${item.description}の返品する数`" data-test="return-quantity" />
                        </td>
                        <td>
                            <div class="flex flex-col">
                                <AppCheckbox v-if="item.restockable" v-model="form.items[item.position].restock" label="在庫に戻す" test="return-restock" />
                                <AppCheckbox v-if="item.addons.length > 0" v-model="form.items[item.position].addons" label="オプションの料金も返金する" test="return-addons" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </AppTable>
            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <AppField v-if="returns.shipping > 0" as="div" label="送料">
                    <AppCheckbox v-model="form.shipping" :label="`送料も返金する（${money(returns.shipping)}）`" test="return-shipping" />
                </AppField>
                <AppField v-if="returns.items.length > 0 || returns.shipping > 0" label="商品の返金額（円）" optional hint="空欄なら、選んだ商品（オプション・送料を含む）の額と、まだ返金できる残りの額のうち、小さい方を返金します。この額より少なくすることだけできます。0 にすると、商品だけ受け取って在庫に戻し、返金はしません。" :error="firstError(fieldErrors, 'goods_amount')">
                    <AppInput v-model="form.goods" inputmode="numeric" autocomplete="off" maxlength="9" placeholder="空欄なら商品の額（残りの額まで）" data-test="return-goods-amount" />
                </AppField>
                <AppField label="金額だけの返金（円）" optional hint="お詫びや後からの値引きなどに使います。商品の返金に加えて返金します。金額だけの返金でも、利用されたポイントは返金した額の割合で戻り、付与されたポイントは同じ割合で取り消されます。" :error="firstError(fieldErrors, 'amount')">
                    <AppInput v-model="form.amount" inputmode="numeric" autocomplete="off" maxlength="9" data-test="return-amount" />
                </AppField>
                <AppField label="理由" :error="firstError(fieldErrors, 'reason')">
                    <AppSelect v-model="form.reason" data-test="return-reason">
                        <option v-for="reason in returns.reasons" :key="reason.value" :value="reason.value">{{ reason.label }}</option>
                    </AppSelect>
                </AppField>
                <AppField label="返金の方法" hint="銀行振込・代金引換の注文や、お店から現金や振込で返金したときは「Stripe 以外で返金した（記録だけ）」を選びます。" :error="firstError(fieldErrors, 'channel')">
                    <AppSelect v-model="form.channel" data-test="return-channel">
                        <option v-if="returns.provider" value="provider">Stripe で返金する</option>
                        <option value="outside">Stripe 以外で返金した（記録だけ）</option>
                    </AppSelect>
                </AppField>
            </div>
            <AppText tone="soft" size="xs">返金できる額は残り {{ money(returns.available) }} です（受け取った額 − 返金済みの額）。</AppText>
            <AppNotice v-if="previewMessage" tone="error" data-test="return-preview-error">{{ previewMessage }}</AppNotice>
            <FormActions>
                <AppButton type="submit" :disabled="previewing || pending !== null" :loading="previewing" data-test="return-check">返金額を確認する</AppButton>
            </FormActions>
        </form>

        <InsetSection v-if="canRefund && preview" title="返金額の確認" description="この内容で返金します。消費税は、税率ごとに、返金の前と後の残りの額にかかる消費税の差です。元に戻せません。" data-test="return-preview">
            <AppTable label="返金の内容">
                <thead>
                    <tr><th>内容</th><th class="num">数量</th><th class="num">税率</th><th class="num">返金額</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(line, index) in preview.lines" :key="index" data-test="return-preview-line">
                        <td><CellTitle :label="line.description" :sub="`${LINE_KINDS[line.kind] ?? line.kind}${line.restock ? '・在庫に戻す' : ''}`" /></td>
                        <NumCell :value="line.quantity || '—'" />
                        <NumCell :value="formatRate(line.rate_bp)" soft />
                        <NumCell :value="money(line.amount)" strong />
                    </tr>
                </tbody>
            </AppTable>
            <AppText v-if="goodsNote" tone="soft" size="xs" data-test="return-goods-note">{{ goodsNote }}</AppText>
            <AmountSummary :rows="[
                ...preview.taxes.map((t) => ({ label: `${formatRate(t.rate_bp)} 対象`, value: `${money(t.gross)}（内消費税 ${money(t.tax)}）` })),
                { label: '返金額の合計', value: money(preview.total) },
            ]" />
            <FormActions>
                <ConfirmButton :label="form.channel === 'outside' ? 'Stripe 以外で返金したと記録する' : '返金する'" :confirm-label="`${money(preview.total)} を${form.channel === 'outside' ? '返金したと記録する' : '返金する'}`" variant="primary" size="md" :disabled="pending !== null" :loading="pending === 'return'" test="return-submit" confirm-test="return-confirm" @confirm="refund">
                    <span data-test="return-confirm-text">{{ form.channel === 'outside' ? `${money(preview.total)} をお店から返金したと記録します（Stripe では処理しません）。` : `${money(preview.total)} を Stripe でお客さんに返金します。` }}元に戻せません。</span>
                </ConfirmButton>
                <AppButton variant="ghost" :disabled="pending !== null" @click="preview = null">選び直す</AppButton>
            </FormActions>
        </InsetSection>

        <AppTable v-if="history.length > 0" label="返金の履歴" data-test="return-history">
            <thead>
                <tr><th>日時</th><th>内容</th><th>理由</th><th>状態</th><th>操作した人</th><th class="num">額</th></tr>
            </thead>
            <tbody>
                <tr v-for="entry in history" :key="entry.id" data-test="return-history-row">
                    <td><AppText as="span" tone="muted" nowrap>{{ formatDate(entry.created_at) }}</AppText></td>
                    <td><CellTitle :label="entry.lines.map((l) => `${l.description ?? LINE_KINDS[l.kind]}${l.quantity > 0 ? ` × ${l.quantity}` : ''}`).join('、') || '—'" :sub="entry.lines.some((l) => l.restock) ? '在庫に戻した' : null" /></td>
                    <td><AppText as="span" tone="muted">{{ entry.reason_label }}</AppText></td>
                    <td>
                        <StatusBadge v-if="entry.refund" :tone="REFUND_TONES[entry.refund.status] ?? 'neutral'" data-test="return-history-state">{{ entry.refund.channel === 'outside' ? 'Stripe 以外で返金（記録）' : (REFUND_STATES[entry.refund.status] ?? entry.refund.status) }}</StatusBadge>
                    </td>
                    <td><AppText as="span" tone="muted">{{ entry.by ?? '—' }}</AppText></td>
                    <NumCell :value="money(entry.amount)" strong />
                </tr>
            </tbody>
        </AppTable>
    </AppPanel>
</template>
