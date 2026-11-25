<script setup>
import { ref } from 'vue';
import { api, describe } from '../../api.js';
import { formatDate } from '../../format.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppIcon from '../../components/ui/AppIcon.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppTable from '../../components/ui/AppTable.vue';
import AppText from '../../components/ui/AppText.vue';
import CellTitle from '../../components/ui/CellTitle.vue';
import NumCell from '../../components/ui/NumCell.vue';

// 取引の記録（イベント）と、記録全体のハッシュ連鎖の検証
const props = defineProps({
    events: { type: Array, required: true },
    // 連鎖の検証の API（/api/tenants/{tenant}/events/verify）
    verifyPath: { type: String, required: true },
});

const verification = ref(null);
const verifying = ref(false);

async function verify() {
    verifying.value = true;
    verification.value = null;

    try {
        verification.value = await api('GET', props.verifyPath);
    } catch (e) {
        verification.value = { error: describe(e) };
    } finally {
        verifying.value = false;
    }
}

function payloadText(payload) {
    return payload && Object.keys(payload).length > 0 ? JSON.stringify(payload) : '—';
}
</script>

<template>
    <AppPanel title="記録（イベント）" description="この注文に起きたことの記録です。あとから書き換えられていないかは、記録全体のハッシュチェーン（1 件ずつ前の記録とつないだもの）で確認します。">
        <template #actions>
            <AppButton :disabled="verifying" data-test="verify-events" @click="verify">
                <AppIcon name="shield" />改竄がないか検証する
            </AppButton>
        </template>
        <template v-if="verification">
            <AppNotice v-if="verification.error" tone="error">{{ verification.error }}</AppNotice>
            <AppNotice v-else-if="verification.intact" tone="success"><span data-test="verify-result">{{ verification.checked }} 件を確認しました。改竄は見つかりませんでした。</span></AppNotice>
            <AppNotice v-else tone="error"><span data-test="verify-result">記録のつながりが {{ verification.broken_at }} 番目で途切れています。記録が書き換えられた可能性があります。</span></AppNotice>
        </template>
        <AppTable label="記録">
            <thead>
                <tr><th class="num">番号</th><th>種類</th><th>操作した人</th><th>日時</th><th>内容</th></tr>
            </thead>
            <tbody>
                <tr v-for="event in events" :key="event.sequence" data-test="event-row">
                    <NumCell :value="event.sequence" soft />
                    <td><CellTitle :label="event.type" :sub="event.hash" /></td>
                    <td><AppText as="span" tone="muted" size="sm">{{ event.actor ?? '—' }}</AppText></td>
                    <td><AppText as="span" tone="muted" size="sm" nowrap>{{ formatDate(event.occurred_at) }}</AppText></td>
                    <td class="min-w-80"><AppText as="span" tone="soft" size="xs" mono break-all>{{ payloadText(event.payload) }}</AppText></td>
                </tr>
            </tbody>
        </AppTable>
    </AppPanel>
</template>
