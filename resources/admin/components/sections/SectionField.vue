<script setup>
import { computed } from 'vue';
import AppButton from '../ui/AppButton.vue';
import AppField from '../ui/AppField.vue';
import AppInput from '../ui/AppInput.vue';
import AppSelect from '../ui/AppSelect.vue';
import AppText from '../ui/AppText.vue';
import AppTextarea from '../ui/AppTextarea.vue';
import CheckList from '../ui/CheckList.vue';
import ChoicePills from '../ui/ChoicePills.vue';
import PhotoPicker from './PhotoPicker.vue';
import RichTextEditor from './RichTextEditor.vue';
import SectionHtmlField from './SectionHtmlField.vue';

// セクションの欄 1 つ（ADR 0028 区切り 2b）。欄の種類（テーマの theme.json の宣言）ごとに入力の形を変える。
// 長さや数の上限は宣言の max（サーバーも同じ上限で確認する）。誤りは道筋（sections.hero.values.heading など）で受け取る
const model = defineModel({ type: null, default: null });
const props = defineProps({
    // 宣言（{ type, label, max, options, from, help }）と、欄の名前
    spec: { type: Object, required: true },
    // 誤りの道筋の頭（sections.hero.values.heading）。この下の誤り（.source など）も拾う
    path: { type: String, required: true },
    errors: { type: Object, required: true },
    // 選ぶための材料: ショップの写真・カテゴリ（字下げつきの平らな並び）・公開中の商品
    context: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
    test: { type: String, default: null },
});
const emit = defineEmits(['uploaded']);

const SOURCE_LABELS = { new: '新着', category: 'カテゴリの商品', pick: '選んだ商品' };

const error = computed(() => {
    const key = Object.keys(props.errors).find((k) => k === props.path || k.startsWith(`${props.path}.`));
    return key ? props.errors[key][0] : null;
});
const hint = computed(() => [props.spec.help, props.spec.max && ['text', 'textarea'].includes(props.spec.type) ? `${props.spec.max} 字まで` : null].filter(Boolean).join('。') || null);

// 商品の並び（{ source, category, ids }）
const products = computed(() => model.value ?? null);
const sources = computed(() => [{ value: '', label: '表示しない' }, ...(props.spec.from ?? []).map((value) => ({ value, label: SOURCE_LABELS[value] ?? value }))]);
const listingTitle = (id) => props.context.listings.find((l) => l.id === id)?.title ?? '公開していないか、削除した商品';
const pickable = computed(() => props.context.listings.filter((l) => !(products.value?.ids ?? []).includes(l.id)));

function setSource(source) {
    model.value = source === '' ? null : { source, category: products.value?.category ?? null, ids: products.value?.ids ?? [] };
}

function patchProducts(change) {
    model.value = { ...products.value, ...change };
}

function moveId(index, offset) {
    const ids = [...products.value.ids];
    const [id] = ids.splice(index, 1);
    ids.splice(index + offset, 0, id);
    patchProducts({ ids });
}

// カテゴリの並び（選んだ順）
const categoryOptions = computed(() => props.context.categories.map((c) => ({ value: c.id, label: c.name, depth: c.depth })));
const chosenCategories = computed({
    get: () => model.value ?? [],
    // 外したものを除き、足したものを後ろに（選んだ順を保つ）
    set: (next) => {
        const kept = (model.value ?? []).filter((id) => next.includes(id));
        model.value = [...kept, ...next.filter((id) => !kept.includes(id))];
    },
});
</script>

<template>
    <AppField v-if="spec.type === 'text'" :label="spec.label" optional :hint="hint" :error="error">
        <AppInput v-model="model" :maxlength="spec.max" :disabled="disabled" :aria-invalid="!!error" :data-test="test" />
    </AppField>

    <AppField v-else-if="spec.type === 'textarea'" :label="spec.label" optional :hint="hint" :error="error">
        <AppTextarea v-model="model" :rows="spec.max > 100 ? 3 : 2" :maxlength="spec.max" :disabled="disabled" :aria-invalid="!!error" :data-test="test" />
    </AppField>

    <AppField v-else-if="spec.type === 'link'" :label="spec.label" optional :hint="spec.help ?? 'ショップ内のページ（/products など）か、「https」で始まる URL'" :error="error">
        <AppInput v-model="model" maxlength="500" inputmode="url" :disabled="disabled" :aria-invalid="!!error" :data-test="test" />
    </AppField>

    <AppField v-else-if="spec.type === 'choice'" :label="spec.label" optional :hint="spec.help" :error="error">
        <AppSelect v-model="model" :disabled="disabled" :data-test="test">
            <option :value="null">選ばない</option>
            <option v-for="(label, value) in spec.options" :key="value" :value="value">{{ label }}</option>
        </AppSelect>
    </AppField>

    <!-- HTML の欄: 自由な HTML は等幅の欄、見たまま書ける文は道具の帯つきの欄。どちらも整えるのはサーバー -->
    <SectionHtmlField v-else-if="spec.type === 'html'" v-model="model" :spec="spec" :error="error" :context="context" :disabled="disabled" :test="test" @uploaded="emit('uploaded', $event)" />
    <RichTextEditor v-else-if="spec.type === 'richtext'" v-model="model" :spec="spec" :error="error" :context="context" :disabled="disabled" :test="test" @uploaded="emit('uploaded', $event)" />

    <div v-else-if="spec.type === 'image'" class="flex flex-col gap-1.5">
        <AppText size="sm" tone="muted">{{ spec.label }}</AppText>
        <PhotoPicker v-model="model" :label="spec.label" :media="context.media" :base="context.mediaBase" :disabled="disabled" :test="test" @uploaded="emit('uploaded', $event)" />
        <AppText v-if="error" size="xs" tone="danger" role="alert">{{ error }}</AppText>
        <AppText v-else-if="spec.help" size="xs" tone="soft">{{ spec.help }}</AppText>
    </div>

    <div v-else-if="spec.type === 'categories'" class="flex flex-col gap-1.5" :data-test="test">
        <AppText size="sm" tone="muted">{{ spec.label }}</AppText>
        <AppText size="xs" tone="soft">{{ spec.help ?? '選ばない場合は、いちばん上の階層のカテゴリを並べます' }}（{{ spec.max }} 個まで。選んだ順に並びます）</AppText>
        <AppText v-if="context.categories.length === 0" size="sm" tone="soft">カテゴリがまだありません。「カテゴリ」の画面で作れます。</AppText>
        <CheckList v-else v-model="chosenCategories" :options="categoryOptions" :legend="spec.label" :test-prefix="test" :disabled="disabled" variant="chips" />
        <AppText v-if="chosenCategories.length > spec.max" size="xs" tone="danger" role="alert">{{ spec.max }} 個までです。{{ chosenCategories.length - spec.max }} 個の選択を解除してください。</AppText>
        <AppText v-if="error" size="xs" tone="danger" role="alert">{{ error }}</AppText>
    </div>

    <div v-else-if="spec.type === 'products'" class="flex flex-col gap-3" :data-test="test">
        <AppText size="sm" tone="muted">{{ spec.label }}</AppText>
        <ChoicePills :disabled="disabled" :model-value="products?.source ?? ''" :options="sources" :legend="`${spec.label}の取得元`" :test-prefix="test ? `${test}-source` : null" @update:model-value="setSource" />
        <AppText v-if="products?.source === 'new'" size="xs" tone="soft">更新日の新しい順に {{ spec.max }} 点まで並びます。</AppText>
        <AppField v-if="products?.source === 'category'" label="カテゴリ" :hint="`下の階層のカテゴリの商品も含めて、新しい順に ${spec.max} 点まで`">
            <AppSelect :model-value="products.category" :disabled="disabled" :data-test="test ? `${test}-category` : null" @update:model-value="patchProducts({ category: $event })">
                <option :value="null" disabled>選んでください</option>
                <option v-for="c in context.categories" :key="c.id" :value="c.id">{{ '　'.repeat(c.depth) }}{{ c.name }}</option>
            </AppSelect>
        </AppField>
        <div v-if="products?.source === 'pick'" class="flex flex-col gap-2">
            <ol v-if="products.ids.length > 0" class="flex flex-col gap-1">
                <li v-for="(id, index) in products.ids" :key="id" class="flex flex-wrap items-center gap-2" data-test="picked-product">
                    <AppText size="sm" class="grow">{{ index + 1 }}. {{ listingTitle(id) }}</AppText>
                    <AppButton size="sm" variant="ghost" :disabled="disabled || index === 0" :aria-label="`${index + 1} 番目を上へ`" @click="moveId(index, -1)">上へ</AppButton>
                    <AppButton size="sm" variant="ghost" :disabled="disabled || index === products.ids.length - 1" :aria-label="`${index + 1} 番目を下へ`" @click="moveId(index, 1)">下へ</AppButton>
                    <AppButton size="sm" variant="ghost" :disabled="disabled" :aria-label="`${index + 1} 番目を削除`" @click="patchProducts({ ids: products.ids.filter((x) => x !== id) })">削除</AppButton>
                </li>
            </ol>
            <AppField v-if="products.ids.length < spec.max" label="商品を追加" :hint="`公開中の商品から ${spec.max} 点まで`">
                <AppSelect :model-value="''" :disabled="disabled" :data-test="test ? `${test}-add` : null" @update:model-value="$event && patchProducts({ ids: [...products.ids, $event] })">
                    <option value="">選んでください</option>
                    <option v-for="l in pickable" :key="l.id" :value="l.id">{{ l.title }}</option>
                </AppSelect>
            </AppField>
        </div>
        <AppText v-if="error" size="xs" tone="danger" role="alert">{{ error }}</AppText>
    </div>
</template>
