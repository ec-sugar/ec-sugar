<script setup>
import { computed } from 'vue';
import { crumbsFor, titleOf, visibleSettingsSections } from '../../navigation.js';
import { canInTenant, tenantName } from '../../tenants.js';
import AppPanel from '../../components/ui/AppPanel.vue';
import LinkList from '../../components/ui/LinkList.vue';
import PageHeader from '../../components/ui/PageHeader.vue';

// 設定の目次。まとまりと項目はメニューの定義（navigation.js）から。1 項目 1 画面で、まだ画面の無い項目は「近日」
const props = defineProps({ tenant: { type: String, required: true } });

const crumbs = computed(() => crumbsFor('settings', props.tenant, tenantName(props.tenant)));
const sections = computed(() => visibleSettingsSections((level) => canInTenant(props.tenant, level)).map((section) => ({
    ...section,
    items: section.items.map((item) => ({
        ...item,
        to: item.route ? { name: item.route, params: { tenant: props.tenant } } : null,
        test: item.route ? `settings-${item.key}` : 'settings-soon',
    })),
})));
</script>

<template>
    <PageHeader :title="titleOf('settings')" :crumbs="crumbs" />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <AppPanel v-for="section in sections" :key="section.key" :title="section.title" :data-test="`settings-section-${section.key}`">
            <LinkList :items="section.items" />
        </AppPanel>
    </div>
</template>
