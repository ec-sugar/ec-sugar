<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { api } from '../../api.js';
import { errorAt, moved } from '../../description.js';
import { errorsOf } from '../../format.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppCheckbox from '../../components/ui/AppCheckbox.vue';
import AppField from '../../components/ui/AppField.vue';
import AppIcon from '../../components/ui/AppIcon.vue';
import AppInput from '../../components/ui/AppInput.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppSelect from '../../components/ui/AppSelect.vue';
import AppText from '../../components/ui/AppText.vue';
import BlockFrame from '../../components/ui/BlockFrame.vue';

// 商品のオプション（名入れ・ギフト包装など。ADR 0036）: 在庫の無い追加を 10 個まで並べる。
// 保存は商品の画面の下の「保存する」でまとめて: 変えたかを dirty で、保存の手順を step() で親へ渡す。
// 保存は商品のバージョンを進める。新しい商品（listing が null）でも書いておけ、商品を作った後に保存する
const props = defineProps({
    // 店の API（/api/tenants/{tenant}）。商品の道筋は保存する時の商品の ID から作る
    tenantBase: { type: String, required: true },
    listing: { type: Object, default: null },
    // 閲覧だけの人（運用より下）には変える操作を出さない（見た目だけ。サーバーも 403 で断る）
    disabled: { type: Boolean, default: false },
});

// 上限はサーバー（ListingAddon）と同じ。守るのはサーバー
const MAX_ADDONS = 10;
const MAX_CHOICES = 20;
const MAX_TEXT_LENGTH = 100;
const KINDS = [
    { value: 'choice', label: '選択式' },
    { value: 'text', label: '文字入力' },
    { value: 'toggle', label: 'あり・なし' },
];

let nextKey = 0;
const addons = ref([]);
const errors = ref({});
const list = ref(null);

function readAddons(listing) {
    addons.value = (listing?.addons ?? []).map((addon) => ({
        key: nextKey++,
        kind: addon.kind,
        name: addon.name,
        required: addon.required,
        amount: addon.amount,
        choices: (addon.choices ?? []).map((choice) => ({ key: nextKey++, label: choice.label, amount: choice.amount })),
        max_length: addon.max_length,
        tax_class: addon.tax_class ?? '',
    }));
}

// サーバーのオプションが変わったとき（読み込み・保存・読み込み直し）だけ読み直す（種類や在庫の保存で、書きかけを消さない）
watch(() => JSON.stringify(props.listing?.addons ?? []), () => readAddons(props.listing), { immediate: true });

const number = (value) => (value === '' || value === null || value === undefined ? null : Number(value));

// 送る形（形ごとに要る欄だけ。額の空は 0）
function payload() {
    return addons.value.map((addon) => ({
        kind: addon.kind,
        name: addon.name.trim(),
        // 付けるは必須にしない（付けないも選び方の 1 つ。サーバーも断る）
        required: addon.kind === 'toggle' ? false : addon.required,
        amount: addon.kind === 'choice' ? 0 : (number(addon.amount) ?? 0),
        choices: addon.kind === 'choice' ? addon.choices.map((choice) => ({ label: choice.label.trim(), amount: number(choice.amount) ?? 0 })) : null,
        max_length: addon.kind === 'text' ? number(addon.max_length) : null,
        // 空は商品と同じ税区分（軽減の商品に付ける別料金のギフト包装は標準 10%）
        tax_class: addon.tax_class === '' ? null : addon.tax_class,
    }));
}

const changed = computed(() => JSON.stringify(payload()) !== JSON.stringify((props.listing?.addons ?? []).map((addon) => ({
    kind: addon.kind,
    name: addon.name,
    required: addon.required,
    amount: addon.kind === 'choice' ? 0 : addon.amount,
    choices: addon.kind === 'choice' ? addon.choices : null,
    max_length: addon.kind === 'text' ? addon.max_length : null,
    tax_class: addon.tax_class ?? null,
}))));

async function focusAddon(index, selector) {
    await nextTick();
    list.value?.children[index]?.querySelector(selector)?.focus();
}

function add() {
    addons.value = [...addons.value, { key: nextKey++, kind: 'choice', name: '', required: false, amount: 0, choices: [{ key: nextKey++, label: '', amount: 0 }], max_length: 20, tax_class: '' }];
    focusAddon(addons.value.length - 1, '[data-test="addon-name"]');
}

function move(index, step) {
    addons.value = moved(addons.value, index, step);
    const target = index + step;
    const atEdge = (step < 0 && target === 0) || (step > 0 && target === addons.value.length - 1);
    focusAddon(target, `[data-move="${atEdge ? (step < 0 ? 'down' : 'up') : (step < 0 ? 'up' : 'down')}"]`);
}

function remove(index) {
    addons.value = addons.value.filter((_, i) => i !== index);
}

// 形を変える。要る欄が空なら始めの値を入れる（選ぶは選択肢 1 つ、入れる文字は 20 文字まで）
function setKind(addon, kind) {
    addon.kind = kind;

    // 付けるは必須にしない（必須の切り替えも出さない）
    if (addon.kind === 'toggle') {
        addon.required = false;
    }

    if (addon.kind === 'choice' && addon.choices.length === 0) {
        addon.choices = [{ key: nextKey++, label: '', amount: 0 }];
    }

    if (addon.kind === 'text' && number(addon.max_length) === null) {
        addon.max_length = 20;
    }
}

function addChoice(addon) {
    addon.choices = [...addon.choices, { key: nextKey++, label: '', amount: 0 }];
}

function removeChoice(addon, index) {
    addon.choices = addon.choices.filter((_, i) => i !== index);
}

function fieldError(index, field) {
    return errorAt(errors.value, `addons.${index}.${field}`);
}

// 枠の下に出す誤り（欄に結び付かないもの）
function frameError(index) {
    const own = ['kind', 'name', 'amount', 'max_length', 'choices', 'required', 'tax_class'].some((field) => fieldError(index, field));

    return own ? null : errorAt(errors.value, `addons.${index}`);
}

// 保存の手順（親の「保存する」から呼ぶ）。誤り（422）は欄の下に出して、投げ直す（親が止めて通知する）
function step() {
    return {
        label: 'オプション',
        section: 'listing-addons',
        run: async (listing) => {
            errors.value = {};

            try {
                return (await api('PUT', `${props.tenantBase}/listings/${encodeURIComponent(listing.id)}/addons`, { version: listing.version, addons: payload() })).listing;
            } catch (e) {
                errors.value = errorsOf(e);
                throw e;
            }
        },
    };
}

defineExpose({ dirty: changed, step });
</script>

<template>
    <AppPanel title="オプション" description="名入れ・ギフト包装のような、在庫を持たない追加の項目を 10 個まで設定できます。種類は「選択式」（選択肢ごとの追加料金）・「文字入力」（名入れなど）・「あり・なし」（付けるか付けないか）の 3 つです。">
        <div class="flex flex-col gap-4" data-test="addon-editor">
            <AppText v-if="addons.length === 0" size="sm" tone="soft" data-test="no-addons">オプションはまだありません。使わない商品は、空のままで大丈夫です。</AppText>
            <ol ref="list" class="flex flex-col gap-3" aria-label="オプションの並び">
                <BlockFrame
                    v-for="(addon, index) in addons"
                    :key="addon.key"
                    label="オプション"
                    :position="index + 1"
                    :total="addons.length"
                    :error="frameError(index)"
                    :disabled="disabled"
                    test="addon"
                    @up="move(index, -1)"
                    @down="move(index, 1)"
                    @remove="remove(index)"
                >
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-12 md:items-start">
                        <AppField label="種類" :error="fieldError(index, 'kind')" class="md:col-span-3">
                            <AppSelect :model-value="addon.kind" :disabled="disabled" data-test="addon-kind" @update:model-value="(kind) => setKind(addon, kind)">
                                <option v-for="kind in KINDS" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
                            </AppSelect>
                        </AppField>
                        <AppField label="名前" :error="fieldError(index, 'name')" class="md:col-span-5">
                            <AppInput v-model="addon.name" maxlength="40" placeholder="ギフト包装・名入れなど" :disabled="disabled" :aria-invalid="!!fieldError(index, 'name')" data-test="addon-name" />
                        </AppField>
                        <AppField v-if="addon.kind !== 'choice'" label="追加料金（円）" :error="fieldError(index, 'amount')" class="md:col-span-2">
                            <AppInput v-model="addon.amount" type="number" min="0" step="1" inputmode="numeric" :disabled="disabled" :aria-invalid="!!fieldError(index, 'amount')" data-test="addon-amount" />
                        </AppField>
                        <AppField label="税区分" :error="fieldError(index, 'tax_class')" hint="別料金の包装は、食品でも標準 10% です" class="md:col-span-4">
                            <AppSelect v-model="addon.tax_class" :disabled="disabled" data-test="addon-tax-class">
                                <option value="">商品と同じ</option>
                                <option value="standard">標準 10%</option>
                                <option value="reduced">軽減 8%（食品・飲み物）</option>
                            </AppSelect>
                        </AppField>
                        <AppField v-if="addon.kind === 'text'" label="最大の文字数" :error="fieldError(index, 'max_length')" class="md:col-span-2">
                            <AppInput v-model="addon.max_length" type="number" min="1" :max="MAX_TEXT_LENGTH" step="1" inputmode="numeric" :disabled="disabled" :aria-invalid="!!fieldError(index, 'max_length')" data-test="addon-max-length" />
                        </AppField>
                    </div>

                    <div v-if="addon.kind === 'choice'" class="flex flex-col gap-2" data-test="addon-choices">
                        <AppText size="xs" tone="soft">選択肢と追加料金（お客さんが 1 つ選びます。追加料金が ¥0 の選択肢も作れます）</AppText>
                        <div v-for="(choice, choiceIndex) in addon.choices" :key="choice.key" class="grid grid-cols-[1fr_8rem_auto] items-center gap-2" data-test="addon-choice">
                            <AppInput v-model="choice.label" maxlength="40" placeholder="なし・ありなど" :disabled="disabled" :aria-label="`${index + 1} 番目のオプションの選択肢 ${choiceIndex + 1} の名前`" :aria-invalid="!!fieldError(index, `choices.${choiceIndex}`)" data-test="addon-choice-label" />
                            <AppInput v-model="choice.amount" type="number" min="0" step="1" inputmode="numeric" :disabled="disabled" :aria-label="`${index + 1} 番目のオプションの選択肢 ${choiceIndex + 1} の追加料金（円）`" data-test="addon-choice-amount" />
                            <AppButton v-if="!disabled" size="sm" variant="ghost" :disabled="addon.choices.length <= 1" :aria-label="`${index + 1} 番目のオプションの選択肢 ${choiceIndex + 1} を削除`" data-test="remove-addon-choice" @click="removeChoice(addon, choiceIndex)">削除</AppButton>
                        </div>
                        <AppText v-if="fieldError(index, 'choices')" size="xs" tone="danger" role="alert">{{ fieldError(index, 'choices') }}</AppText>
                        <div v-if="!disabled" class="flex">
                            <AppButton size="sm" variant="ghost" :disabled="addon.choices.length >= MAX_CHOICES" data-test="add-addon-choice" @click="addChoice(addon)"><AppIcon name="plus" />選択肢を追加</AppButton>
                        </div>
                    </div>

                    <!-- 付けるは「付けない」も選び方なので、必須の切り替えを出さない -->
                    <AppCheckbox v-if="addon.kind !== 'toggle'" v-model="addon.required" label="必須" :disabled="disabled" test="addon-required" />
                    <AppText v-if="fieldError(index, 'required')" size="xs" tone="danger" role="alert">{{ fieldError(index, 'required') }}</AppText>
                </BlockFrame>
            </ol>
            <AppText v-if="errors.addons" size="sm" tone="danger" role="alert">{{ errors.addons[0] }}</AppText>
            <div v-if="!disabled" class="flex flex-wrap items-center justify-between gap-3">
                <AppButton size="sm" :disabled="addons.length >= MAX_ADDONS" data-test="add-addon" @click="add"><AppIcon name="plus" />オプションを追加</AppButton>
                <AppText v-if="changed && listing" size="sm" tone="soft" data-test="addons-changed">保存すると、この商品をカートに入れているお客さんは、オプションを選び直すことになります。</AppText>
            </div>
        </div>
    </AppPanel>
</template>
