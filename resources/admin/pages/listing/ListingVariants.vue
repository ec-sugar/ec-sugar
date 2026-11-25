<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { api } from '../../api.js';
import { errorsOf, formatAmount } from '../../format.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppCheckbox from '../../components/ui/AppCheckbox.vue';
import AppField from '../../components/ui/AppField.vue';
import AppInput from '../../components/ui/AppInput.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppSelect from '../../components/ui/AppSelect.vue';
import AppTable from '../../components/ui/AppTable.vue';
import AppText from '../../components/ui/AppText.vue';
import CellTitle from '../../components/ui/CellTitle.vue';
import InsetSection from '../../components/ui/InsetSection.vue';
import TagInput from '../../components/ui/TagInput.vue';

// 商品の種類（色・サイズなど。ADR 0036）: 選択項目（軸）と値を決め、組み合わせごとに価格・品番・写真・販売中を変える。
// 保存は商品の画面の下の「保存する」でまとめて: 変えたかを dirty で、保存の手順を steps() で親へ渡す
// （軸 → 種類ごとの行の順。どれも商品のバージョンを進める）。保存すると消える種類があるときは removalNote に一言（親が確認してから保存する）。
// 新しい商品（listing が null）は軸だけ書いておけ、商品を作った後に保存する（種類ごとの行は、種類ができてから）。
// 在庫は「在庫」の節（ListingStock）で。行の「入庫・調整」は stock でその種類を親へ通知する
const props = defineProps({
    // 店の API（/api/tenants/{tenant}）。商品の道筋は保存する時の商品の ID から作る
    tenantBase: { type: String, required: true },
    listing: { type: Object, default: null },
    // 閲覧だけの人（運用より下）には変える操作を出さない（見た目だけ。サーバーも 403 で断る）
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['stock']);

const MAX_AXES = 3;
const MAX_VARIANTS = 100;
// 値の名前の区切り（サーバーの種類の名前と同じ）
const SEPARATOR = ' / ';

let nextKey = 0;
const axes = ref([]);
const axisErrors = ref({});

const rows = reactive({});
const rowErrors = ref({});

const isNew = computed(() => props.listing === null);
const options = computed(() => props.listing?.options ?? []);
const variants = computed(() => props.listing?.variants ?? []);
const images = computed(() => props.listing?.images ?? []);
const hasOptions = computed(() => options.value.length > 0);
const locations = computed(() => (props.listing?.stocks ?? []).map((s) => ({ id: s.location_id, name: s.location_name })));

function readAxes(listing) {
    axes.value = (listing?.options ?? []).map((option) => ({ key: nextKey++, name: option.name, values: option.values.map((v) => v.label) }));
}

function rowOf(variant) {
    return {
        unit_amount: variant.unit_amount,
        sku: variant.sku ?? '',
        active: variant.status === 'active',
        image_id: variant.image_id ?? '',
    };
}

function rowChanged(variant, row) {
    return row !== undefined && (
        (row.unit_amount === '' || row.unit_amount === null ? null : Number(row.unit_amount)) !== variant.unit_amount
        || (row.sku || null) !== variant.sku
        || row.active !== (variant.status === 'active')
        || (row.image_id || null) !== variant.image_id
    );
}

// 行の下書きを読み直す。保存の途中（ほかの手順の後）や在庫の操作で、書きかけの行を消さない（前の値から変えていた行は残す）
function readRows(listing, previous) {
    const before = Object.fromEntries((previous?.variants ?? []).map((v) => [v.id, v]));
    const next = {};

    for (const variant of listing?.variants ?? []) {
        const old = before[variant.id];
        next[variant.id] = old && rows[variant.id] && rowChanged(old, rows[variant.id]) ? rows[variant.id] : rowOf(variant);
    }

    for (const key of Object.keys(rows)) {
        delete rows[key];
    }

    Object.assign(rows, next);
}

// 軸はサーバーの軸が変わったとき（読み込み・軸の保存・読み込み直し）だけ読み直す（ほかの保存で、書きかけの軸を消さない）
watch(() => JSON.stringify(props.listing?.options ?? []), () => readAxes(props.listing), { immediate: true });
watch(() => props.listing, readRows, { immediate: true });

function changed(variant) {
    return rowChanged(variant, rows[variant.id]);
}

// 軸を決めたときにできる組み合わせ（最初の軸が外側。サーバーと同じ順）
const draftCombinations = computed(() => {
    let result = [[]];

    for (const axis of axes.value) {
        result = result.flatMap((prefix) => axis.values.map((value) => [...prefix, value.trim()]));
    }

    return result;
});

const axesReady = computed(() => axes.value.every((axis) => axis.name.trim() !== '' && axis.values.length > 0) && draftCombinations.value.length <= MAX_VARIANTS);
const axesChanged = computed(() => JSON.stringify(axes.value.map((a) => [a.name.trim(), a.values])) !== JSON.stringify(options.value.map((o) => [o.name, o.values.map((v) => v.label)])));
const rowsChanged = computed(() => variants.value.some((variant) => changed(variant)));

// 軸の無い商品に初めて軸を足すとき、今の品番と在庫を渡す組み合わせ（値の名前を区切りでつないだもの。'' は渡さない）。
// 新しい商品は渡すものが無いので選ばせない
const firstAxes = computed(() => !isNew.value && !hasOptions.value && axes.value.length > 0);
const carryTo = ref('');
const carryChoices = computed(() => (axesReady.value ? draftCombinations.value.map((labels) => labels.join(SEPARATOR)) : []));

// 組み合わせが変わったら、渡す先が無くなっていれば最初の組み合わせに（初めて軸を足すときは渡すのを既定に）
watch(carryChoices, (choices) => {
    if (carryTo.value === '' || !choices.includes(carryTo.value)) {
        carryTo.value = choices[0] ?? '';
    }
});

// 保存すると消える種類（今の組み合わせのうち、新しい軸に無いもの）。渡す先を選んだら、今の種類は消えない
const removedVariants = computed(() => {
    if (isNew.value || !axesChanged.value || (firstAxes.value && carryTo.value !== '')) {
        return [];
    }

    const wanted = new Set(draftCombinations.value.map((labels) => labels.join(SEPARATOR)));

    return variants.value.filter((variant) => !wanted.has(variant.name));
});

// 保存すると消えるものの一言（消えるものが無ければ null。親は保存の前に確認する）
const removalNote = computed(() => {
    if (removedVariants.value.length === 0) {
        return null;
    }

    if (!hasOptions.value) {
        const sole = variants.value[0];
        const quantity = (sole?.stocks ?? []).reduce((sum, s) => sum + s.quantity, 0);
        const sku = sole?.sku ? `品番（${sole.sku}）と` : '';

        return `保存すると、今の${sku}在庫（${quantity} 個）は引き継がれません。引き継ぐなら「種類」の「今の品番と在庫を引き継ぐ種類」で選んでください。`;
    }

    return `保存すると、${removedVariants.value.length} 件の種類が削除されます（その種類の価格・品番・在庫も削除されます。注文の記録は残ります）。`;
});

const carryHint = computed(() => {
    const sole = variants.value[0];
    const quantity = (sole?.stocks ?? []).reduce((sum, s) => sum + s.quantity, 0);

    return `今の${sole?.sku ? `品番（${sole.sku}）・` : ''}価格・写真・在庫（${quantity} 個）を、選んだ種類にそのまま移します。ほかの種類は、品番と在庫が空の状態から始まります。`;
});

function addAxis() {
    axes.value = [...axes.value, { key: nextKey++, name: '', values: [] }];
    nextTick(() => document.querySelector(`[data-axis-name="${axes.value.length - 1}"]`)?.focus());
}

function removeAxis(index) {
    axes.value = axes.value.filter((_, i) => i !== index);
}

function axisError(index, field) {
    const prefix = `options.${index}.${field}`;
    const key = Object.keys(axisErrors.value).find((k) => k === prefix || k.startsWith(`${prefix}.`));

    return key ? axisErrors.value[key][0] : null;
}

function rowError(variant, field) {
    return rowErrors.value[variant.id]?.[field]?.[0] ?? null;
}

// 保存の手順（親の「保存する」から呼ぶ）。軸 → 種類ごとの行の順。誤り（422）は欄の下に出して、投げ直す（親が止めて通知する）
function steps() {
    const list = [];
    const base = (listing) => `${props.tenantBase}/listings/${encodeURIComponent(listing.id)}`;

    if (axesChanged.value) {
        // 新しい商品では、今の種類（品番も在庫もまだ無い）を渡さない
        const carry = firstAxes.value && carryTo.value !== '' ? carryTo.value.split(SEPARATOR) : null;
        const defined = axes.value.map((axis) => ({ name: axis.name.trim(), values: axis.values }));

        list.push({
            label: '種類（選択項目）',
            section: 'listing-variants',
            run: async (listing) => {
                axisErrors.value = {};

                try {
                    return (await api('PUT', `${base(listing)}/options`, { version: listing.version, options: defined, carry_to: carry })).listing;
                } catch (e) {
                    axisErrors.value = errorsOf(e);
                    throw e;
                }
            },
        });
    }

    if (rowsChanged.value) {
        list.push({
            label: '種類ごとの価格・品番',
            section: 'listing-variants',
            run: async (listing) => {
                rowErrors.value = {};
                let current = listing;

                // 軸を保存した後に残った種類（名前の同じ組み合わせは ID が変わらない）のうち、変えた行だけ。消えた種類の書きかけは送らない
                for (const variant of listing.variants) {
                    const row = rows[variant.id];

                    if (!rowChanged(variant, row)) {
                        continue;
                    }

                    try {
                        current = (await api('PATCH', `${base(current)}/variants/${encodeURIComponent(variant.id)}`, {
                            version: current.version,
                            unit_amount: row.unit_amount === '' ? null : row.unit_amount,
                            sku: row.sku,
                            status: row.active ? 'active' : 'stopped',
                            image_id: row.image_id || null,
                        })).listing;
                    } catch (e) {
                        rowErrors.value = { [variant.id]: errorsOf(e) };
                        e.subject = `「${variant.name}」`;
                        e.partial = current;
                        throw e;
                    }
                }

                return current;
            },
        });
    }

    return list;
}

const dirty = computed(() => axesChanged.value || rowsChanged.value);
// 下書きの軸があるか（新しい商品で、品番と最初の在庫を種類ごとにするため）
const draftAxes = computed(() => axes.value.length > 0);

defineExpose({ dirty, removalNote, draftAxes, steps });

function photoLabel(image, index) {
    return image.alt ? `写真 ${index + 1}（${image.alt}）` : `写真 ${index + 1}`;
}
</script>

<template>
    <AppPanel title="種類（色・サイズ）" description="色・サイズなどの選択項目を 3 つまで設定すると、値の組み合わせごとに種類が作られ、それぞれに価格・品番・在庫を設定できます。種類を分けない商品は、空のままで大丈夫です。">
        <InsetSection title="色・サイズなどの選択項目" description="値はカンマか Enter で区切って追加します。">
            <AppText v-if="axes.length === 0" size="sm" tone="soft" data-test="no-axes">選択項目はまだありません。</AppText>
            <div v-for="(axis, index) in axes" :key="axis.key" class="grid grid-cols-1 gap-3 md:grid-cols-12 md:items-start" data-test="axis">
                <AppField :label="`選択項目 ${index + 1}`" :error="axisError(index, 'name')" class="md:col-span-4">
                    <AppInput v-model="axis.name" maxlength="40" placeholder="色・サイズなど" :disabled="disabled" :aria-invalid="!!axisError(index, 'name')" :data-axis-name="index" data-test="axis-name" />
                </AppField>
                <TagInput v-model="axis.values" class="md:col-span-7" label="値" placeholder="赤, 青, 白" :max="30" :error="axisError(index, 'values')" :disabled="disabled" test="axis-values" />
                <div v-if="!disabled" class="flex md:col-span-1 md:pt-6">
                    <AppButton size="sm" variant="ghost" :aria-label="`選択項目 ${index + 1} を削除`" data-test="remove-axis" @click="removeAxis(index)">削除</AppButton>
                </div>
            </div>
            <AppField
                v-if="firstAxes && carryChoices.length > 0"
                label="今の品番と在庫を引き継ぐ種類"
                :hint="carryHint"
                :error="axisErrors.carry_to?.[0] ?? null"
            >
                <AppSelect v-model="carryTo" :disabled="disabled" data-test="carry-to">
                    <option v-for="choice in carryChoices" :key="choice" :value="choice">{{ choice }}</option>
                    <option value="">引き継がない（品番と在庫は削除されます）</option>
                </AppSelect>
            </AppField>
            <AppText v-if="axisErrors.options" size="sm" tone="danger" role="alert">{{ axisErrors.options[0] }}</AppText>
            <div v-if="!disabled" class="flex flex-wrap items-center justify-between gap-3">
                <AppButton size="sm" :disabled="axes.length >= MAX_AXES" data-test="add-axis" @click="addAxis">選択項目を追加</AppButton>
                <AppText v-if="axes.length > 0" size="sm" :tone="axesReady ? 'soft' : 'danger'" data-test="combination-count">
                    組み合わせ {{ draftCombinations.length }} 件（{{ MAX_VARIANTS }} 件まで）{{ axesReady ? '' : '。項目の名前と値を 1 つ以上入力してください' }}
                </AppText>
            </div>
            <AppText v-if="axesChanged && hasOptions" size="sm" tone="soft" data-test="axes-changed">保存すると、下の種類の表が新しい組み合わせになります（名前の同じ種類は、価格・品番・在庫をそのまま残します）。</AppText>
            <AppText v-if="isNew && axes.length > 0" size="sm" tone="soft" data-test="axes-new">組み合わせごとの価格・品番・在庫は、保存した後にこの下の表で入力できます。</AppText>
        </InsetSection>

        <AppTable v-if="hasOptions" label="種類">
            <thead>
                <tr><th>種類</th><th>価格（円）</th><th>品番</th><th>在庫</th><th>写真</th><th>販売</th></tr>
            </thead>
            <tbody>
                <tr v-for="variant in variants" :key="variant.id" :data-changed="changed(variant) ? 'true' : null" data-test="variant-row">
                    <td><CellTitle :label="variant.name" /></td>
                    <td class="min-w-28">
                        <AppInput
                            v-model="rows[variant.id].unit_amount"
                            type="number"
                            min="0"
                            step="1"
                            inputmode="numeric"
                            :placeholder="listing.unit_amount === null ? '' : formatAmount(listing.unit_amount)"
                            :disabled="disabled"
                            :aria-label="`「${variant.name}」の価格（空欄なら商品の価格）`"
                            :aria-invalid="!!rowError(variant, 'unit_amount')"
                            data-test="variant-amount"
                        />
                        <AppText v-if="rowError(variant, 'unit_amount')" size="xs" tone="danger" role="alert">{{ rowError(variant, 'unit_amount') }}</AppText>
                    </td>
                    <td class="min-w-32">
                        <AppInput
                            v-model="rows[variant.id].sku"
                            maxlength="64"
                            pattern="[A-Za-z0-9][A-Za-z0-9._\-]*"
                            autocomplete="off"
                            :disabled="disabled"
                            :aria-label="`「${variant.name}」の品番`"
                            :aria-invalid="!!rowError(variant, 'sku')"
                            data-test="variant-sku"
                        />
                        <AppText v-if="rowError(variant, 'sku')" size="xs" tone="danger" role="alert" data-test="variant-sku-error">{{ rowError(variant, 'sku') }}</AppText>
                    </td>
                    <td>
                        <div class="flex flex-col gap-1">
                            <AppText v-for="stock in variant.stocks" :key="stock.location_id" size="xs" tone="muted" nowrap data-test="variant-stock">
                                {{ locations.length > 1 ? `${stock.location_name} ` : '' }}{{ stock.quantity }} 個{{ stock.reserved > 0 ? `（確保 ${stock.reserved}）` : '' }}
                            </AppText>
                            <div v-if="!disabled && locations.length > 0">
                                <AppButton size="sm" variant="ghost" data-test="variant-stock-open" @click="emit('stock', variant.id)">入庫・調整</AppButton>
                            </div>
                        </div>
                    </td>
                    <td class="min-w-32">
                        <AppSelect v-model="rows[variant.id].image_id" :disabled="disabled || images.length === 0" :aria-label="`「${variant.name}」の写真`" data-test="variant-image">
                            <option value="">{{ images.length === 0 ? '（写真がありません）' : 'なし' }}</option>
                            <option v-for="(image, index) in images" :key="image.id" :value="image.id">{{ photoLabel(image, index) }}</option>
                        </AppSelect>
                    </td>
                    <td><AppCheckbox v-model="rows[variant.id].active" label="販売中" :disabled="disabled" test="variant-active" /></td>
                </tr>
            </tbody>
        </AppTable>
    </AppPanel>
</template>
