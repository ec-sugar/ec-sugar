<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api } from '../../api.js';
import { firstError, formatDate } from '../../format.js';
import { crumbsFor, titleOf } from '../../navigation.js';
import { helpLink } from '../../help/index.js';
import { useOperation } from '../../operation.js';
import { session } from '../../session.js';
import { canInTenant, onlyTenant, tenantDirectory, tenantName } from '../../tenants.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppField from '../../components/ui/AppField.vue';
import AppInput from '../../components/ui/AppInput.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppSelect from '../../components/ui/AppSelect.vue';
import AppTable from '../../components/ui/AppTable.vue';
import AppText from '../../components/ui/AppText.vue';
import BulletList from '../../components/ui/BulletList.vue';
import CellTitle from '../../components/ui/CellTitle.vue';
import ChoicePills from '../../components/ui/ChoicePills.vue';
import CodeBlock from '../../components/ui/CodeBlock.vue';
import ConfirmButton from '../../components/ui/ConfirmButton.vue';
import EmptyState from '../../components/ui/EmptyState.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import FormActions from '../../components/ui/FormActions.vue';
import InsetSection from '../../components/ui/InsetSection.vue';
import LoadingText from '../../components/ui/LoadingText.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import StatusBadge from '../../components/ui/StatusBadge.vue';
import SettingsLayout from '../../components/SettingsLayout.vue';

// 設定 › AI エージェント（ADR 0100・0101）。自分の AI エージェント（Claude など）から MCP で店を読む・変えるための鍵を作り、一覧で見て、取り消す。
// 鍵は「読むだけ」か「書ける」（書ける鍵はその店で運用以上の人だけ・期限は最長 90 日）。この店の設定として、書き込みを「承認してから」（既定）か
// 「すぐ反映」か、お客さんの情報を渡すか（既定は渡さない）も選ぶ（読むのは閲覧、変えるのは運用。サーバーも 403 で断る）。
// 鍵の API は店の外（/api/agent-tokens。運営ごと）なので、どの店の鍵かはフォームで選ぶ（見られる店が 1 つなら選ばせない）。
// 作った鍵は答えの 1 回だけ受け取り、この画面の中の値にだけ置く（URL・ブラウザの保存領域・通知の文に入れない。閉じる・画面を離れると捨てる）。
// 出し分けは見た目のためだけで、守るのはサーバー（発行は自分の分だけで直前の再確認、取り消しは本人とオーナー、読めるものは毎回その店の今の段階で）
const props = defineProps({ tenant: { type: String, required: true } });

const READ_DAYS = [30, 90, 180, 365].map((days) => ({ value: days, label: `${days} 日` }));
const WRITE_DAYS = [7, 30, 90].map((days) => ({ value: days, label: `${days} 日` }));
const SCOPES = [{ value: 'read', label: '閲覧のみ' }, { value: 'write', label: '閲覧と変更' }];
const WRITE_MODES = [{ value: 'approval', label: '承認してから' }, { value: 'immediate', label: 'すぐ反映' }];
const CUSTOMER_DATA = [{ value: 'off', label: '渡さない' }, { value: 'on', label: '渡す' }];
const PLACEHOLDER = 'ここにアクセスキー';

const tokens = ref(null);
// OAuth でつないだもの（claude.ai などの「コネクタを追加」から。ADR 0101）
const grants = ref(null);
const loadError = ref(null);
const name = ref('');
const shop = ref(props.tenant);
const days = ref(90);
const scope = ref('read');
// この店の設定（書き込みの扱い・お客さんの情報）。読めなければ null のまま
const shopSettings = ref(null);
const settingsDraft = ref(null);
const settingsError = ref(null);
// 作った直後だけ見せる鍵（{ value, name, shop }）。閉じると null にして二度と出さない
const issued = ref(null);
const { pending, errors, error, message, run } = useOperation();
const DAYS = computed(() => (scope.value === 'write' ? WRITE_DAYS : READ_DAYS));
// 書ける鍵を作れるのは、その店で運用以上の人だけ（サーバーが決める。ここは見せ方だけ）
const canWriteIn = computed(() => canInTenant(chooseShop.value ? shop.value : props.tenant, 'operate'));
const canEditSettings = computed(() => canInTenant(props.tenant, 'operate'));
// お客さんの情報を渡し始めるのはお金の段階の人だけ（サーバーも 403。止めるのは運用から）。保存はパスキーの再確認の後
const canShareCustomers = computed(() => canInTenant(props.tenant, 'money'));
const settingsBase = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/shop-settings/agents`);
const settingsDirty = computed(() => shopSettings.value !== null && settingsDraft.value !== null
    && (settingsDraft.value.write_mode !== shopSettings.value.write_mode || settingsDraft.value.customer_data !== shopSettings.value.customer_data));
const customerChoice = computed({
    get: () => (settingsDraft.value?.customer_data ? 'on' : 'off'),
    set: (value) => {
        settingsDraft.value.customer_data = value === 'on';
    },
});

const crumbs = computed(() => crumbsFor('settings-agents', props.tenant, tenantName(props.tenant)));
const isOwner = computed(() => session.operator?.role === 'owner');
// 選べる店（見られる店。今の店を先頭に）。1 つなら選ばせない
const shops = computed(() => {
    const ids = Object.keys(tenantDirectory.names);
    const ordered = [props.tenant, ...ids.filter((id) => id !== props.tenant).sort((a, b) => tenantName(a).localeCompare(tenantName(b), 'ja'))];

    return ordered.map((id) => ({ id, name: tenantName(id) }));
});
const chooseShop = computed(() => onlyTenant() === null && shops.value.length > 1);
// 売上はお金の段階の人の鍵だけ（サーバーが毎回その店の今の段階で決める。ここは説明のためだけ）
const canReadSales = computed(() => canInTenant(shop.value, 'money'));
// MCP の入口は、この管理画面と同じホストの /mcp（画面の今のオリジンから組み立てる）
const endpoint = `${window.location.origin}/mcp`;
// 例は鍵をコマンドの引数に直に書かない（シェルの履歴・プロセスの一覧に残るため）。環境変数か、設定のファイルの env の欄で渡す
const KEY_ENV = 'EC_SUGAR_AGENT_KEY';
// 中継の mcp-remote はバージョンを固定する（出て 7 日より後のもの。ADR 0037 の基準。上げるときは docs/install.md の同じ節も）
const MCP_REMOTE = 'mcp-remote@0.16.0';
const examples = [
    {
        key: 'cli',
        title: 'ターミナルの claude コマンド',
        description: `1 行目を実行してからアクセスキーを貼り、Enter を押します（アクセスキーは画面にもシェルの履歴にも残りません）。2 行目で、環境変数 ${KEY_ENV} のアクセスキーをヘッダーに入れて登録します。`,
        text: `read -rs ${KEY_ENV} && export ${KEY_ENV}\nclaude mcp add --transport http ec-sugar ${endpoint} --header "Authorization: Bearer $${KEY_ENV}"`,
    },
    {
        key: 'http-json',
        title: '設定ファイルに書く（プロジェクトの .mcp.json など、HTTP の MCP サーバーを設定できるツール）',
        description: `アクセスキーはファイルに書かず、環境変数 ${KEY_ENV} から読み込みます（.mcp.json は \${…} を環境変数に置き換えます。ツールを起動する前に、上と同じく環境変数にアクセスキーを入れておきます）。`,
        text: JSON.stringify({ mcpServers: { 'ec-sugar': { type: 'http', url: endpoint, headers: { Authorization: `Bearer \${${KEY_ENV}}` } } } }, null, 2),
    },
    {
        key: 'claude-desktop',
        title: 'Claude Desktop（claude_desktop_config.json）',
        description: `手元の MCP サーバーしか設定できないアプリは、HTTP の MCP サーバーへ中継する mcp-remote を使います（手元に Node.js が必要です）。アクセスキーは env の欄に書き、引数には \${…} の名前だけを書きます。「${PLACEHOLDER}」を、作ったアクセスキーに置き換えてください。`,
        text: JSON.stringify({ mcpServers: { 'ec-sugar': { command: 'npx', args: ['-y', MCP_REMOTE, endpoint, '--header', `Authorization:\${${KEY_ENV}_HEADER}`], env: { [`${KEY_ENV}_HEADER`]: `Bearer ${PLACEHOLDER}` } } } }, null, 2),
    },
];

async function load() {
    const result = await api('GET', '/api/agent-tokens');
    tokens.value = result.tokens;
    grants.value = result.grants ?? [];
}

// OAuth でつないだもの（ADR 0101）を取り消す。その AI エージェントは、次の呼び出しから店を読めず、鍵の入れ替えもできなくなる
function revokeGrant(grant) {
    return run(`revoke-grant-${grant.id}`, () => api('DELETE', `/api/agent-grants/${encodeURIComponent(grant.id)}`), {
        done: `${grant.host} の接続を取り消しました。この AI エージェントは、もう店のデータを見られません。`,
        then: load,
    });
}

function grantStatusOf(grant) {
    if (grant.revoked_at) {
        return { tone: 'neutral', label: '取り消し済み' };
    }

    return grant.usable ? { tone: 'ok', label: '接続中' } : { tone: 'warn', label: '期限切れ' };
}

// 名乗った名前は確認していないので、行き先のホストと並べる
function grantSubOf(grant) {
    // 店は許可で選んだ全部（ADR 0104）
    const shops = (grant.shops ?? []).map((shop) => shop.name ?? shop.id).join('、');
    const shopName = `${shops}・${grant.scope === 'write' ? '閲覧と変更' : '閲覧のみ'}・接続先 ${grant.host}・${formatDate(grant.expires_at)} まで`;

    return grant.mine ? shopName : `${shopName}・持ち主: ${grant.operator?.name ?? '—'}`;
}

async function loadSettings() {
    settingsError.value = null;

    try {
        shopSettings.value = (await api('GET', settingsBase.value)).settings;
        settingsDraft.value = { ...shopSettings.value };
    } catch (e) {
        settingsError.value = e;
    }
}

function saveSettings() {
    return run('settings', () => api('PUT', settingsBase.value, settingsDraft.value), {
        done: 'AI エージェントの設定を保存しました。',
        then: (result) => {
            shopSettings.value = result.settings;
            settingsDraft.value = { ...result.settings };
        },
    });
}

// 鍵の種類を変えたら、期限をその種類の既定に戻す（書ける鍵は短く）
watch(scope, (value) => {
    days.value = value === 'write' ? 30 : 90;
});

onMounted(async () => {
    loadSettings();

    try {
        await load();
    } catch (e) {
        loadError.value = e;
    }
});

// 別の店の設定へ移ったら、選んだ店をその店に戻し、見せていた鍵も捨てる
watch(() => props.tenant, (tenant) => {
    shop.value = tenant;
    issued.value = null;
    loadSettings();
});

onBeforeUnmount(() => {
    issued.value = null;
});

function issue() {
    const label = name.value.trim();
    const target = chooseShop.value ? shop.value : props.tenant;
    const kind = canWriteIn.value ? scope.value : 'read';
    issued.value = null;

    return run('issue', () => api('POST', '/api/agent-tokens', { tenant: target, name: label, days: days.value, scope: kind }), {
        then: async (result) => {
            issued.value = { value: result.secret, name: result.token.name, shop: result.token.tenant.name ?? result.token.tenant.id, scope: result.token.scope };
            name.value = '';
            await load();
        },
    });
}

function closeIssued() {
    issued.value = null;
}

function revoke(token) {
    return run(`revoke-${token.id}`, () => api('DELETE', `/api/agent-tokens/${encodeURIComponent(token.id)}`), {
        done: `「${token.name}」のアクセスキーを取り消しました。このアクセスキーでは、もう店のデータを見られません。`,
        then: load,
    });
}

function statusOf(token) {
    if (token.revoked_at) {
        return { tone: 'neutral', label: '取り消し済み' };
    }

    return token.usable ? { tone: 'ok', label: '有効' } : { tone: 'warn', label: '期限切れ' };
}

// 一覧の名前の下の補足: 店の名前と、ほかの人の鍵なら持ち主の名前（オーナーだけに見える）
function subOf(token) {
    const shopName = `${token.tenant.name ?? token.tenant.id}・${token.scope === 'write' ? '閲覧と変更' : '閲覧のみ'}`;

    return token.mine ? shopName : `${shopName}・持ち主: ${token.operator?.name ?? '—'}`;
}
</script>

<template>
    <SettingsLayout :tenant="tenant">
        <PageHeader :help="helpLink('settings-agents', tenant)" :title="titleOf('settings-agents')" :crumbs="crumbs" />
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>

        <AppPanel v-if="issued" :title="`「${issued.name}」のアクセスキー（${issued.shop}）`" data-test="agent-secret">
            <AppNotice tone="warning" data-test="agent-secret-once">このアクセスキーは今だけ表示します。閉じると二度と見られません。AI エージェントの設定に貼ってから閉じてください。</AppNotice>
            <CodeBlock :text="issued.value" label="作ったアクセスキー" copy copy-label="アクセスキーをコピー" select-all />
            <AppText size="sm" tone="soft">アクセスキーは、パスワードと同じように扱ってください。人に送ったり、スクリーンショットに残したりしないでください。なくしたら、取り消して作り直してください。</AppText>
            <div>
                <AppButton size="sm" data-test="agent-secret-close" @click="closeIssued">保管したので閉じる</AppButton>
            </div>
        </AppPanel>

        <AppPanel
            as="form"
            title="アクセスキーを作る"
            description="自分の AI エージェントから、この管理画面と同じ権限の範囲で店のデータを見る・変更するためのアクセスキーです。1 つのアクセスキーで使えるのは、あなた 1 人の、1 つの店の分だけです。作る前に、パスキーでもう一度本人確認をします。"
            @submit.prevent="issue"
        >
            <AppField layout="row" label="名前" hint="どのツールで使うアクセスキーか分かる名前にします（例「Claude Desktop」。40 文字まで）" :error="firstError(errors, 'name')">
                <AppInput v-model="name" required maxlength="40" autocomplete="off" placeholder="Claude Desktop" data-test="agent-name" />
            </AppField>
            <AppField v-if="chooseShop" layout="row" label="店" hint="このアクセスキーで使えるのは、この店だけです" :error="firstError(errors, 'tenant')">
                <AppSelect v-model="shop" data-test="agent-shop">
                    <option v-for="item in shops" :key="item.id" :value="item.id">{{ item.name }}</option>
                </AppSelect>
            </AppField>
            <AppField
                as="div"
                layout="row"
                label="種類"
                :hint="!canWriteIn
                    ? '「閲覧と変更」のアクセスキーは、この店で「運用」か「会計」の権限を持つ人だけが作れます。'
                    : scope === 'write'
                        ? '商品・在庫・発送・取消・返金・クーポンを変更できます。この店の設定が「承認してから」なら提案を作るだけで、取消・返金・クーポンはいつも承認が必要です。期限は最長 90 日です。'
                        : '商品・在庫・注文・売上を見るだけです。'"
                :error="firstError(errors, 'scope')"
            >
                <ChoicePills v-model="scope" legend="アクセスキーの種類" :options="SCOPES" :disabled="!canWriteIn" test-prefix="agent-scope" />
            </AppField>
            <AppField
                as="div"
                layout="row"
                label="期限"
                hint="期限が来ると、アクセスキーは使えなくなります。続けて使うときは、新しく作ってください"
                :error="firstError(errors, 'days')"
            >
                <ChoicePills v-model="days" legend="期限" :options="DAYS" test-prefix="agent-days" />
            </AppField>
            <AppText v-if="!chooseShop && firstError(errors, 'tenant')" size="sm" tone="danger">{{ firstError(errors, 'tenant') }}</AppText>
            <AppText size="sm" tone="soft" data-test="agent-level-note">
                {{ canReadSales
                    ? 'この店でのあなたの権限は「会計」なので、売上の集計も見られます。'
                    : 'この店でのあなたの権限では、売上の集計は見られません（売上を見られるのは「会計」の権限のある人のアクセスキーだけです）。商品・在庫・注文は見られます。' }}
            </AppText>
            <FormActions>
                <AppButton type="submit" variant="primary" :disabled="pending !== null || name.trim() === ''" :loading="pending === 'issue'" data-test="agent-issue">アクセスキーを作る</AppButton>
            </FormActions>
        </AppPanel>

        <AppPanel
            title="作ったアクセスキー"
            :description="isOwner ? 'オーナーには、ほかのメンバーが作ったアクセスキーも表示されます（持ち主の名前つき）。心当たりの無いアクセスキーは取り消してください。' : '自分が作ったアクセスキーです。使わなくなったアクセスキーは取り消してください。'"
        >
            <ErrorState v-if="loadError" :error="loadError" />
            <LoadingText v-else-if="tokens === null" />
            <EmptyState v-else-if="tokens.length === 0" icon="key" title="まだアクセスキーがありません">
                上の「アクセスキーを作る」で作ると、ここに並びます。
            </EmptyState>
            <AppTable v-else label="作ったアクセスキー" stack>
                <thead>
                    <tr><th>名前と店</th><th>アクセスキー</th><th>最後に使った日時</th><th>期限</th><th>状態</th><th><span class="sr-only">操作</span></th></tr>
                </thead>
                <tbody>
                    <tr v-for="token in tokens" :key="token.id" data-test="agent-row">
                        <td><CellTitle :label="token.name" :sub="subOf(token)" data-test="agent-name-cell" /></td>
                        <td data-label="アクセスキー"><AppText as="span" size="xs" mono nowrap data-test="agent-prefix">{{ token.prefix }}…</AppText></td>
                        <td data-label="最後に使った日時"><AppText as="span" tone="muted" nowrap>{{ token.last_used_at ? formatDate(token.last_used_at) : 'まだ使っていません' }}</AppText></td>
                        <td data-label="期限"><AppText as="span" tone="muted" nowrap>{{ formatDate(token.expires_at) }}</AppText></td>
                        <td data-label="状態"><StatusBadge :tone="statusOf(token).tone" data-test="agent-status">{{ statusOf(token).label }}</StatusBadge></td>
                        <td>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <ConfirmButton
                                    v-if="!token.revoked_at && token.usable"
                                    label="取り消す"
                                    :disabled="pending !== null"
                                    :loading="pending === `revoke-${token.id}`"
                                    test="agent-revoke"
                                    @confirm="revoke(token)"
                                >このアクセスキーを使っている AI エージェントは、次の操作から店のデータを見られなくなります。</ConfirmButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </AppTable>
        </AppPanel>

        <AppPanel
            title="コネクタで接続した AI エージェント"
            :description="isOwner ? 'claude.ai などの「コネクタを追加」から許可した接続です。オーナーには、ほかのメンバーの分も表示されます。心当たりの無いものは取り消してください。' : 'claude.ai などの「コネクタを追加」から許可した接続です。使わなくなったものは取り消してください。'"
            data-test="agent-grants"
        >
            <ErrorState v-if="loadError" :error="loadError" />
            <LoadingText v-else-if="grants === null" />
            <EmptyState v-else-if="grants.length === 0" icon="key" title="まだ接続していません">
                AI エージェントの「コネクタを追加」に、下の MCP の URL を入力すると、許可の画面が開きます。
            </EmptyState>
            <AppTable v-else label="コネクタで接続した AI エージェント" stack>
                <thead>
                    <tr><th>名前と店</th><th>接続した日時</th><th>最後に使った日時</th><th>状態</th><th><span class="sr-only">操作</span></th></tr>
                </thead>
                <tbody>
                    <tr v-for="grant in grants" :key="grant.id" data-test="agent-grant-row">
                        <td><CellTitle :label="grant.client_name" :sub="grantSubOf(grant)" data-test="agent-grant-name" /></td>
                        <td data-label="接続した日時"><AppText as="span" tone="muted" nowrap>{{ formatDate(grant.created_at) }}</AppText></td>
                        <td data-label="最後に使った日時"><AppText as="span" tone="muted" nowrap>{{ grant.last_used_at ? formatDate(grant.last_used_at) : 'まだ使っていません' }}</AppText></td>
                        <td data-label="状態"><StatusBadge :tone="grantStatusOf(grant).tone" data-test="agent-grant-status">{{ grantStatusOf(grant).label }}</StatusBadge></td>
                        <td>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <ConfirmButton
                                    v-if="!grant.revoked_at"
                                    label="取り消す"
                                    :disabled="pending !== null"
                                    :loading="pending === `revoke-grant-${grant.id}`"
                                    test="agent-grant-revoke"
                                    @confirm="revokeGrant(grant)"
                                >この AI エージェントは、次の操作から店のデータを見られなくなります。また使うときは、コネクタから接続し直してください。</ConfirmButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </AppTable>
        </AppPanel>

        <AppPanel
            as="form"
            title="この店の設定"
            description="AI エージェントが店のデータを変更するときの扱いと、お客さんの情報を渡すかどうかを、店ごとに選びます。"
            data-test="agent-shop-settings"
            @submit.prevent="saveSettings"
        >
            <ErrorState v-if="settingsError" :error="settingsError" />
            <LoadingText v-else-if="settingsDraft === null" />
            <template v-else>
                <AppField
                    as="div"
                    layout="row"
                    label="AI エージェントによる変更"
                    :hint="settingsDraft.write_mode === 'immediate'
                        ? '「閲覧と変更」のアクセスキーを使う AI エージェントが依頼した変更を、その場で反映します（商品・在庫・発送）。注文の取消・返金・クーポンは、この設定にかかわらず「AI エージェントの提案」で承認してから反映します。'
                        : 'AI エージェントは変更を提案するだけです。「AI エージェントの提案」で内容を見て、承認すると反映します（7 日で期限切れ）。'"
                    :error="firstError(errors, 'write_mode')"
                >
                    <ChoicePills v-model="settingsDraft.write_mode" legend="AI エージェントによる変更" :options="WRITE_MODES" :disabled="!canEditSettings" test-prefix="agent-write-mode" />
                </AppField>
                <AppField
                    as="div"
                    layout="row"
                    label="お客さんの情報を渡す"
                    :hint="settingsDraft.customer_data
                        ? 'お客さんの名前・メール・電話・住所・注文の履歴を、「運用」以上の権限のある人のアクセスキーを使う AI エージェントに渡します。ショップの「外部送信について」のページに、AI エージェントの項目が表示されます。'
                        : 'お客さんの名前・メール・電話・住所は渡しません（注文のお客さんは番号だけ）。'"
                    :error="firstError(errors, 'customer_data')"
                >
                    <ChoicePills v-model="customerChoice" legend="お客さんの情報を渡す" :options="CUSTOMER_DATA" :disabled="!canEditSettings || (!canShareCustomers && !shopSettings?.customer_data)" test-prefix="agent-customer-data" />
                    <AppText v-if="canEditSettings && !canShareCustomers && !shopSettings?.customer_data" size="sm" tone="soft" data-test="agent-customer-data-money">「渡す」にできるのは、「会計」の権限のある人かオーナーだけです。</AppText>
                </AppField>
                <AppText v-if="!canEditSettings" size="sm" tone="soft" data-test="settings-read-only">設定を変更できるのは「運用」以上の権限のある人だけです。</AppText>
                <FormActions v-else>
                    <template v-if="settingsDirty" #meta><span data-test="settings-unsaved">保存していない変更があります</span></template>
                    <AppButton :to="{ name: 'agent-proposals', params: { tenant } }" data-test="agent-proposals-link">AI エージェントの提案を見る</AppButton>
                    <AppButton type="submit" variant="primary" :disabled="pending !== null" :loading="pending === 'settings'" data-test="agent-settings-save">設定を保存する</AppButton>
                </FormActions>
            </template>
        </AppPanel>

        <AppPanel title="接続の方法" description="AI エージェントのツールに、MCP サーバーとして次の URL とアクセスキーを設定します。アクセスキーは「Authorization: Bearer アクセスキー」のヘッダーで送ります（URL には入れません）。">
            <InsetSection title="MCP の URL">
                <CodeBlock :text="endpoint" label="MCP の URL" copy copy-label="URL をコピー" />
            </InsetSection>
            <InsetSection
                title="claude.ai・ChatGPT などの「コネクタを追加」（アクセスキーは不要）"
                description="コネクタの URL に上の MCP の URL を入力すると、この管理画面の許可の画面が開きます。ログインして、店と「閲覧のみ／閲覧と変更」を選び、パスキーで本人確認をして許可します。許可の画面は、管理画面を開けるネットワーク（接続元の制限の中）から開いてください。"
                data-test="agent-example-connector"
            />
            <!-- 道具ごとの設定の例（鍵を使う形） -->
            <InsetSection v-for="example in examples" :key="example.key" :title="example.title" :description="example.description" :data-test="`agent-example-${example.key}`">
                <CodeBlock :text="example.text" :label="`${example.title} の設定の例`" copy />
            </InsetSection>
        </AppPanel>

        <AppPanel title="できることと渡さないもの">
            <BulletList size="sm">
                <li>「閲覧のみ」のアクセスキーで見られるのは、商品の検索・1 つの商品の詳細・在庫が少ない商品・注文の一覧と 1 つの注文の詳細・日ごと月ごとの売上です。</li>
                <li>「閲覧と変更」のアクセスキーでは、商品の登録（下書き）・編集・入庫と在庫の調整・発送済みにする・注文の取消・返金・クーポンの作成もできます。管理画面と同じ確認を通ります。</li>
                <li>注文の取消・返金・クーポンは、店の設定にかかわらず「AI エージェントの提案」で承認してから反映します。取消と返金を承認できるのは「会計」の権限のある人だけです。</li>
                <li>お客さんの名前・メール・電話・住所は、この店の設定で「渡す」にしたときだけ渡します。注文の備考は渡しません。</li>
                <li>売上の集計を見られるのは、「会計」の権限のある人のアクセスキーだけです。アクセスキーでできるのは、作った人のその店での今の権限の範囲だけで、権限が外されると、その時から使えなくなります。</li>
                <li>商品の説明など店で書いた文は「店のデータ」であることを示して渡し、AI エージェントへの指示として扱わないよう伝えます。</li>
                <li>AI エージェントの操作は 1 回ごとに記録に残ります。1 つのアクセスキーで 1 分に 60 回まで、変更の操作は 1 分に 10 回までです。</li>
            </BulletList>
        </AppPanel>
    </SettingsLayout>
</template>
