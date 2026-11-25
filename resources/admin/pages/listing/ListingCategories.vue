<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../../api.js';
import { flatten } from '../../categories.js';
import { errorsOf } from '../../format.js';
import AppField from '../../components/ui/AppField.vue';
import AppSelect from '../../components/ui/AppSelect.vue';
import AppText from '../../components/ui/AppText.vue';
import CheckList from '../../components/ui/CheckList.vue';
import TextLink from '../../components/ui/TextLink.vue';

// 商品のカテゴリ。いくつでも選べ、主カテゴリは選んだものの中から。
// 保存は商品の画面の下の「保存する」でまとめて: 変えたかを dirty で、保存の手順を step() で親へ渡す。
// カテゴリの保存は商品のバージョンを進めない。新しい商品（listing が null）でも選んでおけ、商品を作った後に保存する
const props = defineProps({
    // 店の API（/api/tenants/{tenant}）
    tenantBase: { type: String, required: true },
    tenant: { type: String, required: true },
    listing: { type: Object, default: null },
    // カテゴリの木（読めなかったときは null）
    tree: { type: Array, default: null },
    disabled: { type: Boolean, default: false },
});

const chosen = ref([]);
const primary = ref('');
const errors = ref({});

const options = computed(() => (props.tree ? flatten(props.tree).map((c) => ({ value: c.id, label: c.name, depth: c.depth })) : []));
const primaryOptions = computed(() => options.value.filter((o) => chosen.value.includes(o.value)));

function read(categories) {
    chosen.value = categories.map((c) => c.id);
    primary.value = categories.find((c) => c.primary)?.id ?? '';
}

// サーバーのカテゴリが変わったとき（読み込み・保存・読み込み直し）だけ読み直す（ほかの保存で、選びかけを消さない）
watch(() => JSON.stringify(props.listing?.categories ?? []), () => read(props.listing?.categories ?? []), { immediate: true });

watch(chosen, (ids) => {
    if (!ids.includes(primary.value)) {
        primary.value = ids[0] ?? '';
    }
});

const saved = computed(() => {
    const categories = props.listing?.categories ?? [];

    return { ids: categories.map((c) => c.id), primary: categories.find((c) => c.primary)?.id ?? '' };
});

const dirty = computed(() => JSON.stringify([...chosen.value].sort()) !== JSON.stringify([...saved.value.ids].sort()) || (chosen.value.length > 0 && primary.value !== saved.value.primary));

function step() {
    return {
        label: 'カテゴリ',
        section: 'listing-details',
        run: async (listing) => {
            errors.value = {};

            try {
                const { listing: assigned } = await api('PUT', `${props.tenantBase}/listings/${encodeURIComponent(listing.id)}/categories`, {
                    categories: chosen.value,
                    primary: primary.value || null,
                });

                // カテゴリはバージョンを進めないので、返った商品の categories だけを足す
                return { ...listing, categories: assigned.categories };
            } catch (e) {
                errors.value = errorsOf(e);
                throw e;
            }
        },
    };
}

const errorText = computed(() => Object.values(errors.value).flat()[0] ?? null);

defineExpose({ dirty, step });
</script>

<template>
    <AppField v-if="tree" as="div" label="カテゴリ" layout="row" optional hint="いくつでも選べます（10 個まで）。ショップでお客さんが商品を探すときに使います" :error="errorText">
        <AppText v-if="options.length === 0" size="sm" tone="soft" data-test="no-categories">
            カテゴリがまだありません。<TextLink :to="{ name: 'categories', params: { tenant } }">カテゴリの画面</TextLink>で作れます。
        </AppText>
        <CheckList v-else v-model="chosen" :options="options" legend="カテゴリ" test-prefix="category" variant="chips" />
    </AppField>
    <AppField v-if="tree && options.length > 0" label="主カテゴリ" layout="row" hint="ショップのパンくずリスト（今いるページの場所の表示）に出すカテゴリ">
        <AppSelect v-model="primary" :disabled="disabled || primaryOptions.length === 0" data-test="primary-category">
            <option v-if="primaryOptions.length === 0" value="">（先にカテゴリを選んでください）</option>
            <option v-for="option in primaryOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
        </AppSelect>
    </AppField>
</template>
