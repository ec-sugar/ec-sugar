<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../../api.js';
import { formatDate, shortId } from '../../format.js';
import { crumbsFor, titleOf } from '../../navigation.js';
import { helpLink } from '../../help/index.js';
import { useOperation } from '../../operation.js';
import { tenantName } from '../../tenants.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPager from '../../components/ui/AppPager.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppTable from '../../components/ui/AppTable.vue';
import AppText from '../../components/ui/AppText.vue';
import CellTitle from '../../components/ui/CellTitle.vue';
import DescriptionList from '../../components/ui/DescriptionList.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import FilterSelect from '../../components/ui/FilterSelect.vue';
import InsetSection from '../../components/ui/InsetSection.vue';
import ListFilters from '../../components/ui/ListFilters.vue';
import LoadingText from '../../components/ui/LoadingText.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import StatusBadge from '../../components/ui/StatusBadge.vue';
import SettingsLayout from '../../components/SettingsLayout.vue';

// 設定 › メールの記録（ADR 0095）。店が送ったメール（注文の通知・会員のログインのコード・App のメール・試しのメール）の一覧と 1 通の様子、
// 送れなかった理由（決まった語と SMTP の応答の番号だけ）、関係する注文・お客さんへのリンク、試しのメールを自分に送る。
// 宛先のメールアドレスは「宛先を見る」でだけ（運用の段階から。見たことは記録に残る）。段階での出し分けは見た目のためだけで、守るのはサーバー
const props = defineProps({ tenant: { type: String, required: true } });

const STATUS_FILTERS = [
    { value: 'all', label: 'すべて' },
    { value: 'failed', label: '送信失敗' },
    { value: 'sending', label: '送信中' },
    { value: 'sent', label: '送信済み' },
];
const REASONS = {
    rejected: '相手のメールのサーバーに受け取りを断られました（アドレスが無い・受け取りの拒否など）。アドレスに打ち間違いがないかを確認してください。',
    temporary: '相手のメールのサーバーが、いまは受け取れないと応答しました（混雑・受信箱がいっぱいなど）。自動で再送します。',
    auth: 'メールを送るサーバーにログインできませんでした。サーバーを管理している人に、メールの送信の設定（ユーザー名とパスワード）を確認してもらってください。',
    connection: 'メールを送るサーバーに接続できませんでした。サーバーを管理している人に、メールの送信の設定と、送るサーバーが動いているかを確認してもらってください。',
    timeout: 'メールを送るサーバーが時間内に応答しませんでした。続くときは、サーバーを管理している人に確認してもらってください。',
    error: 'そのほかの理由で送れませんでした。続くときは、サーバーを管理している人に確認してもらってください。',
};

const list = ref(null);
const loadError = ref(null);
const status = ref('all');
const kind = ref('');
const page = ref(1);
const open = ref(null);
const detailError = ref(null);
const recipients = ref(null);
const revealing = useOperation();
const testing = useOperation();

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/mail-records`);
const crumbs = computed(() => crumbsFor('settings-mails', props.tenant, tenantName(props.tenant)));
const filtered = computed(() => status.value !== 'all' || kind.value !== '');

watch(() => props.tenant, () => load(1), { immediate: true });
watch([status, kind], () => load(1));

async function load(to = page.value) {
    loadError.value = null;
    page.value = to;
    const query = new URLSearchParams({ status: status.value, page: String(to) });

    if (kind.value !== '') {
        query.set('kind', kind.value);
    }

    try {
        list.value = await api('GET', `${base.value}?${query}`);
    } catch (e) {
        loadError.value = e;
    }
}

async function show(id) {
    detailError.value = null;
    recipients.value = null;

    try {
        open.value = (await api('GET', `${base.value}/${encodeURIComponent(id)}`)).record;
    } catch (e) {
        detailError.value = e;
    }
}

function reveal() {
    const id = open.value.id;

    return revealing.run('reveal', () => api('GET', `${base.value}/${encodeURIComponent(id)}/personal`), {
        then: (result) => {
            recipients.value = result.personal.recipients;
        },
    });
}

function sendTest() {
    return testing.run('test', () => api('POST', `${base.value}/test`), {
        then: () => setTimeout(() => load(1), 3000),
        done: 'テストメールを送りました。数十秒以内に、この一覧に表示されます（表示されないときは、バックグラウンド処理〔キュー〕が止まっているかもしれません）。',
    });
}

function clear() {
    status.value = 'all';
    kind.value = '';
}

function statusLabel(record) {
    if (record.status === 'sending') {
        return record.stale ? '送信できたか不明' : '送信中';
    }

    return record.status === 'sent' ? '送信済み' : '送信失敗';
}

function statusTone(record) {
    if (record.status === 'failed') {
        return 'danger';
    }

    return record.status === 'sent' ? 'ok' : 'warn';
}

function smtpOf(record) {
    return [record.smtp_code, record.smtp_status].filter((value) => value !== null).join(' ');
}

const facts = computed(() => {
    if (open.value === null) {
        return [];
    }

    const record = open.value;

    return [
        { label: '種類', value: record.kind_label },
        { label: '状態', value: statusLabel(record) },
        { label: '最初に送信した時刻', value: formatDate(record.created_at) },
        { label: record.status === 'sent' ? '送信した時刻' : '最後に状態が変わった時刻', value: formatDate(record.sent_at ?? record.updated_at) },
        { label: '送信を試みた回数', value: `${record.attempts} 回` },
        { label: '宛先の数', value: `${record.recipient_count} 件` },
        ...(smtpOf(record) !== '' ? [{ label: '送信サーバーの応答コード', value: smtpOf(record), mono: true }] : []),
    ];
});
</script>

<template>
    <SettingsLayout :tenant="tenant">
        <PageHeader :help="helpLink('settings-mails', tenant)" :title="titleOf('settings-mails')" :crumbs="crumbs" />

        <ErrorState v-if="loadError" :error="loadError" />
        <LoadingText v-else-if="list === null" />
        <template v-else>
            <AppNotice v-if="list.failed_recently > 0" tone="error" data-test="mail-failed-notice">
                この 7 日に送信できなかったメールが {{ list.failed_recently }} 通あります。「送信失敗」で絞り込み、開いて理由を確認してください。
            </AppNotice>

            <AppPanel title="送ったメール" :description="`お客さんや店へ送ったメールです（${list.keep_days} 日より前のものは削除されます）。メルマガの配信は、送信に失敗した分だけが表示されます。デモの店のメールは送信しないため表示されません。`" data-test="mail-records">
                <ListFilters label="メールの絞り込み" :total="list.total" :filtered="filtered" @clear="clear">
                    <FilterSelect v-model="status" label="状態" data-test="mail-status-filter">
                        <option v-for="option in STATUS_FILTERS" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </FilterSelect>
                    <FilterSelect v-model="kind" label="種類" data-test="mail-kind-filter">
                        <option value="">すべて</option>
                        <option v-for="option in list.kinds" :key="option.value" :value="option.value">{{ option.label }}（{{ option.value }}）</option>
                    </FilterSelect>
                </ListFilters>

                <AppText v-if="list.records.length === 0" tone="soft" data-test="mail-records-empty">記録はありません。</AppText>
                <AppTable v-else label="送ったメール" stack>
                    <thead>
                        <tr><th>時刻</th><th>種類</th><th>件名</th><th>状態</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="record in list.records" :key="record.id" data-test="mail-record-row" :data-status="record.status">
                            <td data-label="時刻"><AppText as="span" tone="muted" nowrap>{{ formatDate(record.created_at) }}</AppText></td>
                            <td data-label="種類"><AppText as="span" size="sm">{{ record.kind_label }}</AppText></td>
                            <td data-label="件名"><AppText as="span">{{ record.subject }}</AppText></td>
                            <td data-label="状態"><StatusBadge :tone="statusTone(record)">{{ statusLabel(record) }}</StatusBadge></td>
                            <td><AppButton size="sm" data-test="mail-record-open" @click="show(record.id)">開く</AppButton></td>
                        </tr>
                    </tbody>
                </AppTable>
                <AppPager :page="list.page" :last-page="list.last_page" :total="list.total" @go="load" />
            </AppPanel>

            <ErrorState v-if="detailError" :error="detailError" />
            <AppPanel v-if="open" :title="open.subject" :description="open.kind_label" data-test="mail-record-detail">
                <AppNotice v-if="open.status === 'failed'" tone="error" data-test="mail-record-reason">{{ REASONS[open.reason] ?? REASONS.error }}</AppNotice>
                <AppNotice v-else-if="open.stale" tone="warning">送信を始めてから 1 時間を過ぎても「送信済み」になっていません。送信できたかどうか分かりません。続くときは、サーバーを管理している人に確認してもらってください。</AppNotice>
                <DescriptionList :items="facts" />
                <div class="flex flex-wrap gap-4">
                    <CellTitle v-if="open.transaction_id" :label="`注文 ${shortId(open.transaction_id)} を開く`" :to="{ name: 'transaction', params: { tenant, transaction: open.transaction_id } }" />
                    <CellTitle v-if="open.customer_id" label="このお客さんを開く" :to="{ name: 'customer', params: { tenant, customer: open.customer_id } }" />
                </div>

                <InsetSection v-if="list.can_see_recipients" title="宛先" description="メールアドレスはお客さんの名前と連絡先と同じ扱いです。見たことは記録に残ります。" data-test="mail-record-recipients">
                    <AppNotice v-if="revealing.error.value" tone="error">{{ revealing.error.value }}</AppNotice>
                    <AppText v-if="recipients !== null" data-test="mail-record-recipient">{{ recipients.join('、') || '（宛先がありません）' }}</AppText>
                    <AppButton v-else size="sm" :loading="revealing.pending.value !== null" :disabled="revealing.pending.value !== null" data-test="mail-record-reveal" @click="reveal">宛先を見る</AppButton>
                </InsetSection>
            </AppPanel>

            <AppPanel title="テストメールを送る" description="ログインしているあなたのメールアドレスへ、お客さんへのメールと同じ経路で 1 通送ります。届くか、この一覧に「送信済み」と表示されるかを確認できます。" data-test="mail-test">
                <AppNotice v-if="testing.message.value" tone="success" data-test="mail-test-done">{{ testing.message.value }}</AppNotice>
                <AppNotice v-if="testing.error.value" tone="error" data-test="mail-test-error">{{ testing.error.value }}</AppNotice>
                <div v-if="list.can_send_test" class="flex">
                    <AppButton variant="primary" :loading="testing.pending.value !== null" :disabled="testing.pending.value !== null" data-test="mail-test-send" @click="sendTest">テストメールを送る</AppButton>
                </div>
                <AppText v-else size="sm" tone="soft" data-test="mail-test-no-permission">テストメールを送れるのは「運用」か「会計」の権限のある人だけです。</AppText>
            </AppPanel>
        </template>
    </SettingsLayout>
</template>
