<script setup>
import { ref } from 'vue';
import { api } from '../api.js';
import { crumbsFor, titleOf } from '../navigation.js';
import { formatDate } from '../format.js';
import { session } from '../session.js';
import AppPanel from '../components/ui/AppPanel.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';

// ログインの履歴。オーナーは全員、スタッフは自分の分。新しい順に 200 件
const KINDS = { login_succeeded: ['ok', 'ログイン'], login_failed: ['danger', 'ログインの失敗'], logout: ['neutral', 'ログアウト'] };
const logins = ref(null);
const error = ref(null);

api('GET', '/api/login-history').then((data) => { logins.value = data.logins; }).catch((e) => { error.value = e; });
</script>

<template>
    <PageHeader :title="titleOf('login-history')" :crumbs="crumbsFor('login-history')" />
    <ErrorState v-if="error" :error="error" />
    <LoadingText v-else-if="logins === null" />
    <AppPanel v-else :title="session.operator?.role === 'owner' ? '全員のログイン' : 'あなたのログイン'" description="身に覚えのないログインや失敗が続いていたら、パスキーを見直し、オーナーに連絡してください。">
        <AppTable label="ログインの履歴">
            <thead>
                <tr><th>日時</th><th>メンバー</th><th>操作</th><th>接続元</th></tr>
            </thead>
            <tbody>
                <tr v-for="(login, index) in logins" :key="index" data-test="login-row">
                    <td><AppText as="span" tone="muted" nowrap>{{ formatDate(login.occurred_at) }}</AppText></td>
                    <td>{{ login.operator?.name ?? '（不明）' }}</td>
                    <td><StatusBadge :tone="KINDS[login.type]?.[0] ?? 'neutral'">{{ KINDS[login.type]?.[1] ?? login.type }}</StatusBadge></td>
                    <td><AppText as="span" tone="muted">{{ login.ip ?? '—' }}</AppText></td>
                </tr>
            </tbody>
        </AppTable>
    </AppPanel>
</template>
