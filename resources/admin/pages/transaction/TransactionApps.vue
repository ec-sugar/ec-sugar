<script setup>
import { ref, watch } from 'vue';
import { api } from '../../api.js';
import { cellText, isNumeric } from '../../appPages.js';
import AppPageValue from '../../components/ui/AppPageValue.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppTable from '../../components/ui/AppTable.vue';
import DescriptionList from '../../components/ui/DescriptionList.vue';
import NumCell from '../../components/ui/NumCell.vue';
import TextLink from '../../components/ui/TextLink.vue';

// 注文の画面の App の 1 件（App「ダウンロード販売」の回数・最後の日時・期限など）。この注文に結びつく App の画面の 1 件を、
// 個人情報を出さずに並べる（形は App の画面の 1 件と同じ。描くのも同じ部品）。操作（回数を戻す・期限を延ばすなど）は App の画面の 1 件で
const props = defineProps({
    tenant: { type: String, required: true },
    transaction: { type: String, required: true },
});
const apps = ref([]);

watch(() => [props.tenant, props.transaction], async () => {
    apps.value = [];

    try {
        apps.value = (await api('GET', `/api/tenants/${encodeURIComponent(props.tenant)}/transactions/${encodeURIComponent(props.transaction)}/apps`)).apps;
    } catch {
        // App の節が出ないだけ（注文の画面は開ける）
    }
}, { immediate: true });
</script>

<template>
    <AppPanel v-for="app in apps" :key="app.plugin" :title="app.page_title" :data-plugin="app.plugin" data-test="order-app">
        <DescriptionList :items="app.facts">
            <template #value="{ item }">
                <AppPageValue :type="item.type" :value="item.value" :tenant="tenant" />
            </template>
        </DescriptionList>
        <AppTable v-for="(table, tableIndex) in app.tables" :key="tableIndex" :label="table.title" stack>
            <thead>
                <tr>
                    <th v-for="column in table.columns" :key="column.key" :class="isNumeric(column.type) ? 'num' : null">{{ column.label }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, rowIndex) in table.rows" :key="row.id ?? rowIndex" data-test="order-app-row">
                    <template v-for="column in table.columns" :key="column.key">
                        <NumCell v-if="isNumeric(column.type)" :value="cellText(column.type, row.cells[column.key])" :data-label="column.label" />
                        <td v-else :data-label="column.label">
                            <AppPageValue :type="column.type" :value="row.cells[column.key]" :tenant="tenant" />
                        </td>
                    </template>
                </tr>
            </tbody>
        </AppTable>
        <TextLink :to="{ name: 'app-record', params: { tenant, plugin: app.plugin, record: app.id } }" data-test="order-app-open">App の画面で開く（操作はこちら）</TextLink>
    </AppPanel>
</template>
