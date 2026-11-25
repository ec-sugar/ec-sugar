<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { crumbsFor, HELP_PAGES, titleOf } from '../navigation.js';
import { articleOf, helpAvailable, helpRoute, searchHelp, searchWords } from '../help/index.js';
import { session } from '../session.js';
import AppButton from '../components/ui/AppButton.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppText from '../components/ui/AppText.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import LinkList from '../components/ui/LinkList.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import SearchField from '../components/ui/SearchField.vue';
import SearchResultList from '../components/ui/SearchResultList.vue';

// ヘルプ。説明のページの目次と、題と本文から言葉で探す欄。並びはメニューの定義（navigation.js の HELP_PAGES）、中身は help/。
// 探すのはこの画面の中だけ（手元のデータ。サーバーへは送らない）。打つたびに探し、言葉は URL（?q=）に置く（戻ったときに残る）。
// どの店でも同じ説明なので、店の外の画面。?shop= は説明の中の「画面を開く」のために、そのまま説明のページへ渡す
const route = useRoute();
const router = useRouter();
const query = ref(typeof route.query.q === 'string' ? route.query.q.slice(0, 100) : '');
const shop = computed(() => (typeof route.query.shop === 'string' && /^[a-z0-9][a-z0-9_-]{0,62}$/.test(route.query.shop) ? route.query.shop : null));

// 入っていない App の説明のページは目次にも探した結果にも出さない（helpAvailable）
const pages = computed(() => HELP_PAGES.filter((page) => helpAvailable(articleOf(page.key), session.apps))
    .map((page) => ({ ...page, to: helpRoute(page.key, null, shop.value), test: `help-${page.key}` })));
const searching = computed(() => searchWords(query.value).length > 0);
const results = computed(() => searchHelp(query.value, 30, session.apps).map((result) => ({
    ...result,
    to: helpRoute(result.article, result.section, shop.value),
    test: `help-result-${result.key}`,
})));

// 言葉を URL に置く（履歴を増やさない）
watch(query, (value) => {
    const q = value.trim() === '' ? undefined : value.slice(0, 100);

    if (q !== route.query.q) {
        router.replace({ query: { ...route.query, q } });
    }
});
</script>

<template>
    <PageHeader :title="titleOf('help')" :crumbs="crumbsFor('help')" />
    <AppPanel title="ヘルプの中を探す" description="タイトルと本文から探します。言葉を空白で区切ると、すべての言葉を含む所を探します。">
        <SearchField v-model="query" label="例: 送料、返金、パスキー" wide data-test="help-search" />
        <template v-if="searching">
            <AppText size="sm" tone="soft" role="status" data-test="help-result-count">{{ results.length === 0 ? '見つかりませんでした' : `${results.length} 件` }}</AppText>
            <SearchResultList v-if="results.length" :items="results" label="探した結果" />
            <EmptyState v-else icon="search" title="この言葉の説明は見つかりませんでした" data-test="help-no-result">
                言葉を短くするか、別の言い方で探してください。
                <template #action>
                    <AppButton size="sm" :to="helpRoute('faq', null, shop)">よくある質問を見る</AppButton>
                    <AppButton size="sm" variant="ghost" data-test="help-search-clear" @click="query = ''">クリア</AppButton>
                </template>
            </EmptyState>
        </template>
    </AppPanel>
    <AppPanel title="説明のページ">
        <AppText tone="soft">設定の手順を、スクリーンショットつきで説明しています。各画面のタイトルの横の「説明を見る」からも開けます。</AppText>
        <LinkList :items="pages" />
    </AppPanel>
</template>
