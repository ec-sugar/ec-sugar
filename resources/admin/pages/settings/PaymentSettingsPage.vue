<script setup>
import { computed } from 'vue';
import { crumbsFor, titleOf } from '../../navigation.js';
import { helpLink } from '../../help/index.js';
import { tenantName } from '../../tenants.js';
import PageHeader from '../../components/ui/PageHeader.vue';
import SettingsLayout from '../../components/SettingsLayout.vue';
import DirectPaymentSettings from '../../components/DirectPaymentSettings.vue';
import PaymentMethodsSettings from '../../components/PaymentMethodsSettings.vue';
import StripeSettings from '../../components/StripeSettings.vue';

// 設定 › 支払い（で 1 画面に。使う手段は ADR 0058。銀行振込・代金引換は ADR 0074）。読むのは閲覧から、変えるのはお金の段階でパスキーの再確認の後（サーバーが守る）。
// Stripe の登録の画面からの戻り（?stripe=return）は、前の店の設定の URL に来て、ここへ送られる（router.js）
const props = defineProps({ tenant: { type: String, required: true } });

const crumbs = computed(() => crumbsFor('settings-payments', props.tenant, tenantName(props.tenant)));
</script>

<template>
    <SettingsLayout :tenant="tenant">
        <PageHeader :help="helpLink('settings-payments', tenant)" :title="titleOf('settings-payments')" :crumbs="crumbs" />
        <StripeSettings :tenant="tenant" />
        <!-- 使う支払いの手段（コンビニ払い・PayPay。ADR 0058） -->
        <PaymentMethodsSettings :tenant="tenant" />
        <!-- 銀行振込・代金引換（Stripe を通さない。ADR 0074） -->
        <DirectPaymentSettings :tenant="tenant" />
    </SettingsLayout>
</template>
