<script setup>
import { computed, ref } from 'vue';
import { crumbsFor, titleOf } from '../navigation.js';
import { api } from '../api.js';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppTable from '../components/ui/AppTable.vue';
import AppText from '../components/ui/AppText.vue';
import CellTitle from '../components/ui/CellTitle.vue';
import CodeText from '../components/ui/CodeText.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';
import StepList from '../components/ui/StepList.vue';

// 入っているプラグインとテーマ。オーナーだけ・読むだけ。入れる・外すはサーバーで composer を使う（管理画面からコードは入れない。CONCEPT 7.2）
const data = ref(null);
const error = ref(null);

api('GET', '/api/plugins').then((result) => { data.value = result; }).catch((e) => { error.value = e; });

const themes = computed(() => (data.value?.packages ?? []).filter((p) => p.kind === 'theme'));
const plugins = computed(() => (data.value?.packages ?? []).filter((p) => p.kind !== 'theme'));

function state(p) {
    if (p.problem) {
        return { tone: 'danger', label: 'マニフェストに誤り' };
    }

    if (!p.compatible) {
        return { tone: 'danger', label: 'この EC-SUGAR のバージョンに未対応' };
    }

    if (!p.active) {
        return { tone: 'warn', label: '読み込みに失敗' };
    }

    return { tone: 'ok', label: '利用可能' };
}

function maker(p) {
    return p.authors.length > 0 ? p.authors.join('、') : '—';
}
</script>

<template>
    <PageHeader :title="titleOf('plugins')" :crumbs="crumbsFor('plugins')" />
    <ErrorState v-if="error" :error="error" />
    <LoadingText v-else-if="data === null" />

    <template v-else>
        <AppNotice tone="info">安全のため、App とテーマは管理画面からはインストールできません（管理画面からプログラムを入れられないようにしています）。サーバーの担当者が composer でインストールします。手順はこの画面のいちばん下にあります。</AppNotice>

        <AppPanel title="テーマ" description="ショップのデザインです。店ごとに「デザイン」で選びます。テーマを選んでいない店と、削除したテーマを選んでいた店は、既定のテーマで表示します。" data-test="themes">
            <AppTable label="テーマ">
                <thead>
                    <tr><th>名前</th><th>バージョン</th><th>開発元</th><th>対応する EC-SUGAR のバージョン</th><th>状態</th><th>使っている店</th></tr>
                </thead>
                <tbody>
                    <tr v-for="theme in themes" :key="theme.package" data-test="theme-row">
                        <td>
                            <CellTitle :label="theme.name ?? theme.package" :sub="theme.package" />
                            <AppText v-if="theme.description" size="xs" tone="soft">{{ theme.description }}</AppText>
                        </td>
                        <td><AppText as="span" tone="muted" nowrap>{{ theme.version }}</AppText></td>
                        <td>
                            <StatusBadge v-if="theme.first_party" tone="accent">自社</StatusBadge>
                            <AppText v-else as="span" tone="muted">{{ maker(theme) }}</AppText>
                        </td>
                        <td><CodeText>{{ theme.core ?? '—' }}</CodeText></td>
                        <td>
                            <StatusBadge :tone="state(theme).tone" data-test="package-state">{{ state(theme).label }}</StatusBadge>
                            <AppText v-if="theme.problem" size="xs" tone="soft">{{ theme.problem }}</AppText>
                        </td>
                        <td>
                            <AppText v-if="theme.default" size="xs" tone="soft">既定のテーマ</AppText>
                            <AppText v-if="theme.shops.length === 0" as="span" tone="soft">なし</AppText>
                            <AppText v-else size="sm" tone="muted" data-test="theme-shops">{{ theme.shops.map((s) => s.name).join('、') }}</AppText>
                        </td>
                    </tr>
                </tbody>
            </AppTable>
        </AppPanel>

        <AppPanel title="App" description="機能を追加するもの（プラグイン）です。インストールしただけでは動かず、店ごとに、店のメニューの「App」で使うかどうかを選びます。" data-test="plugins">
            <EmptyState v-if="plugins.length === 0" title="App はまだインストールされていません">インストールすると、ここに表示されます。</EmptyState>
            <AppTable v-else label="App">
                <thead>
                    <tr><th>名前</th><th>バージョン</th><th>開発元</th><th>対応する EC-SUGAR のバージョン</th><th>状態</th></tr>
                </thead>
                <tbody>
                    <tr v-for="plugin in plugins" :key="plugin.package" data-test="plugin-row">
                        <td>
                            <CellTitle :label="plugin.name ?? plugin.package" :sub="plugin.package" />
                            <AppText v-if="plugin.description" size="xs" tone="soft">{{ plugin.description }}</AppText>
                        </td>
                        <td><AppText as="span" tone="muted" nowrap>{{ plugin.version }}</AppText></td>
                        <td>
                            <StatusBadge v-if="plugin.first_party" tone="accent">自社</StatusBadge>
                            <AppText v-else as="span" tone="muted">{{ maker(plugin) }}</AppText>
                        </td>
                        <td><CodeText>{{ plugin.core ?? '—' }}</CodeText></td>
                        <td>
                            <StatusBadge :tone="state(plugin).tone" data-test="package-state">{{ state(plugin).label }}</StatusBadge>
                            <AppText v-if="plugin.problem" size="xs" tone="soft">{{ plugin.problem }}</AppText>
                        </td>
                    </tr>
                </tbody>
            </AppTable>
        </AppPanel>

        <AppPanel title="インストールの手順" :description="`サーバーの担当者の作業です。この EC-SUGAR のバージョンは ${data.core_version} です（「対応する EC-SUGAR のバージョン」にこのバージョンが含まれるものだけが使えます）。`" data-test="install-steps">
            <StepList size="sm">
                <li>バージョンを固定してインストールする: <CodeText>composer require 開発元/plugin-名前:1.2.3</CodeText>（公開から 7 日以上たったバージョンを選び、<CodeText>composer audit</CodeText> で既知の脆弱性が無いことを確認する）</li>
                <li>動かしているイメージを作り直して更新する（イメージの中は読み取り専用なので、動いているサーバーの中ではインストールできません）</li>
                <li>この画面に表示され、状態が「利用可能」になっていることを確認する</li>
            </StepList>
            <AppText tone="soft">削除するときは <CodeText>composer remove 開発元/plugin-名前</CodeText> の後、同じようにイメージを更新します。</AppText>
        </AppPanel>
    </template>
</template>
