<script setup>
import { computed, onMounted, ref } from 'vue';
import { api, describe as describeError } from '../api.js';
import { ConfirmationCancelled, withConfirmation } from '../confirmation.js';
import { formatDate } from '../format.js';
import { createKit, KitError, kitFileName, openKit, passphraseProblem, PASSPHRASE_MIN, proofOf, saveKit } from '../recovery-kit.js';
import AppButton from './ui/AppButton.vue';
import AppField from './ui/AppField.vue';
import AppInput from './ui/AppInput.vue';
import AppNotice from './ui/AppNotice.vue';
import AppPanel from './ui/AppPanel.vue';
import AppText from './ui/AppText.vue';
import CodeText from './ui/CodeText.vue';
import FileDrop from './ui/FileDrop.vue';
import StepList from './ui/StepList.vue';

// 復元のキット（ADR 0111）。運営ダッシュボードに置く。オーナーだけ（API が断る）。
//
// 1. 合い言葉を決めて「キットを作って保存」: APP_KEY を受け取り（直前の本人確認の後）、ブラウザの中で開ける鍵を作って、キットを保存する
// 2. 保存したファイルを選び、合い言葉をもう一度入れて「確認する」: ブラウザの中で開け直し、開けられた時だけサーバーへ通知する
//    （サーバーへ送るのは、開ける鍵の公開の半分・指紋・印だけ。合い言葉・秘密の鍵・キットは送らない）
//
// 合い言葉と APP_KEY は、この部品の変数に必要な間だけ置き、使い終えたら空にする。保存領域・URL・console には置かない
const emit = defineEmits(['done']);

const state = ref(null);
const loadError = ref(null);
const passphrase = ref('');
const passphraseAgain = ref('');
const creating = ref(false);
const created = ref(null);
const file = ref(null);
const checkPassphrase = ref('');
const checking = ref(false);
const notice = ref(null);

const createProblem = computed(() => {
    if (passphrase.value === '') {
        return null;
    }

    return passphraseProblem(passphrase.value) ?? (passphraseAgain.value !== '' && passphraseAgain.value !== passphrase.value ? '2 つのパスフレーズが違います。' : null);
});
const canCreate = computed(() => passphraseProblem(passphrase.value) === null && passphraseAgain.value === passphrase.value);
const canCheck = computed(() => file.value !== null && checkPassphrase.value !== '');
// サーバーがいまバックアップの暗号化に使っている鍵が、このキットの鍵と違う（手元で作った gpg の鍵を置いたサーバー・キットを作り直した後）。
// その時、このキットではサーバーの外のバックアップを開けない。黙って「戻せる」と言わない
const otherKey = computed(() => state.value?.fingerprint && state.value?.host_fingerprint && state.value.host_fingerprint !== state.value.fingerprint);
const sameKey = computed(() => state.value?.fingerprint && state.value?.host_fingerprint === state.value.fingerprint);

async function load() {
    try {
        state.value = await api('GET', '/api/operations/recovery-kit');
        loadError.value = null;
    } catch (error) {
        loadError.value = describeError(error);
    }
}

function fail(prefix, error) {
    // 本人確認を取り消しただけなら、誤りとして出さない
    if (!(error instanceof ConfirmationCancelled)) {
        notice.value = { tone: 'error', text: `${prefix}${error?.userMessage ?? describeError(error)}` };
    }
}

async function create() {
    creating.value = true;
    notice.value = null;

    try {
        const material = await withConfirmation(() => api('POST', '/api/operations/recovery-kit/material'));
        const kit = await createKit({ appKey: material.app_key, server: material.server, passphrase: passphrase.value });
        const name = kitFileName(material.server);
        saveKit(kit.bytes, name);
        created.value = { name, fingerprint: kit.fingerprint };
        // 合い言葉はここで捨てる（次の手順で、もう一度入れてもらう。控えた合い言葉で本当に開くかを確認するため）
        passphrase.value = '';
        passphraseAgain.value = '';
    } catch (error) {
        fail('キットを作れませんでした。', error);
    } finally {
        creating.value = false;
    }
}

function choose(files) {
    file.value = files[0] ?? null;
    notice.value = null;
}

async function check() {
    checking.value = true;
    notice.value = null;

    try {
        const opened = await openKit(new Uint8Array(await file.value.arrayBuffer()), checkPassphrase.value);

        // いま作ったキットと別のファイル（前に作った古いキットなど）を選んでいたら、止める（古い方を「確認した」と数えない）
        if (created.value !== null && created.value.fingerprint !== opened.fingerprint) {
            throw new KitError('選んだファイルは、いま作ったキットではありません。いま保存したファイルを選んでください。');
        }

        const body = { public_key: opened.publicKey, fingerprint: opened.fingerprint, proof: await proofOf(opened.appKey, opened.fingerprint) };
        state.value = await withConfirmation(() => api('POST', '/api/operations/recovery-kit', body));
        checkPassphrase.value = '';
        file.value = null;
        created.value = null;
        notice.value = { tone: 'success', text: '保存したキットを開いて確認しました。このファイルとパスフレーズで、APP_KEY を復元できます。' };
        emit('done');
    } catch (error) {
        fail('確認できませんでした。', error);
    } finally {
        checking.value = false;
    }
}

onMounted(load);
</script>

<template>
    <AppPanel title="復元のキット" description="サーバーを丸ごと失った時に、バックアップから復元するのに必要な鍵をまとめた 1 つのファイルです。お客さんの個人情報の復号の鍵（APP_KEY）は、バックアップには入りません。このキットが無いと、バックアップがあっても復元できません" data-test="recovery-kit">
        <AppNotice v-if="loadError" tone="error" :live="false">{{ loadError }}</AppNotice>
        <template v-else-if="state">
            <AppNotice v-if="state.fingerprint" tone="success" :live="false" data-test="recovery-kit-done">
                <span>キットを作って確認してあります（{{ formatDate(state.registered_at) }}）。復号の鍵の指紋 <CodeText data-test="recovery-kit-fingerprint">{{ state.fingerprint }}</CodeText></span>
                <span v-if="sameKey">サーバーの外のバックアップも、このキットの鍵で暗号化して送っています。<strong>このキットは持ち続けてください</strong>。下から作り直しても、サーバーが暗号化に使う鍵は替わりません（替える手順は説明書の「復元のキットを管理画面で作る」）。</span>
                <span v-else>パスフレーズを忘れた・ファイルをなくした時は、下からもう一度作れます。</span>
            </AppNotice>
            <AppNotice v-if="otherKey" tone="warning" :live="false" data-test="recovery-kit-other-key">
                <span>サーバーの外のバックアップは、<strong>このキットとは別の鍵</strong>（指紋 <CodeText>{{ state.host_fingerprint }}</CodeText>）で暗号化して送っています。このキットで復元できるのは APP_KEY だけで、バックアップを復号するには、その鍵（手元の gpg の鍵か、それを入れた前のキット）が必要です。</span>
            </AppNotice>
            <AppNotice v-else-if="!state.available" tone="warning" :live="false">このサーバーの APP_KEY は、キットに入れられる形式ではありません。サーバーで <CodeText>sudo ec-sugar app-key</CodeText> を実行してバックアップを取ってください。</AppNotice>

            <StepList v-if="state.available">
                <li>
                    <div class="flex flex-col gap-3">
                        <AppText>パスフレーズを決めて、キットを作ります。パスフレーズはキットを開く時に必要です。サーバーには送らず、どこにも残りません（忘れるとキットを開けません）。</AppText>
                        <AppField label="キットのパスフレーズ" :hint="`${PASSPHRASE_MIN} 文字以上。パスワード管理ツールで作って保存するのが確実です`" :error="createProblem">
                            <AppInput v-model="passphrase" type="password" autocomplete="new-password" data-test="recovery-kit-passphrase" />
                        </AppField>
                        <AppField label="キットのパスフレーズ（もう一度）">
                            <AppInput v-model="passphraseAgain" type="password" autocomplete="new-password" data-test="recovery-kit-passphrase-again" />
                        </AppField>
                        <div>
                            <AppButton variant="primary" :loading="creating" :disabled="!canCreate" data-test="recovery-kit-create" @click="create">キットを作って保存</AppButton>
                        </div>
                        <AppNotice v-if="created" tone="info" :live="false" data-test="recovery-kit-created">
                            <span><CodeText>{{ created.name }}</CodeText> を保存しました。USB メモリとクラウドのストレージなど、<strong>このパソコンの外の 2 か所</strong>に置いてください。パスフレーズは、キットとは別の場所に保管してください。</span>
                        </AppNotice>
                    </div>
                </li>
                <li>
                    <div class="flex flex-col gap-3">
                        <AppText>保存したファイルを選び、保管したパスフレーズを入れて確認します。ブラウザの中で開いて確認するだけで、ファイルもパスフレーズもサーバーには送りません。</AppText>
                        <FileDrop accept=".gpg" :label="file ? file.name : '保存したキットのファイルを選ぶ'" hint="ec-sugar-recovery-kit-….gpg" data-test="recovery-kit-file" @select="choose" />
                        <AppField label="キットのパスフレーズ">
                            <AppInput v-model="checkPassphrase" type="password" autocomplete="off" data-test="recovery-kit-check-passphrase" />
                        </AppField>
                        <div>
                            <AppButton variant="primary" :loading="checking" :disabled="!canCheck" data-test="recovery-kit-check" @click="check">開いて確認する</AppButton>
                        </div>
                    </div>
                </li>
            </StepList>
            <AppNotice v-if="notice" :tone="notice.tone" data-test="recovery-kit-notice">{{ notice.text }}</AppNotice>
        </template>
    </AppPanel>
</template>
