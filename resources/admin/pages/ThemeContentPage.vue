<script setup>
import { computed, ref, watch } from 'vue';
import { api, ApiError, describe } from '../api.js';
import { formatDate } from '../format.js';
import { crumbsFor, tabsFor, titleOf } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { canInTenant, storefrontUrl, tenantName } from '../tenants.js';
import AppButton from '../components/ui/AppButton.vue';
import AppIcon from '../components/ui/AppIcon.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppText from '../components/ui/AppText.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';
import ThemeCard from '../components/ui/ThemeCard.vue';

// デザインのテーマの画面（上に今のテーマの大きな札、下に入っているテーマの札の並び）。セクションの中身はここでは入れない（ページの編集の画面だけ。ADR 0039）。
// - 上に「今のテーマ」の大きな札: 見本の絵・名前とバージョン・公開の状態・「編集する」（ページの編集の画面のトップへ）・「公開する」（…/pages/home/publish）
// - 下に「ほかのテーマ」: 入っているテーマの札。「試しに編集する」（そのテーマの下書きをページの編集の画面で。ショップには出ない）と
//   「このテーマにする」（…/shop-settings/theme。替える前に、写せないセクションのある自由なページを通知する）
// - 変えるのは運用の段階から（閲覧だけの人にはボタンを出さない。守るのはサーバー）
const props = defineProps({ tenant: { type: String, required: true } });

const list = ref(null);
const loadError = ref(null);
const error = ref(null);
const message = ref(null);
const conflict = ref(false);
const publishing = ref(false);
// 選び替えを確認している札（テーマの名前）と、そのテーマに替えると写せないセクションのある自由なページ
const switching = ref(null);
const impact = ref([]);
const switchBusy = ref(false);

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}`);
const crumbs = computed(() => crumbsFor('theme-content', props.tenant, tenantName(props.tenant)));
const canEdit = computed(() => canInTenant(props.tenant, 'operate'));
const current = computed(() => list.value?.themes.find((t) => t.name === list.value.active) ?? null);
const others = computed(() => list.value?.themes.filter((t) => t.name !== list.value.active) ?? []);
const shopUrl = computed(() => storefrontUrl(props.tenant));

// 今のテーマの公開の状態（中身のバージョンが無ければ、ショップにはテーマの見本の文と店の名前が出ている）
const state = computed(() => {
    const content = current.value?.content;

    if (!content || content.published_at === null) {
        return { tone: 'neutral', label: 'まだ公開していません', note: 'ショップには、テーマのサンプルの文と店の名前が表示されています。' };
    }

    return content.unpublished_changes
        ? { tone: 'warn', label: '公開していない変更あり', note: `最後の公開は ${formatDate(content.published_at)} です。` }
        : { tone: 'ok', label: '公開中', note: `${formatDate(content.published_at)} に公開しました。` };
});
const canPublish = computed(() => current.value?.content?.unpublished_changes === true);

const editorLink = (name) => ({ name: 'theme-page', params: { tenant: props.tenant, page: 'home' }, query: { theme: name } });

async function load() {
    loadError.value = null;
    conflict.value = false;

    try {
        list.value = await api('GET', `${base.value}/themes`);
    } catch (e) {
        if (list.value === null) {
            loadError.value = e;
        } else {
            error.value = describe(e);
        }
    }
}

watch(() => props.tenant, () => {
    list.value = null;
    load();
}, { immediate: true });

function begin() {
    error.value = null;
    message.value = null;
}

// 今のテーマのすべてのページの下書きを公開する（ページの編集の画面の「公開する」と同じ口。公開はバージョンごと）
async function publish() {
    const content = current.value?.content;
    if (!content) return;
    begin();
    publishing.value = true;

    try {
        await api('POST', `${base.value}/themes/${encodeURIComponent(current.value.name)}/pages/home/publish`, { version_id: content.version_id, revision: content.revision });
        message.value = '公開しました。ショップに反映されています。';
        await load();
    } catch (e) {
        conflict.value = e instanceof ApiError && e.status === 409;
        error.value = conflict.value ? (e.body?.message ?? describe(e)) : describe(e);
    } finally {
        publishing.value = false;
    }
}

// 「このテーマにする」の問いを開いたら、替えると写せないセクションのある自由なページを読む（読めなくても替えられる。写せないセクションは前のテーマに残る）
async function ask(name) {
    switching.value = name;
    impact.value = [];

    try {
        const data = await api('GET', `${base.value}/shop-settings/theme/impact?theme=${encodeURIComponent(name)}`);
        if (switching.value === name) impact.value = data.pages;
    } catch {
        // 通知を読めなくても替えられる
    }
}

function cancel() {
    switching.value = null;
    impact.value = [];
}

async function choose(theme) {
    begin();
    switchBusy.value = true;

    try {
        await api('PUT', `${base.value}/shop-settings/theme`, { theme: theme.name });
        message.value = `ショップのテーマを「${theme.label}」にしました。`;
        cancel();
        await load();
    } catch (e) {
        error.value = describe(e);
    } finally {
        switchBusy.value = false;
    }
}
</script>

<template>
    <PageHeader :help="helpLink('theme-content', tenant)" :title="titleOf('theme-content')" :crumbs="crumbs" :tabs="tabsFor('theme-content', tenant)" />
    <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
    <AppNotice v-if="error" :tone="conflict ? 'warning' : 'error'">
        <span>{{ error }}</span>
        <div v-if="conflict">
            <AppButton size="sm" data-test="reload-themes" @click="load">最新の状態を読み込み直す</AppButton>
        </div>
    </AppNotice>

    <ErrorState v-if="loadError" :error="loadError" />
    <LoadingText v-else-if="list === null" />
    <template v-else>
        <AppPanel title="今のテーマ" description="ショップで使っているテーマです。内容（お知らせバナー・ヒーロー・商品の並びなど）は「編集する」から、プレビューを見ながら編集できます。" data-test="current-theme">
            <ThemeCard v-if="current" layout="wide" :label="current.label" :version="current.version" :screenshot="current.screenshot">
                <template #status>
                    <StatusBadge :tone="state.tone" data-test="current-theme-state">{{ state.label }}</StatusBadge>
                    <AppText size="sm" tone="soft" data-test="current-theme-note">{{ state.note }}</AppText>
                </template>
                <AppText v-if="!canEdit" size="sm" tone="soft" data-test="themes-read-only">閲覧のみです。テーマの内容の編集・公開・テーマの切り替えができるのは、「運用」以上の権限のある人だけです。</AppText>
                <template #actions>
                    <AppButton v-if="canEdit" variant="primary" :to="editorLink(current.name)" data-test="open-page-editor"><AppIcon name="brush" />編集する</AppButton>
                    <ConfirmButton
                        v-if="canEdit"
                        :key="`publish-${current.content?.revision ?? 0}`"
                        label="公開する"
                        variant="secondary"
                        size="md"
                        :disabled="publishing || !canPublish"
                        :loading="publishing"
                        test="publish-theme"
                        @confirm="publish"
                    >
                        このテーマのすべてのページの下書きを、ショップに公開します。
                    </ConfirmButton>
                    <AppButton v-if="shopUrl" :href="shopUrl" data-test="open-storefront"><AppIcon name="external" />ショップを開く</AppButton>
                </template>
            </ThemeCard>
        </AppPanel>

        <AppPanel title="ほかのテーマ" description="インストールされているテーマです。「試しに編集する」で、切り替える前に内容を用意できます（ショップには表示されません）。「このテーマにする」を押すと、ショップのデザインが切り替わります。" data-test="other-themes">
            <EmptyState v-if="others.length === 0" title="ほかにインストールされているテーマはありません" icon="brush" />
            <ul v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="theme in others" :key="theme.name" class="flex" :data-test="`theme-${theme.name}`">
                    <ThemeCard :label="theme.label" :version="theme.version" :screenshot="theme.screenshot" class="w-full">
                        <AppNotice v-if="switching === theme.name && impact.length > 0" tone="warning" :live="false" data-test="theme-impact">
                            <span>このテーマに切り替えると、次のページのセクションの一部は新しいテーマへコピーできません（テーマ固有のセクションのため）。基本のセクションはコピーします。コピーできないセクションは前のテーマに残り、前のテーマに戻せば元に戻ります。</span>
                            <ul>
                                <li v-for="page in impact" :key="page.id" data-test="theme-impact-page">「{{ page.title }}」（/pages/{{ page.slug }}）: コピーできないセクション {{ page.dropped }} 個・コピーするセクション {{ page.copied }} 個</li>
                            </ul>
                        </AppNotice>
                        <template v-if="canEdit" #actions>
                            <AppButton size="sm" :to="editorLink(theme.name)" data-test="try-theme">試しに編集する</AppButton>
                            <ConfirmButton
                                label="このテーマにする"
                                variant="secondary"
                                :disabled="switchBusy"
                                :loading="switchBusy && switching === theme.name"
                                test="choose-theme"
                                @ask="ask(theme.name)"
                                @cancel="cancel"
                                @confirm="choose(theme)"
                            >
                                ショップのデザインがこのテーマに切り替わります。
                            </ConfirmButton>
                        </template>
                    </ThemeCard>
                </li>
            </ul>
        </AppPanel>
    </template>
</template>
