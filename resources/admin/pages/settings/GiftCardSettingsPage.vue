<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../../api.js';
import { firstError, formatAmount, formatDate, shortId } from '../../format.js';
import { crumbsFor, titleOf } from '../../navigation.js';
import { helpLink } from '../../help/index.js';
import { useOperation } from '../../operation.js';
import { tenantName } from '../../tenants.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppField from '../../components/ui/AppField.vue';
import AppInput from '../../components/ui/AppInput.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPager from '../../components/ui/AppPager.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppTable from '../../components/ui/AppTable.vue';
import AppText from '../../components/ui/AppText.vue';
import AppTextarea from '../../components/ui/AppTextarea.vue';
import CellTitle from '../../components/ui/CellTitle.vue';
import ChoicePills from '../../components/ui/ChoicePills.vue';
import ConfirmButton from '../../components/ui/ConfirmButton.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import FormActions from '../../components/ui/FormActions.vue';
import InsetSection from '../../components/ui/InsetSection.vue';
import LoadingText from '../../components/ui/LoadingText.vue';
import NumCell from '../../components/ui/NumCell.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import StatCard from '../../components/ui/StatCard.vue';
import StatusBadge from '../../components/ui/StatusBadge.vue';
import SettingsLayout from '../../components/SettingsLayout.vue';

// 設定 › ギフトカード（ADR 0076）。発行したカードの一覧（末尾 4 文字・額面・残高・期限・様子）と 1 枚の帳簿、止める・再開（盗難の申し出）、
// 手で足す・引く（お金の段階・直前のパスキーの再確認）、まだ使われていない残高の合計（資金決済法の届出の判断のため。お金の段階だけ）。
// コードそのものはどこにも持っていないので出せない（お客さんには末尾 4 文字で確認する）。売るのは App「ギフトカード」（設定はそちら）。
// 段階での出し分けは見た目のためだけで、守るのはサーバー（足りない段階には 403）
const props = defineProps({ tenant: { type: String, required: true } });

const STATES = [
    { value: 'all', label: 'すべて' },
    { value: 'active', label: '有効' },
    { value: 'empty', label: '残高なし' },
    { value: 'expired', label: '期限切れ' },
    { value: 'stopped', label: '停止中' },
];
const STATE_LABELS = { active: '有効', empty: '残高なし', expired: '期限切れ', stopped: '停止中' };
const KINDS = { issue: '発行', spend: '注文で利用', release: '取消・返金で戻し', expire: '期限切れ', adjust: '手動の調整' };

const list = ref(null);
const loadError = ref(null);
const state = ref('all');
const last4 = ref('');
const page = ref(1);
const open = ref(null);
const detailError = ref(null);
const draft = ref({ amount: '', reason: '' });
const acting = useOperation();

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/gift-cards`);
const crumbs = computed(() => crumbsFor('settings-gift-cards', props.tenant, tenantName(props.tenant)));

watch(() => props.tenant, () => load(1), { immediate: true });
watch(state, () => load(1));

async function load(to = page.value) {
    loadError.value = null;
    page.value = to;
    const query = new URLSearchParams({ state: state.value, page: String(to) });

    if (last4.value.trim() !== '') {
        query.set('last4', last4.value.trim().toUpperCase());
    }

    try {
        list.value = await api('GET', `${base.value}?${query}`);
    } catch (e) {
        loadError.value = e;
    }
}

async function show(id) {
    detailError.value = null;
    draft.value = { amount: '', reason: '' };

    try {
        open.value = (await api('GET', `${base.value}/${encodeURIComponent(id)}`)).card;
    } catch (e) {
        detailError.value = e;
    }
}

function act(name, path, body, done) {
    const id = open.value.id;

    return acting.run(name, () => api('POST', `${base.value}/${encodeURIComponent(id)}/${path}`, body), {
        then: async () => {
            await show(id);
            await load();
        },
        done,
    });
}

function adjust() {
    return act('adjust', 'adjust', { amount: Number(draft.value.amount), reason: draft.value.reason }, (result) => `残高を調整しました。現在の残高は ${formatAmount(result.balance)} です。`);
}

function signed(value) {
    return `${value > 0 ? '+' : '−'}${formatAmount(Math.abs(value))}`;
}
</script>

<template>
    <SettingsLayout :tenant="tenant">
        <PageHeader :help="helpLink('settings-gift-cards', tenant)" :title="titleOf('settings-gift-cards')" :crumbs="crumbs" />

        <ErrorState v-if="loadError" :error="loadError" />
        <LoadingText v-else-if="list === null" />
        <template v-else>
            <!-- 未使用の残高の合計はお金の段階の人にだけ返る（ほかは null） -->
            <div v-if="list.outstanding !== null" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <StatCard label="まだ使われていない残高" :value="formatAmount(list.outstanding.amount)" note="有効期限内のカードの残高の合計（店の負債）。資金決済法の届出が必要かの判断に使います" value-test="gift-cards-outstanding" />
                <StatCard label="残高のあるカード" :value="`${list.outstanding.cards.toLocaleString('ja-JP')} 枚`" value-test="gift-cards-holders" />
            </div>

            <AppPanel title="発行したギフトカード" description="ギフトカードの販売は App「ギフトカード」で行います。コードそのものは店にも保存していないため、お客さんにはコードの末尾 4 文字で確認してください。" data-test="gift-cards-list">
                <div class="flex flex-wrap items-end gap-4">
                    <ChoicePills v-model="state" legend="状態" :options="STATES" test-prefix="gift-cards-state" />
                    <form class="flex items-end gap-2" @submit.prevent="load(1)">
                        <AppField label="末尾 4 文字">
                            <AppInput v-model="last4" maxlength="4" autocomplete="off" data-test="gift-cards-last4" />
                        </AppField>
                        <AppButton type="submit" data-test="gift-cards-search">探す</AppButton>
                    </form>
                </div>
                <AppText v-if="list.cards.length === 0" tone="soft" data-test="gift-cards-empty">ギフトカードはありません。</AppText>
                <AppTable v-else label="発行したギフトカード" stack>
                    <thead>
                        <tr><th>末尾</th><th class="num">額面</th><th class="num">残高</th><th>期限</th><th>状態</th><th>発行</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="card in list.cards" :key="card.id" data-test="gift-card-row" :data-state="card.state">
                            <td data-label="末尾"><AppText as="span" nowrap>{{ card.last4 }}</AppText></td>
                            <NumCell :value="formatAmount(card.amount)" data-label="額面" />
                            <NumCell :value="formatAmount(card.balance)" strong data-label="残高" />
                            <td data-label="期限"><AppText as="span" tone="muted" nowrap>{{ formatDate(card.expires_at) }}</AppText></td>
                            <td data-label="状態"><StatusBadge :tone="card.state === 'active' ? 'accent' : 'neutral'">{{ STATE_LABELS[card.state] }}</StatusBadge></td>
                            <td data-label="発行"><AppText as="span" tone="muted" nowrap>{{ formatDate(card.created_at) }}</AppText></td>
                            <td><AppButton size="sm" data-test="gift-card-open" @click="show(card.id)">開く</AppButton></td>
                        </tr>
                    </tbody>
                </AppTable>
                <AppPager :page="list.page" :last-page="list.last_page" :total="list.total" @go="load" />
            </AppPanel>

            <ErrorState v-if="detailError" :error="detailError" />
            <AppPanel v-if="open" :title="`末尾 ${open.last4} のギフトカード`" :description="`額面 ${formatAmount(open.amount)}・期限 ${formatDate(open.expires_at)}・${STATE_LABELS[open.state]}${open.stopped_reason === 'refund' ? '（販売した注文が返金されたため、自動で停止しました。残高を 0 にするか、確認してから再開します）' : ''}${open.test ? '・テストの支払いで販売したカード（本番の鍵の店では使えません）' : ''}${open.mailed ? '' : '（コードのメールをまだ送信できていません。定期チェックで再送します）'}`" data-test="gift-card-detail">
                <AppNotice v-if="acting.message.value" tone="success" data-test="gift-card-done">{{ acting.message.value }}</AppNotice>
                <AppNotice v-if="acting.error.value" tone="error" data-test="gift-card-error">{{ acting.error.value }}</AppNotice>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <StatCard label="残高" :value="formatAmount(open.balance)" size="sm" value-test="gift-card-balance" />
                    <StatCard label="購入された注文" :value="open.purchase_transaction_id ? shortId(open.purchase_transaction_id) : '—'" size="sm" />
                </div>

                <AppTable label="残高の履歴" stack>
                    <thead>
                        <tr><th>日時</th><th>内容</th><th class="num">額</th><th class="num">残高</th><th>注文・理由</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in open.history" :key="entry.id" data-test="gift-card-entry" :data-kind="entry.kind">
                            <td data-label="日時"><AppText as="span" tone="muted" nowrap>{{ formatDate(entry.occurred_at) }}</AppText></td>
                            <td data-label="内容"><StatusBadge :tone="entry.amount > 0 ? 'accent' : 'neutral'">{{ KINDS[entry.kind] }}</StatusBadge></td>
                            <NumCell :value="signed(entry.amount)" strong data-label="額" />
                            <NumCell :value="formatAmount(entry.balance_after)" data-label="残高" />
                            <td data-label="注文・理由">
                                <CellTitle v-if="entry.transaction_id" :label="shortId(entry.transaction_id)" :to="{ name: 'transaction', params: { tenant, transaction: entry.transaction_id } }" />
                                <AppText v-if="entry.reason" as="span" size="sm" data-test="gift-card-reason">{{ entry.reason }}</AppText>
                            </td>
                        </tr>
                    </tbody>
                </AppTable>

                <InsetSection v-if="list.can_stop" :title="open.state === 'stopped' ? '停止したカードを再開する' : 'カードを停止する'" description="お客さんから紛失・盗難の申し出があったときに停止します。停止したカードは注文に使えません（残高はそのままで、再開もできます）。" data-test="gift-card-stop">
                    <ConfirmButton
                        v-if="open.state !== 'stopped'"
                        label="このカードを停止"
                        :loading="acting.pending.value === 'stop'"
                        :disabled="acting.pending.value !== null"
                        test="gift-card-stop"
                        @confirm="act('stop', 'stop', {}, 'カードを停止しました。')"
                    >
                        末尾 {{ open.last4 }} のカードを停止しますか。
                    </ConfirmButton>
                    <AppButton v-else :loading="acting.pending.value === 'resume'" :disabled="acting.pending.value !== null" data-test="gift-card-resume" @click="act('resume', 'resume', {}, 'カードを再開しました。')">このカードを再開する</AppButton>
                </InsetSection>

                <InsetSection v-if="list.can_adjust" title="残高を調整する" description="お詫び・入力の誤りの訂正などに使います。減らすときは数字の前に「-」を付けます（残高より多くは減らせません。期限切れのカードには加算できません）。押すとパスキーで本人確認をします。" data-test="gift-card-adjust">
                    <form class="flex flex-col gap-4" @submit.prevent="adjust">
                        <AppField label="調整する額（円）" hint="例: 1000（加算）・-500（減算）" :error="firstError(acting.errors.value, 'amount')">
                            <AppInput v-model="draft.amount" type="number" step="1" min="-1000000" max="1000000" inputmode="numeric" required data-test="gift-card-adjust-amount" />
                        </AppField>
                        <AppField label="理由" hint="店の中の記録です（お客さんには表示されません）。200 文字まで。" :error="firstError(acting.errors.value, 'reason')">
                            <AppTextarea v-model="draft.reason" maxlength="200" required data-test="gift-card-adjust-reason" />
                        </AppField>
                        <FormActions>
                            <AppButton type="submit" variant="primary" :disabled="acting.pending.value !== null" :loading="acting.pending.value === 'adjust'" data-test="gift-card-adjust-save">残高を調整する</AppButton>
                        </FormActions>
                    </form>
                </InsetSection>
                <AppText v-else size="sm" tone="soft" data-test="gift-card-adjust-no-permission">残高を手動で調整できるのは、「会計」の権限のある人だけです。</AppText>
            </AppPanel>
        </template>
    </SettingsLayout>
</template>
