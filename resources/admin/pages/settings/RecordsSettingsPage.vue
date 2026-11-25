<script setup>
import { computed, ref, watch } from 'vue';
import { api, describe } from '../../api.js';
import { crumbsFor, titleOf } from '../../navigation.js';
import { tenantName } from '../../tenants.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppIcon from '../../components/ui/AppIcon.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppText from '../../components/ui/AppText.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import SettingsLayout from '../../components/SettingsLayout.vue';

// 設定 › 記録（前は概要の画面にあった「記録の改竄の検証」）。注文・在庫・お金の記録（ドメインイベント）のハッシュの連鎖を
// 先頭から確認し、あとから書き換えられていないかを見る。読むだけなので閲覧の段階から（GET …/events/verify。API は前のまま）
const props = defineProps({ tenant: { type: String, required: true } });

const verification = ref(null);
const verifying = ref(false);
const crumbs = computed(() => crumbsFor('settings-records', props.tenant, tenantName(props.tenant)));

// 別の店へ移ったら、前の店の結果を見せない
watch(() => props.tenant, () => {
    verification.value = null;
});

async function verify() {
    verifying.value = true;
    verification.value = null;

    try {
        verification.value = await api('GET', `/api/tenants/${encodeURIComponent(props.tenant)}/events/verify`);
    } catch (e) {
        verification.value = { error: describe(e) };
    } finally {
        verifying.value = false;
    }
}
</script>

<template>
    <SettingsLayout :tenant="tenant">
        <PageHeader :title="titleOf('settings-records')" :crumbs="crumbs" />
        <AppPanel title="記録の改竄の確認" description="注文・在庫・お金の動きは、1 件ずつ前の記録と連結して残しています（ハッシュチェーン）。先頭から順に確認し、あとから書き換えられていないかを見ます。">
            <template #actions>
                <AppButton :disabled="verifying" :loading="verifying" data-test="verify-events" @click="verify">
                    <AppIcon name="shield" />確認する
                </AppButton>
            </template>
            <AppText v-if="verification === null" tone="soft">記録が多いと、少し時間がかかります。</AppText>
            <template v-else>
                <AppNotice v-if="verification.error" tone="error">{{ verification.error }}</AppNotice>
                <AppNotice v-else-if="verification.intact" tone="success"><span data-test="verify-result">{{ verification.checked }} 件を確認しました。改竄は見つかりませんでした。</span></AppNotice>
                <AppNotice v-else tone="error"><span data-test="verify-result">記録のつながりが {{ verification.broken_at }} 番目で途切れています。記録が書き換えられた可能性があります。</span></AppNotice>
            </template>
        </AppPanel>
    </SettingsLayout>
</template>
