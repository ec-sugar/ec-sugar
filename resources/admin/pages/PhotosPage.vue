<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../api.js';
import { crumbsFor, titleOf } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { canInTenant, tenantName } from '../tenants.js';
import AppText from '../components/ui/AppText.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import ShopPhotos from './shop/ShopPhotos.vue';

// 写真（ADR 0028 区切り 2b）: ショップの写真の置き場を独立した画面に（WordPress のメディアと同じ置き方。2026-10-29）。
// 使うのはテーマの中身の写真の欄と、店の設定のヒーロー
const props = defineProps({ tenant: { type: String, required: true } });

const media = ref(null);
const message = ref(null);
const error = ref(null);
const loadError = ref(null);
const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/shop-media`);
const crumbs = computed(() => crumbsFor('photos', props.tenant, tenantName(props.tenant)));

async function load() {
    error.value = null;
    loadError.value = null;

    try {
        media.value = await api('GET', base.value);
    } catch (e) {
        loadError.value = e;
    }
}

function notice({ message: done, error: failed }) {
    message.value = done;
    error.value = failed;
}

watch(() => props.tenant, load, { immediate: true });
</script>

<template>
    <PageHeader :help="helpLink('photos', tenant)" :title="titleOf('photos')" :crumbs="crumbs" />
    <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
    <AppNotice v-if="error" tone="error" data-test="media-error">{{ error }}</AppNotice>
    <ErrorState v-if="loadError" :error="loadError" />
    <LoadingText v-else-if="media === null && !error" />
    <template v-else-if="media">
        <AppText v-if="!canInTenant(tenant, 'operate')" size="sm" tone="soft" data-test="photos-read-only">写真のアップロード・変更・削除ができるのは、「運用」以上の権限のある人だけです。</AppText>
        <ShopPhotos v-model:items="media.items" :base="base" :max="media.max" :disabled="!canInTenant(tenant, 'operate')" @notice="notice" />
    </template>
</template>
