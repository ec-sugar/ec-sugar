<script setup>
import { computed, reactive, ref } from 'vue';
import { api } from '../api.js';
import { crumbsFor, titleOf } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { firstError, formatDate } from '../format.js';
import { useOperation } from '../operation.js';
import { session } from '../session.js';
import AppButton from '../components/ui/AppButton.vue';
import AppField from '../components/ui/AppField.vue';
import AppInput from '../components/ui/AppInput.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppSelect from '../components/ui/AppSelect.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import CellTitle from '../components/ui/CellTitle.vue';
import CodeText from '../components/ui/CodeText.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import FormActions from '../components/ui/FormActions.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';

// 運営のメンバーと権限（ADR 0024）。オーナーだけ。変えるのはパスキーで確認してから（サーバーの再確認）。守るのはサーバー
const ROLE_LABELS = { owner: 'オーナー（全店・全操作）', staff: 'スタッフ（与えた店だけ）' };
const LEVEL_LABELS = { none: 'なし', read: '閲覧', operate: '運用', money: '会計' };

const data = ref(null);
// busy は今動いている操作の名前（そのボタンだけ回す）
const { pending: busy, errors: formErrors, error, message, run: send } = useOperation();
const form = reactive({ name: '', email: '' });
const enrollment = ref(null);
const loadError = ref(null);

const members = computed(() => data.value?.members ?? []);

async function load() {
    try {
        data.value = await api('GET', '/api/members');
    } catch (e) {
        loadError.value = e;
    }
}

load();

// 変えた後の一覧を受け取り、結果を返す（できなかったら null）
async function run(name, action, done) {
    let result = null;
    await send(name, action, { done, then: (r) => {
        result = r;
        data.value = { members: r.members ?? data.value.members, tenants: r.tenants ?? data.value.tenants };
    } });

    return result;
}

async function add() {
    enrollment.value = null;
    const result = await run('add', () => api('POST', '/api/members', { name: form.name, email: form.email }), `${form.name} を追加しました。下の登録のリンクを本人に渡してください。`);

    if (result) {
        enrollment.value = result.enrollment_url;
        Object.assign(form, { name: '', email: '' });
    }
}

async function reissue(member) {
    const result = await run(`enroll-${member.id}`, () => api('POST', `/api/members/${member.id}/enrollment`), `${member.name} の登録のリンクを再発行しました（前のリンクは使えなくなります）。`);
    enrollment.value = result?.enrollment_url ?? null;
}

function setRole(member, role) {
    return run(`role-${member.id}`, () => api('PUT', `/api/members/${member.id}/role`, { role }), `${member.name} を${role === 'owner' ? 'オーナー' : 'スタッフ'}にしました。`);
}

function setActive(member, active) {
    return run(`status-${member.id}`, () => api('PUT', `/api/members/${member.id}/status`, { active }), `${member.name} を${active ? '有効に戻しました' : '停止しました（次の操作から管理画面を使えなくなります）'}。`);
}

function setGrant(member, tenant, level) {
    return run(`grant-${member.id}-${tenant.id}`, () => api('PUT', `/api/members/${member.id}/grants/${encodeURIComponent(tenant.id)}`, { level }), `${member.name} の「${tenant.name}」の権限を「${LEVEL_LABELS[level]}」にしました。`);
}

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        message.value = 'リンクをコピーしました。';
    } catch {
        error.value = 'コピーできませんでした。文字を選んでコピーしてください。';
    }
}
</script>

<template>
    <PageHeader :help="helpLink('members')" :title="titleOf('members')" :crumbs="crumbsFor('members')" />
    <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
    <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>
    <ErrorState v-if="loadError" :error="loadError" />
    <LoadingText v-else-if="data === null && !error" />

    <template v-else-if="data">
        <AppPanel as="form" title="メンバーを追加" description="追加したメンバーはスタッフになり、はじめはどの店にも入れません。下の表で店ごとの権限を設定してください。パスキーの登録用のリンクは、ここで 1 回だけ表示されます。" novalidate @submit.prevent="add">
            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <AppField label="表示名" :error="firstError(formErrors, 'name')">
                    <AppInput v-model="form.name" maxlength="100" required data-test="member-name" />
                </AppField>
                <AppField label="メールアドレス" :error="firstError(formErrors, 'email')">
                    <AppInput v-model="form.email" type="email" maxlength="254" required data-test="member-email" />
                </AppField>
            </div>
            <FormActions>
                <AppButton type="submit" variant="primary" :disabled="busy !== null" :loading="busy === 'add'" data-test="add-member">メンバーを追加</AppButton>
            </FormActions>
            <AppNotice v-if="enrollment" tone="warning" data-test="enrollment-url">
                <span>パスキーの登録用のリンクです（有効期限は短く、1 回だけ使えます）。本人だけに、安全な方法で渡してください。</span>
                <CodeText>{{ enrollment }}</CodeText>
                <div><AppButton size="sm" @click="copy(enrollment)">リンクをコピー</AppButton></div>
            </AppNotice>
        </AppPanel>

        <AppPanel title="メンバーと権限" description="オーナーは全店・全操作とメンバーの管理ができます。スタッフには、店ごとに「閲覧（見るだけ）」「運用（商品・設定・発送・個人情報の表示）」「会計（取消と返金・Stripe）」のどれかを設定します。">
            <AppTable label="メンバー" stack>
                <thead>
                    <tr><th>メンバー</th><th>役割</th><th>状態</th><th>最後のログイン</th><th><span class="sr-only">操作</span></th></tr>
                </thead>
                <tbody>
                    <tr v-for="member in members" :key="member.id" data-test="member-row">
                        <td><CellTitle :label="member.name" :sub="member.email" /></td>
                        <td data-label="役割">
                            <AppSelect :model-value="member.role" :disabled="busy !== null" :aria-label="`${member.name} の役割`" data-test="member-role" @update:model-value="(role) => setRole(member, role)">
                                <option v-for="(label, role) in ROLE_LABELS" :key="role" :value="role">{{ label }}</option>
                            </AppSelect>
                        </td>
                        <td data-label="状態"><StatusBadge :tone="member.active ? 'ok' : 'neutral'">{{ member.active ? '有効' : '停止中' }}</StatusBadge></td>
                        <td data-label="最後のログイン"><AppText as="span" tone="muted" nowrap>{{ formatDate(member.last_login_at) }}</AppText></td>
                        <td>
                            <div class="flex flex-wrap justify-end gap-2">
                                <AppButton v-if="member.active" size="sm" :disabled="busy !== null" :loading="busy === `enroll-${member.id}`" @click="reissue(member)">登録用のリンクを再発行</AppButton>
                                <AppButton v-if="member.id !== session.operator?.id" size="sm" :variant="member.active ? 'danger' : 'secondary'" :disabled="busy !== null" :loading="busy === `status-${member.id}`" data-test="member-status" @click="setActive(member, !member.active)">{{ member.active ? '停止' : '有効に戻す' }}</AppButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </AppTable>
        </AppPanel>

        <AppPanel v-for="member in members.filter((m) => m.role === 'staff')" :key="`grants-${member.id}`" :title="`${member.name} の店ごとの権限`" :data-test="`grants-${member.email}`">
            <AppTable :label="`${member.name} の店ごとの権限`">
                <thead>
                    <tr><th>店</th><th>権限</th></tr>
                </thead>
                <tbody>
                    <tr v-for="tenant in data.tenants" :key="tenant.id" data-test="grant-row">
                        <td><CellTitle :label="tenant.name" :sub="tenant.id" /></td>
                        <td>
                            <AppSelect :model-value="member.grants[tenant.id] ?? 'none'" :disabled="busy !== null" :aria-label="`${member.name} の ${tenant.name} の権限`" data-test="grant-level" @update:model-value="(level) => setGrant(member, tenant, level)">
                                <option v-for="(label, level) in LEVEL_LABELS" :key="level" :value="level">{{ label }}</option>
                            </AppSelect>
                        </td>
                    </tr>
                </tbody>
            </AppTable>
        </AppPanel>
    </template>
</template>
