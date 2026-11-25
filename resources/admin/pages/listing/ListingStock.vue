<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { api, describe } from '../../api.js';
import { errorsOf } from '../../format.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppField from '../../components/ui/AppField.vue';
import AppInput from '../../components/ui/AppInput.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppSelect from '../../components/ui/AppSelect.vue';
import AppTable from '../../components/ui/AppTable.vue';
import AppText from '../../components/ui/AppText.vue';
import CellTitle from '../../components/ui/CellTitle.vue';
import ChoicePills from '../../components/ui/ChoicePills.vue';
import InsetSection from '../../components/ui/InsetSection.vue';
import NumCell from '../../components/ui/NumCell.vue';

// 商品の在庫（で 1 つの節に）。
// - 今ある商品: 拠点ごとの在庫の表と、入庫・調整。入庫と調整は在庫の記録に残る操作なので、押すとすぐ反映する（下の「保存する」を待たない）。
//   種類のある商品は種類を選んで。返った在庫だけを update:stocks で親へ返す（商品のほかの値は返さない。同時の編集の見張りのバージョンを動かさない）
// - 新しい商品: 「最初の在庫」を書いておくと、商品を作った後に入庫する（step()）。色・サイズを分ける商品は、種類ができてから種類ごとに
const props = defineProps({
    // 店の API（/api/tenants/{tenant}）
    tenantBase: { type: String, required: true },
    listing: { type: Object, default: null },
    // 新しい商品の出品者の拠点（[{ id, name }]）
    locations: { type: Array, default: () => [] },
    adjustmentReasons: { type: Array, required: true },
    // 新しい商品で、色・サイズの選ぶ項目を書いているか（最初の在庫は種類ができてから）
    perVariant: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:stocks']);

const form = reactive({ variant_id: '', location_id: '', operation: 'receive', amount: null, reason: 'stocktake' });
const errors = ref({});
const busy = ref(false);
const message = ref(null);
const error = ref(null);
// 新しい商品の最初の在庫
const initial = reactive({ location_id: '', quantity: null });
const initialErrors = ref({});

const isNew = computed(() => props.listing === null);
const hasOptions = computed(() => (props.listing?.options?.length ?? 0) > 0);
const stockLocations = computed(() => (isNew.value ? props.locations : (props.listing?.stocks ?? []).map((s) => ({ id: s.location_id, name: s.location_name }))));
const fieldError = computed(() => ['quantity', 'delta', 'location_id', 'reason', 'variant_id', 'stock'].map((field) => errors.value[field]?.[0]).find(Boolean) ?? null);
const target = computed(() => props.listing?.variants?.find((v) => v.id === form.variant_id) ?? null);

watch(() => props.listing, (listing) => {
    if (!listing) {
        return;
    }

    if (!listing.variants.some((v) => v.id === form.variant_id)) {
        form.variant_id = listing.variants[0]?.id ?? '';
    }

    form.location_id ||= listing.stocks[0]?.location_id ?? '';
}, { immediate: true });

watch(stockLocations, (locations) => {
    if (!locations.some((l) => l.id === initial.location_id)) {
        initial.location_id = locations[0]?.id ?? '';
    }
}, { immediate: true });

async function apply() {
    busy.value = true;
    errors.value = {};
    message.value = null;
    error.value = null;
    const receive = form.operation === 'receive';
    const body = { location_id: form.location_id, ...(receive ? { quantity: form.amount } : { delta: form.amount, reason: form.reason }) };

    if (hasOptions.value) {
        body.variant_id = form.variant_id;
    }

    const name = hasOptions.value ? `「${target.value?.name ?? ''}」を` : '';

    try {
        const { listing } = await api('POST', `${props.tenantBase}/listings/${encodeURIComponent(props.listing.id)}/stock/${receive ? 'receive' : 'adjust'}`, body);
        emit('update:stocks', listing);
        form.amount = null;
        message.value = `${name}${receive ? '入庫しました' : '在庫を調整しました'}。`;
    } catch (e) {
        // 入力の誤り（422）は入力欄の下にだけ出す。それ以外はこの節のお知らせに
        errors.value = errorsOf(e);
        error.value = fieldError.value ? null : describe(e);
    } finally {
        busy.value = false;
    }
}

// 種類の表の「入庫・調整」から: その種類を選び、数の欄へ
function choose(variantId) {
    form.variant_id = variantId;
    nextTick(() => {
        const input = document.querySelector('[data-test="stock-amount"]');
        input?.scrollIntoView({ block: 'center' });
        input?.focus({ preventScroll: true });
    });
}

const initialAmount = computed(() => (initial.quantity === null || initial.quantity === '' ? 0 : Number(initial.quantity)));
const dirty = computed(() => isNew.value && !props.perVariant && initialAmount.value !== 0);

// 新しい商品の保存の手順（商品を作った後に入庫する）
function step() {
    return {
        label: '最初の在庫',
        section: 'listing-stock',
        run: async (listing) => {
            initialErrors.value = {};

            try {
                const saved = (await api('POST', `${props.tenantBase}/listings/${encodeURIComponent(listing.id)}/stock/receive`, { location_id: initial.location_id, quantity: initial.quantity })).listing;

                return { ...listing, stocks: saved.stocks, variants: saved.variants };
            } catch (e) {
                initialErrors.value = errorsOf(e);
                throw e;
            }
        },
    };
}

defineExpose({ dirty, step, choose });
</script>

<template>
    <AppPanel :title="hasOptions ? '在庫（種類の合計）' : '在庫'" description="お客さんが買える数です。注文が入ると減り、注文を取り消すと戻ります。">
        <template v-if="isNew">
            <AppText v-if="perVariant" size="sm" tone="soft" data-test="stock-per-variant">色・サイズを分ける商品は、保存した後に種類ごとの在庫をここで入力します。</AppText>
            <AppText v-else-if="stockLocations.length === 0" size="sm" tone="soft">この販売者には拠点がありません。</AppText>
            <template v-else>
                <AppField v-if="stockLocations.length > 1" label="拠点" layout="row">
                    <AppSelect v-model="initial.location_id" :disabled="disabled" data-test="initial-stock-location">
                        <option v-for="location in stockLocations" :key="location.id" :value="location.id">{{ location.name }}</option>
                    </AppSelect>
                </AppField>
                <AppField
                    label="最初の在庫"
                    layout="row"
                    optional
                    hint="今ある数です。空欄なら 0 になり、後から入庫できます"
                    :error="initialErrors.quantity?.[0] ?? initialErrors.location_id?.[0] ?? null"
                >
                    <AppInput v-model="initial.quantity" type="number" min="0" step="1" inputmode="numeric" :disabled="disabled" data-test="initial-stock" />
                </AppField>
            </template>
        </template>

        <template v-else>
            <AppText v-if="stockLocations.length === 0" tone="soft">この販売者には拠点がありません。</AppText>
            <template v-else>
                <AppTable label="拠点ごとの在庫">
                    <thead>
                        <tr><th>拠点</th><th class="num">在庫</th><th class="num">確保済み</th><th class="num">引当可能</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="stock in listing.stocks" :key="stock.location_id" data-test="stock-row">
                            <td><CellTitle :label="stock.location_name" /></td>
                            <NumCell :value="stock.quantity" strong data-test="stock-quantity" />
                            <NumCell :value="stock.reserved" soft />
                            <NumCell :value="stock.available" />
                        </tr>
                    </tbody>
                </AppTable>

                <AppNotice v-if="message" tone="success" data-test="stock-message">{{ message }}</AppNotice>
                <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>
                <InsetSection
                    v-if="!disabled"
                    as="form"
                    title="入庫・調整"
                    description="押すとすぐ反映されます（下の「保存する」は不要です）。入庫は在庫の数を増やします。調整は、棚卸しなどで実際の数に合わせます（減らすときは -3 のように入力）。注文で確保済みの数より少なくはできません。"
                    data-test="stock-form"
                    @submit.prevent="apply"
                >
                    <ChoicePills v-model="form.operation" legend="操作" :options="[{ value: 'receive', label: '入庫' }, { value: 'adjust', label: '調整' }]" test-prefix="op" />
                    <div class="grid grid-cols-1 gap-5 md:grid-cols-4">
                        <AppField v-if="hasOptions" label="種類">
                            <AppSelect v-model="form.variant_id" required data-test="stock-variant">
                                <option v-for="variant in listing.variants" :key="variant.id" :value="variant.id">{{ variant.name }}</option>
                            </AppSelect>
                        </AppField>
                        <AppField label="拠点">
                            <AppSelect v-model="form.location_id" required>
                                <option v-for="location in stockLocations" :key="location.id" :value="location.id">{{ location.name }}</option>
                            </AppSelect>
                        </AppField>
                        <AppField :label="form.operation === 'receive' ? '入庫する数' : '増減（-3 など）'" :error="fieldError">
                            <AppInput v-model="form.amount" type="number" step="1" :min="form.operation === 'receive' ? 1 : null" required inputmode="numeric" :aria-invalid="!!fieldError" data-test="stock-amount" />
                        </AppField>
                        <AppField v-if="form.operation === 'adjust'" label="理由">
                            <AppSelect v-model="form.reason" required data-test="stock-reason">
                                <option v-for="reason in adjustmentReasons" :key="reason.value" :value="reason.value">{{ reason.label }}</option>
                            </AppSelect>
                        </AppField>
                    </div>
                    <div class="flex justify-end">
                        <AppButton type="submit" variant="secondary" :loading="busy" :disabled="busy || (hasOptions && !form.variant_id)" data-test="apply-stock">{{ form.operation === 'receive' ? '入庫する' : '調整する' }}</AppButton>
                    </div>
                </InsetSection>
            </template>
        </template>
    </AppPanel>
</template>
