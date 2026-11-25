<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api.js';
import { titleOf } from '../navigation.js';
import { rememberTenant } from '../tenants.js';
import { session } from '../session.js';
import RecoveryPendingNotice from '../components/RecoveryPendingNotice.vue';
import UnusualSessionNotice from '../components/UnusualSessionNotice.vue';
import SinglePasskeyNotice from '../components/SinglePasskeyNotice.vue';
import AppPager from '../components/ui/AppPager.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import CellTitle from '../components/ui/CellTitle.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import PageHeader from '../components/ui/PageHeader.vue';

const PER_PAGE = 50;
const route = useRoute();
const router = useRouter();
const tenants = ref(null);
const meta = ref(null);
const error = ref(null);

const page = computed(() => {
    const n = Number.parseInt(String(route.query.page ?? '1'), 10);

    return Number.isSafeInteger(n) && n >= 1 ? n : 1;
});
const lastPage = computed(() => (meta.value ? Math.max(1, Math.ceil(meta.value.total / meta.value.per_page)) : 1));

watch(page, async (n) => {
    error.value = null;

    try {
        const result = await api('GET', `/api/tenants?page=${n}&per_page=${PER_PAGE}`);
        tenants.value = result.tenants;
        meta.value = result.meta;
        result.tenants.forEach((tenant) => rememberTenant(tenant.id, tenant.name));
    } catch (e) {
        error.value = e;
    }
}, { immediate: true });

function go(n) {
    router.push({ query: n === 1 ? {} : { page: String(n) } });
}
</script>

<template>
    <PageHeader :title="titleOf('tenants')" />
    <RecoveryPendingNotice />
    <UnusualSessionNotice />
    <SinglePasskeyNotice
        v-if="session.passkeys?.count === 1"
        :owner="session.operator?.role === 'owner'"
        :synced="session.passkeys.synced"
        link
        data-test="single-passkey"
    />
    <ErrorState v-if="error" :error="error" />
    <AppPanel v-else>
        <LoadingText v-if="tenants === null && !error" />
        <template v-else-if="tenants !== null">
            <EmptyState v-if="tenants.length === 0" icon="building" title="店はまだありません">店はサーバーのコマンド（make tenant）で作ります。</EmptyState>
            <AppTable v-else label="店の一覧" stack>
                <thead>
                    <tr><th>名前</th><th>ID</th><th>ドメイン</th></tr>
                </thead>
                <tbody>
                    <tr v-for="tenant in tenants" :key="tenant.id" data-test="tenant-row">
                        <td><CellTitle :label="tenant.name" :to="{ name: 'tenant', params: { tenant: tenant.id } }" /></td>
                        <td data-label="ID"><AppText as="span" tone="soft" size="xs" mono>{{ tenant.id }}</AppText></td>
                        <td data-label="ドメイン"><AppText as="span" tone="muted">{{ tenant.domains.join('、') }}</AppText></td>
                    </tr>
                </tbody>
            </AppTable>
            <AppPager :page="page" :last-page="lastPage" :total="meta.total" @go="go" />
        </template>
    </AppPanel>
</template>
