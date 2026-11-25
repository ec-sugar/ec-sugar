<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { api, describe } from '../api.js';
import { canInTenant } from '../tenants.js';
import { firstError } from '../format.js';
import { useOperation } from '../operation.js';
import { helpRoute } from '../help/index.js';
import AppButton from './ui/AppButton.vue';
import AppField from './ui/AppField.vue';
import AppInput from './ui/AppInput.vue';
import AppNotice from './ui/AppNotice.vue';
import AppPanel from './ui/AppPanel.vue';
import AppText from './ui/AppText.vue';
import ConfirmButton from './ui/ConfirmButton.vue';
import FormActions from './ui/FormActions.vue';
import InsetSection from './ui/InsetSection.vue';
import StatusBadge from './ui/StatusBadge.vue';

// 店のお支払い（Stripe）。SaaS 版は「Stripe へ接続」（ADR 0022）。運営の鍵が無い環境（パッケージ版）は店の鍵を貼る（ADR 0019）。
// 置く・外す・接続・解除はパスキーでの再確認の後。鍵はサーバーから返らない（末尾 4 文字と test / live だけ）
const props = defineProps({ tenant: { type: String, required: true } });
const route = useRoute();
const router = useRouter();

const status = ref(null);
const connect = ref(null);
const webhook = ref(null);
const dashboard = ref(null);
const keys = reactive({ secret_key: '', webhook_secret: '' });
// 公開の前に試すテスト用の鍵（ADR 0096）。ふだんの鍵と別に置き、準備中で、ふだんの鍵が本番か無いときだけ使う
const testKeys = reactive({ secret_key: '', webhook_secret: '' });
const testDashboard = ref(null);
// pending は今動いている操作（そのボタンだけ回す）
const { pending, errors, error, message, run: send, leave } = useOperation();
// 登録の画面から戻った直後の自動の確認し直し（回数と間隔）
const CHECK_TIMES = 8;
const CHECK_INTERVAL_MS = 4000;
const checking = ref(false);
const busy = computed(() => pending.value !== null || checking.value);
const copied = ref(null);

// Stripe のダッシュボードの画面（サーバーが渡す。テスト用の鍵の店はテスト環境の画面へ）
function stripeLink(page) {
    return dashboard.value?.[page] ?? null;
}

async function copy(text, name) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = name;
        setTimeout(() => { copied.value = null; }, 2000);
    } catch {
        error.value = 'コピーできませんでした。文字を選んでコピーしてください。';
    }
}

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/payment-settings`);

// 保存済みのときに欄に薄く出す伏せ字（本物の鍵ではない。見せるのは test / live と末尾 4 文字だけ）。空欄だと保存できていないように見えるため
const DOTS = '••••••••••••••••';
const secretPlaceholder = computed(() => (status.value?.configured ? `sk_${status.value.mode}_${DOTS}${status.value.key_hint}` : null));
const webhookPlaceholder = computed(() => (status.value?.configured ? `whsec_${DOTS}` : null));

// 接続した店の登録の状態（Stripe の連結アカウント）
const CONNECT_STATES = {
    onboarding: { tone: 'warn', label: '登録の途中', text: 'Stripe の登録の画面で、事業の情報と入金先の口座を入力してください。登録が終わるまで、ショップではお支払いに進めません。入力し終えたばかりなら、Stripe の審査が済むと自動でここの表示が変わります（10 分ほどかかることがあります）。', action: '登録を続ける' },
    active: { tone: 'ok', label: 'お支払いを受けられます', text: 'ショップの注文は、接続した Stripe のアカウントで受け付けます。売上はこのアカウントから入金されます。', action: null },
    restricted: { tone: 'danger', label: 'Stripe が情報を求めています', text: 'Stripe から求められた情報が期限までに提出されていないため、お支払いや入金が止まっています。Stripe の画面で情報を入力してください。', action: 'Stripe で情報を入力する' },
};
const connectState = computed(() => (connect.value?.connected ? CONNECT_STATES[connect.value.status] ?? CONNECT_STATES.onboarding : null));

function fill(data) {
    status.value = data.stripe;
    connect.value = data.connect;
    webhook.value = data.webhook;
    dashboard.value = data.dashboard;
    testDashboard.value = data.test_dashboard ?? null;
}

async function load() {
    error.value = null;

    try {
        fill(await api('GET', base.value));
    } catch (e) {
        error.value = describe(e);
    }
}

// 登録の画面の URL が返ったら Stripe へ移る（終わると、この画面に ?stripe=return で戻る）
function run(action, done, name) {
    return send(name, action, { done, then: (data) => (data?.url ? leave(data.url) : fill(data)) });
}

function startConnect() {
    return run(() => api('POST', `${base.value}/stripe-connect`), null, 'connect');
}

function disconnect() {
    return run(() => api('DELETE', `${base.value}/stripe-connect`), 'Stripe との接続を解除しました。ショップではお支払いに進めなくなります（Stripe のアカウントは残ります）。', 'disconnect');
}

// 空の欄はブラウザに黙って止めさせず、どの欄が空かを画面に出す
function missing() {
    const found = {};

    if (keys.secret_key.trim() === '') {
        found.secret_key = ['秘密鍵が空です。手順 1 で Stripe の「シークレットキー」をコピーして貼ってください。'];
    }

    if (keys.webhook_secret.trim() === '') {
        found.webhook_secret = ['Webhook の署名の鍵が空です。手順 3 で「署名シークレット」（whsec_ で始まる）をコピーして貼ってください。'];
    }

    return found;
}

async function save() {
    const empty = missing();

    if (Object.keys(empty).length > 0) {
        errors.value = empty;
        error.value = '空の欄があります。欄の下の案内を見てください。';
        message.value = null;

        return;
    }

    // 鍵は画面に残さない（保存できなかったときは、直せるように残す）
    if (await run(() => api('PUT', `${base.value}/stripe`, { secret_key: keys.secret_key, webhook_secret: keys.webhook_secret }), 'Stripe の鍵を保存しました。', 'save')) {
        Object.assign(keys, { secret_key: '', webhook_secret: '' });
    }
}

function remove() {
    return run(() => api('DELETE', `${base.value}/stripe`), 'Stripe の鍵を削除しました。ショップではお支払いに進めなくなります。', 'remove');
}

async function saveTest() {
    const empty = {};

    if (testKeys.secret_key.trim() === '') {
        empty.test_secret_key = ['テスト用の秘密鍵が空です。Stripe のテスト環境の「シークレットキー」（sk_test_ で始まる）を貼ってください。'];
    }

    if (testKeys.webhook_secret.trim() === '') {
        empty.test_webhook_secret = ['テスト用の Webhook の署名の鍵が空です。テスト環境で作った Webhook の送信先の「署名シークレット」を貼ってください。'];
    }

    if (Object.keys(empty).length > 0) {
        errors.value = empty;
        error.value = '空の欄があります。欄の下の案内を見てください。';
        message.value = null;

        return;
    }

    if (await run(() => api('PUT', `${base.value}/stripe-test`, { secret_key: testKeys.secret_key, webhook_secret: testKeys.webhook_secret }), 'テスト用の鍵を保存しました。ショップが準備中のあいだ、テストモードで注文を試せます。', 'save-test')) {
        Object.assign(testKeys, { secret_key: '', webhook_secret: '' });

        return;
    }

    // サーバーの欄の名前（secret_key・webhook_secret）を、テスト用の欄に読み替える（同じ画面にふだんの鍵の欄もあるため）
    if (errors.value) {
        errors.value = Object.fromEntries(Object.entries(errors.value).map(([key, value]) => [`test_${key}`, value]));
    }
}

function removeTest() {
    return run(() => api('DELETE', `${base.value}/stripe-test`), 'テスト用の鍵を削除しました。', 'remove-test');
}


// Stripe の登録の画面から戻ってきた（return: 状態を問い合わせ直す / refresh: リンクの期限が切れたので作り直す）
async function handleReturn() {
    const back = route.query.stripe;

    if (back !== 'return' && back !== 'refresh') {
        return;
    }

    await router.replace({ query: { ...route.query, stripe: undefined } });

    if (back === 'refresh') {
        await startConnect();

        return;
    }

    // 登録を終えて戻った直後は、Stripe がまだ確認していることが多い（数秒〜十数秒）。少しの間、自動で確認し直す
    checking.value = true;

    try {
        for (let i = 0; i < CHECK_TIMES; i++) {
            fill(await api('POST', `${base.value}/stripe-connect/refresh`));

            if (connect.value?.status !== 'onboarding' || i === CHECK_TIMES - 1) {
                break;
            }

            await new Promise((resolve) => setTimeout(resolve, CHECK_INTERVAL_MS));
        }

        message.value = connect.value?.status === 'active' ? 'Stripe へ接続しました。ショップでお支払いを受けられます。' : null;
    } catch (e) {
        error.value = describe(e);
    } finally {
        checking.value = false;
    }
}

watch(() => props.tenant, async () => {
    await load();
    await handleReturn();
}, { immediate: true });
</script>

<template>
    <AppPanel data-test="stripe-panel" title="お支払い（Stripe）" description="カードの情報は Stripe の画面で入力され、この店のサーバーには届きません。お支払いの設定を変える前に、パスキーでもう一度確認します。">
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>
        <AppNotice v-if="!canInTenant(tenant, 'money')" tone="info" data-test="stripe-no-permission">お支払いの設定は「会計」の権限のある人だけが変えられます。</AppNotice>
        <AppText tone="soft"><RouterLink :to="helpRoute('payments', null, tenant)" class="underline underline-offset-2" data-test="help-payments-link">始め方の説明（スクリーンショットつき）</RouterLink></AppText>

        <!-- SaaS 版: Stripe へ接続 -->
        <InsetSection v-if="connect?.available && canInTenant(tenant, 'money')" title="Stripe へ接続" data-test="stripe-connect">
            <div v-if="checking" class="flex items-center gap-2 text-sm text-ink-muted" role="status" data-test="stripe-connect-checking">
                <svg class="size-4 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" />
                    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                </svg>
                Stripe が登録の内容を確認しています。数秒〜十数秒お待ちください。
            </div>
            <template v-else-if="connectState">
                <div class="flex flex-wrap items-center gap-3" data-test="stripe-connect-status">
                    <StatusBadge :tone="connectState.tone">{{ connectState.label }}</StatusBadge>
                    <StatusBadge v-if="connect.mode === 'test'" tone="neutral">テスト用</StatusBadge>
                    <AppText tone="muted">Stripe のアカウント（末尾 <span class="tabular">{{ connect.account_hint }}</span>）</AppText>
                </div>
                <AppText>{{ connectState.text }}</AppText>
                <div class="flex flex-wrap justify-end gap-2">
                    <ConfirmButton label="接続を解除" :disabled="busy" :loading="pending === 'disconnect'" test="disconnect-stripe" @confirm="disconnect" />
                    <AppButton v-if="connectState.action" variant="primary" :disabled="busy" :loading="pending === 'connect'" data-test="continue-connect" @click="startConnect">{{ connectState.action }}</AppButton>
                </div>
            </template>
            <template v-else>
                <ol class="flex list-decimal flex-col gap-1 pl-5 text-sm text-ink" data-test="stripe-connect-steps">
                    <li>下の「Stripe へ接続」を押します（パスキーでもう一度確認します）</li>
                    <li>Stripe の登録の画面で、事業の情報と入金先の口座を入力します（Stripe のアカウントをお持ちなら、ログインして使えます）</li>
                    <li>終わるとこの画面に戻り、ショップでお支払いを受けられるようになります</li>
                </ol>
                <AppText tone="soft">鍵のコピーや Webhook の設定は不要です。売上はこの店の Stripe のアカウントへ入り、Stripe の手数料もこのアカウントから引かれます。</AppText>
                <div class="flex justify-end">
                    <AppButton variant="primary" :disabled="busy" :loading="pending === 'connect'" data-test="start-connect" @click="startConnect">Stripe へ接続</AppButton>
                </div>
            </template>
        </InsetSection>

        <!-- 鍵を貼る形（パッケージ版。SaaS 版では詳しい方向けに畳む） -->
        <component :is="connect?.available ? 'details' : 'div'" v-if="canInTenant(tenant, 'money')" class="flex flex-col gap-5" data-test="stripe-keys">
            <summary v-if="connect?.available" class="cursor-pointer text-sm text-ink-muted">自分の Stripe の鍵で設定する（詳しい方向け）</summary>
            <form class="flex flex-col gap-5" :class="connect?.available ? 'mt-4' : ''" novalidate @submit.prevent="save">
                <div v-if="status" class="flex flex-wrap items-center gap-3" data-test="stripe-status">
                    <template v-if="status.configured">
                        <StatusBadge :tone="status.mode === 'live' ? 'accent' : 'warn'">{{ status.mode === 'live' ? '本番の鍵' : 'テスト用の鍵' }}</StatusBadge>
                        <AppText tone="muted">末尾 <span class="tabular">{{ status.key_hint }}</span> の鍵を使っています。</AppText>
                    </template>
                    <AppText v-else tone="soft">まだ設定していません。設定するまで、ショップではお支払いに進めません。</AppText>
                </div>

                <!-- 手順（Stripe の画面の名前は、Stripe のダッシュボードの日本語の表示に合わせる） -->
                <ol class="flex flex-col gap-5" data-test="stripe-key-steps">
                    <li class="flex flex-col gap-2">
                        <AppText><span class="font-medium">1. 秘密鍵をコピーする</span>　Stripe のダッシュボードの「<a :href="stripeLink('apikeys')" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2">API キー</a>」で、「シークレットキー」（sk_test_ / sk_live_ で始まる）を表示してコピーし、下の「秘密鍵」に貼ります。</AppText>
                    </li>
                    <li class="flex flex-col gap-2">
                        <AppText><span class="font-medium">2. Webhook の送信先を作る</span>　「<a :href="stripeLink('webhooks')" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2">Webhook</a>」で「送信先を追加」を押し、次のとおりに選びます（「お客様のアカウント」「スナップショット」のまま）。</AppText>
                        <dl v-if="webhook?.url" class="grid grid-cols-1 gap-3 rounded-lg bg-subtle p-4 text-sm" data-test="stripe-webhook">
                            <div class="flex flex-col gap-1">
                                <dt class="text-xs text-ink-soft">エンドポイント URL</dt>
                                <dd class="flex flex-wrap items-center gap-2"><code class="font-mono text-xs break-all">{{ webhook.url }}</code><AppButton size="sm" variant="ghost" data-test="copy-webhook-url" @click="copy(webhook.url, 'url')">{{ copied === 'url' ? 'コピーしました' : 'コピー' }}</AppButton></dd>
                            </div>
                            <div class="flex flex-col gap-1">
                                <dt class="text-xs text-ink-soft">選ぶイベント（「Checkout」の中の 4 つ・「Payment Intent」の中の 2 つ・「Refund」の中の 3 つ）</dt>
                                <dd><ul class="flex flex-col gap-0.5 font-mono text-xs"><li v-for="event in webhook.events" :key="event">{{ event }}</li></ul></dd>
                            </div>
                        </dl>
                    </li>
                    <li class="flex flex-col gap-2">
                        <AppText><span class="font-medium">3. 署名の鍵を貼る</span>　作った送信先の画面で「署名シークレット」（whsec_ で始まる）を表示してコピーし、下の「Webhook の署名の鍵」に貼ります。<span class="text-ink-soft">先に 1 の秘密鍵をコピーし直すと、署名の鍵が上書きされるのでご注意ください。</span></AppText>
                    </li>
                </ol>

                <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <AppField label="秘密鍵" hint="sk_test_ / sk_live_ で始まる（手順 1）" :error="firstError(errors, 'secret_key')">
                        <AppInput v-model="keys.secret_key" type="password" autocomplete="off" spellcheck="false" :placeholder="secretPlaceholder" :aria-invalid="!!firstError(errors, 'secret_key')" data-test="stripe-secret-key" />
                    </AppField>
                    <AppField label="Webhook の署名の鍵" hint="whsec_ で始まる（手順 3）" :error="firstError(errors, 'webhook_secret')">
                        <AppInput v-model="keys.webhook_secret" type="password" autocomplete="off" spellcheck="false" :placeholder="webhookPlaceholder" :aria-invalid="!!firstError(errors, 'webhook_secret')" data-test="stripe-webhook-secret" />
                    </AppField>
                </div>

                <FormActions>
                    <ConfirmButton v-if="status?.configured" label="鍵を削除" confirm-label="削除" :disabled="busy" :loading="pending === 'remove'" test="remove-stripe" @confirm="remove" />
                    <AppButton type="submit" :disabled="busy" :loading="pending === 'save'" data-test="save-stripe">{{ status?.configured ? '鍵を置き換える' : '鍵を保存する' }}</AppButton>
                </FormActions>
            </form>
        </component>

        <!-- 公開の前に試すテスト用の鍵（ADR 0096） -->
        <InsetSection v-if="canInTenant(tenant, 'money') && status" title="公開の前に試すテスト用の鍵" data-test="stripe-test-key">
            <AppText>本番の鍵を使う店でも、ショップが準備中のあいだだけ、この鍵で注文を最後まで試せます（Stripe のテストのモード。本物のお金は動きません）。ショップを公開すると、新しい注文にはこの鍵を使いません。</AppText>
            <div class="flex flex-wrap items-center gap-3" data-test="stripe-test-status">
                <template v-if="status.test_key?.configured">
                    <StatusBadge :tone="status.test_key.in_use ? 'warn' : 'neutral'">{{ status.test_key.in_use ? '今はテスト用の鍵で注文を受けます' : '登録済み（今は使っていません）' }}</StatusBadge>
                    <AppText tone="muted">末尾 <span class="tabular">{{ status.test_key.key_hint }}</span></AppText>
                </template>
                <AppText v-else tone="soft">登録していません。</AppText>
            </div>
            <AppText tone="soft">Stripe のダッシュボードを「テスト環境」に切り替えて、<a :href="testDashboard?.apikeys" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2">テスト環境の API キー</a>の「シークレットキー」と、<a :href="testDashboard?.webhooks" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2">テスト環境の Webhook</a> で上と同じ送信先（同じ URL・同じイベント）を作り、その「署名シークレット」を貼ります。テストした注文は、注文の画面に「テストの支払い」と出ます。商品を送らずに取り消してください。</AppText>
            <form class="flex flex-col gap-5" novalidate @submit.prevent="saveTest">
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <AppField label="テスト用の秘密鍵" hint="sk_test_ で始まる" :error="firstError(errors, 'test_secret_key')">
                        <AppInput v-model="testKeys.secret_key" type="password" autocomplete="off" spellcheck="false" :placeholder="status.test_key?.configured ? `sk_test_${DOTS}${status.test_key.key_hint}` : null" data-test="stripe-test-secret" />
                    </AppField>
                    <AppField label="テスト用の Webhook の署名の鍵" hint="whsec_ で始まる" :error="firstError(errors, 'test_webhook_secret')">
                        <AppInput v-model="testKeys.webhook_secret" type="password" autocomplete="off" spellcheck="false" :placeholder="status.test_key?.configured ? `whsec_${DOTS}` : null" data-test="stripe-test-webhook" />
                    </AppField>
                </div>
                <FormActions>
                    <ConfirmButton v-if="status.test_key?.configured" label="テスト用の鍵を削除" confirm-label="削除" :disabled="busy" :loading="pending === 'remove-test'" test="remove-stripe-test" @confirm="removeTest" />
                    <AppButton type="submit" :disabled="busy" :loading="pending === 'save-test'" data-test="save-stripe-test">{{ status.test_key?.configured ? 'テスト用の鍵を置き換える' : 'テスト用の鍵を保存する' }}</AppButton>
                </FormActions>
            </form>
        </InsetSection>
    </AppPanel>
</template>
