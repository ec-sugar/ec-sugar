<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { api, ApiError, describe } from '../api.js';
import { withConfirmation } from '../confirmation.js';
import { firstError, formatDate } from '../format.js';
import AppButton from '../components/ui/AppButton.vue';
import AppField from '../components/ui/AppField.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppText from '../components/ui/AppText.vue';
import AuthLayout from '../components/ui/AuthLayout.vue';
import BulletList from '../components/ui/BulletList.vue';
import CheckList from '../components/ui/CheckList.vue';
import ChoicePills from '../components/ui/ChoicePills.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import ProminentFacts from '../components/ui/ProminentFacts.vue';

// AI エージェントのつなぎの許可（OAuth の許可の画面。ADR 0101）。claude.ai などの「コネクタを追加」から、この画面へ来る。
// ログインが要る（していなければログインの後にここへ戻る。戻り先はルーターが自分の画面の経路だけを残す）。
// 名前はつなぐ側が名乗ったもので確認していないので小さく出し、コードを渡す行き先のホストを大きく出す。
// 許可はパスキーの直前の再確認の後（サーバーが 423 で求める）。許可・拒否のあとは、サーバーが登録した行き先から作った URL へ移るだけ
// （行き先の URL を画面で組み立てない）。店は見られる店の中からいくつでも選ぶ（既定は何も選ばない。ADR 0104）。
// 「書ける」は選んだ店の全部で運用以上の人だけ（サーバーも断る。ここは見せ方だけ）
const props = defineProps({ request: { type: String, default: null } });

const SCOPES = [{ value: 'read', label: '閲覧のみ' }, { value: 'write', label: '閲覧と変更' }];
const LEVELS = { read: '閲覧', operate: '運用', money: '会計' };
// /oauth/authorize が行き先へ送れない誤りのとき（client_id か行き先が登録と違う）。決まった名前だけを文にする
const INVALID = {
    invalid_client: '接続するアプリの登録が見つかりません。AI エージェントの画面から、もう一度接続し直してください。',
    invalid_redirect_uri: '戻り先の URL が、接続するアプリの登録と一致しません。このリクエストは受け付けられません。',
};

const detail = ref(null);
const loadError = ref(null);
const chosenShops = ref([]);
const scope = ref('read');
const busy = ref(null);
const error = ref(null);
const errors = ref({});
const leaving = ref(false);

const invalid = computed(() => {
    if (props.request) {
        return null;
    }

    const reason = new URLSearchParams(window.location.search).get('error');

    return INVALID[reason] ?? 'このリクエストは受け付けられません。AI エージェントの画面から、もう一度接続し直してください。';
});
const shopOptions = computed(() => (detail.value?.tenants ?? []).map((tenant) => ({ value: tenant.id, label: `${tenant.name || tenant.id}（あなたの権限: ${LEVELS[tenant.level] ?? tenant.level}）` })));
const chosen = computed(() => (detail.value?.tenants ?? []).filter((tenant) => chosenShops.value.includes(tenant.id)));
const canWrite = computed(() => chosen.value.length > 0 && chosen.value.every((tenant) => tenant.can_write === true));
// 大きく出すのは登録できる名前（例 claude.ai）。ホストと URL の全部は下に出す（似た名前のホストを見分けられるように）
const facts = computed(() => (detail.value === null ? [] : [{ label: '許可すると、ここへアクセスキーを渡します', value: detail.value.redirect.site }]));
// つなぐアプリが登録された日時。claude.ai などはつなぐたびに登録するので、ふつうは「いま登録されたばかり」
const registered = computed(() => {
    if (detail.value === null) {
        return '';
    }

    const minutes = Math.floor((Date.now() - new Date(detail.value.client.registered_at).getTime()) / 60000);

    return minutes < 60 ? `${Math.max(0, minutes)} 分前に登録されたばかり` : `${formatDate(detail.value.client.registered_at)} に登録`;
});

watch(canWrite, (value) => {
    if (!value) {
        scope.value = 'read';
    }
});

onMounted(async () => {
    if (!props.request) {
        return;
    }

    try {
        detail.value = (await api('GET', `/api/oauth-requests/${encodeURIComponent(props.request)}`)).request;
        // 店は既定で何も選ばない（うっかり全部の店を許さない。ADR 0104）
        chosenShops.value = [];
        // 既定はいつも「読むだけ」（頼みが書けるを求めていても、書けるは人が選んだ時だけ。PR 186 の見直しの低 7）
        scope.value = 'read';
    } catch (e) {
        loadError.value = e instanceof ApiError && e.status === 404
            ? 'このリクエストは期限が切れたか、すでに許可か拒否をした後です。AI エージェントの画面から、もう一度接続し直してください。'
            : describe(e);
    }
});

async function decide(kind) {
    busy.value = kind;
    error.value = null;
    errors.value = {};

    try {
        const path = `/api/oauth-requests/${encodeURIComponent(props.request)}/${kind}`;
        const result = kind === 'approve'
            ? await withConfirmation(() => api('POST', path, { tenants: chosenShops.value, scope: canWrite.value ? scope.value : 'read' }))
            : await api('POST', path);
        leaving.value = true;
        // サーバーが登録した行き先から作った URL だけへ移る
        window.location.assign(result.redirect_to);
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
            errors.value = e.body?.errors ?? {};
        }

        error.value = e instanceof ApiError && e.status === 404
            ? 'このリクエストは期限が切れたか、すでに許可か拒否をした後です。AI エージェントの画面から、もう一度接続し直してください。'
            : describe(e);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <AuthLayout title="AI エージェントの接続" heading="AI エージェントに店を接続します" lead="claude.ai などの AI エージェントが、あなたの権限の範囲で店のデータを見る・変更するための許可です。">
        <AppNotice v-if="invalid" tone="error" data-test="oauth-invalid">{{ invalid }}</AppNotice>
        <AppNotice v-else-if="loadError" tone="error" data-test="oauth-load-error">{{ loadError }}</AppNotice>
        <LoadingText v-else-if="detail === null" />
        <template v-else>
            <ProminentFacts :items="facts" data-test="oauth-host" />
            <AppText size="sm" tone="muted" break-all data-test="oauth-redirect-uri">戻り先の URL（全体）: {{ detail.redirect.uri }}</AppText>
            <AppText size="sm" tone="muted" data-test="oauth-client-name">
                接続するアプリが記載した名前: 「{{ detail.client.name }}」（この名前は、接続する側が自分で記載したもので、EC-SUGAR は確認していません）。{{ registered }}。
            </AppText>
            <AppNotice tone="warning" data-test="oauth-host-check">
                今ご自分で AI エージェントの「コネクタを追加」を押していなければ、「拒否する」を押してください（人から送られてきたリンクでは許可しないでください）。
                上の戻り先が、いま使っている AI エージェントのサービス（例 claude.ai・chatgpt.com）かも確認してください。
            </AppNotice>
            <AppNotice v-if="detail.redirect.loopback" tone="info" data-test="oauth-loopback">戻り先はこのパソコンの中（{{ detail.redirect.host }}）です。手元のツール（ターミナルの claude コマンドなど）から接続するときは、この形になります。</AppNotice>
            <AppNotice v-if="detail.tenants.length === 0" tone="error" data-test="oauth-no-shop">あなたが見られる店がありません。オーナーに店の権限を依頼してください。</AppNotice>
            <template v-else>
                <AppField as="div" label="接続する店" hint="AI エージェントが見る・変更できるのは、ここで選んだ店だけです。後から店を追加するときは、接続し直してください。" :error="firstError(errors, 'tenants')">
                    <CheckList v-model="chosenShops" :options="shopOptions" legend="接続する店" test-prefix="oauth-shop" />
                </AppField>
                <AppField
                    as="div"
                    label="できること"
                    :hint="chosen.length === 0
                        ? '先に店を選んでください。'
                        : !canWrite
                        ? '選んだ店のどれかで、あなたの権限は「閲覧」です。「閲覧と変更」は、選んだすべての店で「運用」か「会計」の権限を持つときだけ選べます。'
                        : scope === 'write'
                            ? '商品・在庫・発送・取消・返金・クーポンを変更できます。店の設定が「承認してから」なら提案を作るだけで、取消・返金・クーポンはいつも承認が必要です。'
                            : '商品・在庫・注文・売上（「会計」の権限のある人だけ）を見るだけです。'"
                    :error="firstError(errors, 'scope')"
                >
                    <ChoicePills v-model="scope" legend="できること" :options="SCOPES" :disabled="!canWrite" test-prefix="oauth-scope" />
                </AppField>
                <BulletList size="sm">
                    <li>アクセスキーは 1 時間ごとに自動で切り替わり、30 日使わないと無効になります。使い続けても、「閲覧と変更」は 90 日・「閲覧のみ」は 365 日で無効になります。「設定」の「AI エージェント」でいつでも取り消せます。</li>
                    <li>できることは、それぞれの店でのあなたの今の権限の範囲だけです。権限を下げられたときや、ほかの端末をすべてログアウトしたときなどは、接続も切れます。</li>
                    <li>許可の前に、パスキーでもう一度本人確認をします。</li>
                </BulletList>
            </template>
            <AppNotice v-if="error" tone="error" data-test="oauth-error">{{ error }}</AppNotice>
            <AppNotice v-if="leaving" tone="info" data-test="oauth-leaving">AI エージェントの画面へ戻ります…</AppNotice>
            <div class="flex flex-wrap gap-3">
                <AppButton v-if="detail.tenants.length > 0" variant="primary" size="lg" :disabled="busy !== null || leaving || chosenShops.length === 0" :loading="busy === 'approve'" data-test="oauth-approve" @click="decide('approve')">許可する</AppButton>
                <AppButton size="lg" :disabled="busy !== null || leaving" :loading="busy === 'deny'" data-test="oauth-deny" @click="decide('deny')">拒否する</AppButton>
            </div>
        </template>
    </AuthLayout>
</template>
