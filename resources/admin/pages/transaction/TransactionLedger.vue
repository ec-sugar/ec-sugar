<script setup>
import { computed } from 'vue';
import { ACCOUNT_LABELS, formatAmount, formatDate, JOURNAL_LABELS } from '../../format.js';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppTable from '../../components/ui/AppTable.vue';
import AppText from '../../components/ui/AppText.vue';
import CellTitle from '../../components/ui/CellTitle.vue';
import NumCell from '../../components/ui/NumCell.vue';

// 取引の仕訳と、勘定ごとの残高（読むだけ）
const props = defineProps({
    journals: { type: Array, required: true },
    balances: { type: Object, required: true },
    currency: { type: String, default: 'JPY' },
});

const money = (amount) => formatAmount(amount, props.currency);
const rows = computed(() => Object.entries(props.balances).map(([account, amount]) => ({ account, amount })));
</script>

<template>
    <AppPanel title="仕訳と残高" description="複式簿記の仕訳です。どの仕訳も合計は 0 になり、残高は勘定ごとの合計です（マイナスは支払う側）。">
        <AppTable label="勘定ごとの残高">
            <thead>
                <tr><th>勘定</th><th class="num">残高</th></tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.account" data-test="balance-row">
                    <td><CellTitle :label="ACCOUNT_LABELS[row.account] ?? row.account" /></td>
                    <NumCell :value="row.amount === 0 ? 0 : money(row.amount)" strong />
                </tr>
            </tbody>
        </AppTable>
        <AppTable label="仕訳">
            <thead>
                <tr><th>仕訳</th><th>日時</th><th>参照</th><th>内訳</th></tr>
            </thead>
            <tbody>
                <tr v-for="journal in journals" :key="journal.id" data-test="journal-row">
                    <td><CellTitle :label="JOURNAL_LABELS[journal.type] ?? journal.type" :sub="journal.reverses_journal_id ? '取り消しの仕訳' : null" /></td>
                    <td><AppText as="span" tone="muted" nowrap>{{ formatDate(journal.occurred_at) }}</AppText></td>
                    <td><AppText as="span" tone="soft" size="xs" mono>{{ journal.reference ?? '—' }}</AppText></td>
                    <td>
                        <AppText v-for="posting in journal.postings" :key="posting.account" size="sm" tone="muted">
                            {{ ACCOUNT_LABELS[posting.account] ?? posting.account }} {{ money(posting.amount) }}
                        </AppText>
                    </td>
                </tr>
            </tbody>
        </AppTable>
    </AppPanel>
</template>
