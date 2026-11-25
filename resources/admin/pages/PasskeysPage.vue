<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api.js';
import { createPasskey } from '../webauthn.js';
import { titleOf } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { formatDate } from '../format.js';
import { useOperation } from '../operation.js';
import { session } from '../session.js';
import SinglePasskeyNotice from '../components/SinglePasskeyNotice.vue';
import AppButton from '../components/ui/AppButton.vue';
import AppField from '../components/ui/AppField.vue';
import AppInput from '../components/ui/AppInput.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import CellTitle from '../components/ui/CellTitle.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import SecretCodes from '../components/ui/SecretCodes.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';

const passkeys = ref(null);
const name = ref('');
const { pending, error, message, run: send } = useOperation();
const loadError = ref(null);
// 回復用のコード（ADR 0038）: 残りの数と、作った直後だけの平文
const recovery = ref(null);
const newCodes = ref(null);
// ログイン中の端末
const sessions = ref(null);

async function load() {
    const result = await api('GET', '/auth/passkeys');
    passkeys.value = result.passkeys;
    // ホームの通知も、足した・消した後の本数にそろえる
    session.passkeys = { count: result.count, synced: result.passkeys.some((passkey) => passkey.synced) };
    recovery.value = await api('GET', '/auth/recovery-codes');
    sessions.value = (await api('GET', '/auth/sessions')).sessions;
    // ホームのいつもと違うログインの通知も、終えた後にそろえる
    if (session.unusualSession && !sessions.value.some((item) => item.id === session.unusualSession.id)) {
        session.unusualSession = null;
    }
}

onMounted(async () => {
    try {
        await load();
    } catch (e) {
        loadError.value = e;
    }
});

function run(name, action, done) {
    return send(name, action, { done, then: load });
}

function add() {
    const label = name.value.trim();

    return run('add', async () => {
        const { options } = await api('GET', '/auth/passkeys/options');
        await api('POST', '/auth/passkeys', { name: label, credential: await createPasskey(options) });
        name.value = '';
    }, `「${label}」を追加しました。`);
}

function makeCodes() {
    return run('codes', async () => {
        const result = await api('POST', '/auth/recovery-codes');
        newCodes.value = result.codes;
    }, '回復用のコードを作りました。下の 10 個を控えてください。');
}

function remove(passkey) {
    return run(`remove-${passkey.id}`, () => api('DELETE', `/auth/passkeys/${encodeURIComponent(passkey.id)}`), `「${passkey.name}」を削除しました。このパスキーでログインしていたほかの端末もログアウトしました。`);
}

function endSession(item) {
    return run(`end-${item.id}`, () => api('DELETE', `/auth/sessions/${encodeURIComponent(item.id)}`), `${item.device} をログアウトしました。`);
}

function endOthers() {
    return run('others', () => api('POST', '/auth/sessions/others'),
        (result) => (result.ended > 0 ? `ほかの端末 ${result.ended} 台をログアウトしました。` : 'ほかにログイン中の端末はありませんでした。'));
}
</script>

<template>
    <PageHeader :help="helpLink('passkeys')" :title="titleOf('passkeys')" />
    <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
    <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>

    <ErrorState v-if="loadError" :error="loadError" />
    <AppPanel v-else title="登録済みのパスキー">
        <LoadingText v-if="passkeys === null && !error" />
        <template v-else-if="passkeys !== null">
            <SinglePasskeyNotice
                v-if="passkeys.length === 1"
                :owner="session.operator?.role === 'owner'"
                :synced="passkeys.some((passkey) => passkey.synced)"
                data-test="single-passkey"
            />
            <AppTable label="登録済みのパスキー">
                <thead>
                    <tr><th>名前</th><th>登録</th><th>最後に使った日時</th><th><span class="sr-only">操作</span></th></tr>
                </thead>
                <tbody>
                    <tr v-for="passkey in passkeys" :key="passkey.id" data-test="passkey-row">
                        <td><CellTitle :label="passkey.name" :sub="passkey.authenticator" /></td>
                        <td><AppText as="span" tone="muted">{{ formatDate(passkey.created_at) }}</AppText></td>
                        <td><AppText as="span" tone="muted">{{ formatDate(passkey.last_used_at) }}</AppText></td>
                        <td>
                            <div class="flex flex-wrap items-center justify-end gap-2" :title="passkeys.length <= 1 ? '最後のパスキーは削除できません' : null">
                                <ConfirmButton
                                    label="削除する"
                                    :disabled="pending !== null || passkeys.length <= 1"
                                    :loading="pending === `remove-${passkey.id}`"
                                    test="remove-passkey"
                                    @confirm="remove(passkey)"
                                >このパスキーではログインできなくなります。</ConfirmButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </AppTable>
        </template>
    </AppPanel>

    <AppPanel v-if="sessions" title="ログイン中の端末" description="この管理画面にログインしている端末です。心当たりの無い端末は、ログアウトさせてからパスキーを見直してください。パスキーを削除すると、そのパスキーでログインしていた端末もログアウトします。">
        <AppTable label="ログイン中の端末">
            <thead>
                <tr><th>端末</th><th>使ったパスキー</th><th>ログイン</th><th>最後の操作</th><th><span class="sr-only">操作</span></th></tr>
            </thead>
            <tbody>
                <tr v-for="item in sessions" :key="item.id" data-test="session-row">
                    <td>
                        <div class="flex flex-wrap items-center gap-2">
                            <span>{{ item.device }}</span>
                            <StatusBadge v-if="item.current" tone="ok">この画面</StatusBadge>
                            <StatusBadge v-if="item.unusual" tone="warn" data-test="session-unusual">いつもと違う接続元</StatusBadge>
                        </div>
                    </td>
                    <td><AppText as="span" tone="muted">{{ item.passkey ?? '削除済み' }}</AppText></td>
                    <td><AppText as="span" tone="muted">{{ formatDate(item.created_at) }}</AppText></td>
                    <td><AppText as="span" tone="muted">{{ formatDate(item.last_seen_at) }}</AppText></td>
                    <td>
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <ConfirmButton
                                v-if="!item.current"
                                label="ログアウトさせる"
                                :disabled="pending !== null"
                                :loading="pending === `end-${item.id}`"
                                test="end-session"
                                @confirm="endSession(item)"
                            >この端末の画面は、次の操作でログインの画面に戻ります。</ConfirmButton>
                        </div>
                    </td>
                </tr>
            </tbody>
        </AppTable>
        <div v-if="sessions.length > 1">
            <ConfirmButton
                label="ほかの端末を全部ログアウトさせる"
                :disabled="pending !== null"
                :loading="pending === 'others'"
                test="end-other-sessions"
                @confirm="endOthers"
            >この端末以外は、次の操作でログインの画面に戻ります。</ConfirmButton>
        </div>
    </AppPanel>

    <AppPanel v-if="recovery" title="回復用のコード" description="パスキーをすべてなくした時に、ログインの画面の「パスキーを使えないとき」から新しいパスキーを登録し直すためのコードです。1 つは 1 回だけ使えます。">
        <template v-if="newCodes">
            <AppNotice tone="warning" :live="false" data-test="codes-shown-once">この画面を離れると二度と見られません。紙か、パスワード管理ツールに控えてください。</AppNotice>
            <SecretCodes :codes="newCodes" label="回復用のコード" />
            <div>
                <AppButton size="sm" data-test="codes-saved" @click="newCodes = null">控えました</AppButton>
            </div>
        </template>
        <template v-else>
            <AppText tone="muted" data-test="codes-remaining">{{ recovery.created_at ? `使えるコードは残り ${recovery.remaining} 個です（${formatDate(recovery.created_at)} に作成）。` : 'まだ作っていません。作っておくと、パスキーをなくした時に、サーバーを操作しなくても管理画面に入り直せます。' }}</AppText>
            <div>
                <ConfirmButton
                    v-if="recovery.created_at"
                    label="作り直す"
                    :disabled="pending !== null"
                    :loading="pending === 'codes'"
                    test="make-codes"
                    @confirm="makeCodes"
                >前のコードは使えなくなります。</ConfirmButton>
                <AppButton v-else variant="primary" :disabled="pending !== null" :loading="pending === 'codes'" data-test="make-codes" @click="makeCodes">回復用のコードを作る</AppButton>
            </div>
        </template>
    </AppPanel>

    <AppPanel title="パスキーを追加" description="追加と削除の前に、パスキーでもう一度本人確認をします。">
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="add">
            <AppField label="名前" hint="どの端末のものか分かる名前にします" class="min-w-64 flex-1">
                <AppInput v-model="name" required maxlength="100" autocomplete="off" data-test="new-passkey-name" />
            </AppField>
            <AppButton type="submit" variant="primary" :disabled="pending !== null || name.trim() === ''" :loading="pending === 'add'" class="mb-5" data-test="add-passkey">追加する</AppButton>
        </form>
    </AppPanel>
</template>
