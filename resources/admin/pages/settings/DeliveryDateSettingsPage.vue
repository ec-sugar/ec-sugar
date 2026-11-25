<script setup>
import { computed, ref, watch } from 'vue';
import { api, describe } from '../../api.js';
import { errorsOf, firstError } from '../../format.js';
import { crumbsFor, titleOf } from '../../navigation.js';
import { helpLink } from '../../help/index.js';
import { canInTenant, tenantName } from '../../tenants.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppCheckbox from '../../components/ui/AppCheckbox.vue';
import AppField from '../../components/ui/AppField.vue';
import AppInput from '../../components/ui/AppInput.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppText from '../../components/ui/AppText.vue';
import ChoicePills from '../../components/ui/ChoicePills.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import FormActions from '../../components/ui/FormActions.vue';
import LoadingText from '../../components/ui/LoadingText.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import TagInput from '../../components/ui/TagInput.vue';
import SettingsLayout from '../../components/SettingsLayout.vue';

// 設定 › お届け日時（ADR 0059）。お客さんが注文の入力でお届け日と時間帯を選べるようにする。
// 選べる日は日本の時間で、受付の日（締め切りの後は翌日）→ 発送までの日数（休みは数えない）→ お届けまでの日数 → 選べる日数の分。
// 読むのは閲覧から、変えるのは運用から（サーバーも 403 で断る）
const props = defineProps({ tenant: { type: String, required: true } });

const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];
const loaded = ref(false);
const loadError = ref(null);
const busy = ref(false);
const errors = ref({});
const error = ref(null);
const message = ref(null);
const server = ref(null);
const slotOptions = ref([]);
const preview = ref(null);
const draft = ref(null);

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}/shop-settings/delivery-dates`);
const canEdit = computed(() => canInTenant(props.tenant, 'operate'));
const crumbs = computed(() => crumbsFor('settings-delivery-dates', props.tenant, tenantName(props.tenant)));
const dirty = computed(() => draft.value !== null && JSON.stringify(body(draft.value)) !== JSON.stringify(body(fromServer(server.value))));
const datesChoice = computed({
    get: () => (draft.value?.dates ? 'on' : 'off'),
    set: (value) => {
        draft.value.dates = value === 'on';
    },
});

function fromServer(delivery) {
    return {
        dates: delivery.dates === true,
        slots: [...delivery.slots],
        ship_days: delivery.ship_days,
        transit_days: delivery.transit_days,
        span_days: delivery.span_days,
        cutoff: delivery.cutoff ?? '',
        closed_weekdays: [...delivery.closed_weekdays],
        closed_dates: [...delivery.closed_dates],
    };
}

// 送る形（数の欄は文字になりうるので数に、空の時刻は null、休みの日の「/」は「-」に）
function body(d) {
    return {
        dates: d.dates,
        slots: [...d.slots].sort(),
        ship_days: Number(d.ship_days),
        transit_days: Number(d.transit_days),
        span_days: Number(d.span_days),
        cutoff: d.cutoff === '' ? null : d.cutoff,
        closed_weekdays: [...d.closed_weekdays].sort(),
        closed_dates: d.closed_dates.map((v) => v.replaceAll('/', '-')),
    };
}

function receive(data) {
    server.value = data.delivery;
    slotOptions.value = data.slot_options;
    preview.value = data.preview;
    draft.value = fromServer(data.delivery);
    loaded.value = true;
}

// 曜日・時間帯のチェック（選んだ値の並びに出し入れする）
function toggle(list, value, on) {
    const index = list.indexOf(value);
    if (on && index === -1) {
        list.push(value);
    } else if (!on && index !== -1) {
        list.splice(index, 1);
    }
}

async function load() {
    loadError.value = null;
    loaded.value = false;

    try {
        receive(await api('GET', base.value));
    } catch (e) {
        loadError.value = e;
    }
}

watch(() => props.tenant, load, { immediate: true });

async function save() {
    busy.value = true;
    errors.value = {};
    error.value = null;
    message.value = null;

    try {
        receive(await api('PUT', base.value, body(draft.value)));
        message.value = 'お届け日時の設定を保存しました。';
    } catch (e) {
        errors.value = errorsOf(e);
        error.value = Object.keys(errors.value).length > 0 ? '入力を確認してください。誤りのある項目の下に理由を出しています。' : describe(e);
    } finally {
        busy.value = false;
    }
}

// 休みの日の誤りは、並びのどれか（closed_dates.3 など）にも付く
function closedDatesError() {
    const key = Object.keys(errors.value).find((k) => k === 'closed_dates' || k.startsWith('closed_dates.'));

    return key ? firstError(errors.value, key) : null;
}
</script>

<template>
    <SettingsLayout :tenant="tenant">
        <PageHeader :help="helpLink('settings-delivery-dates', tenant)" :title="titleOf('settings-delivery-dates')" :crumbs="crumbs" />
        <AppNotice v-if="message" tone="success">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>

        <ErrorState v-if="loadError" :error="loadError" />
        <LoadingText v-else-if="!loaded" />
        <AppPanel
            v-else
            as="form"
            title="お届け日時"
            description="お客さんが注文の入力で、お届け日と時間帯を選べるようにします（「指定なし」も選べます）。選べるのは、注文の日（日本時間）に「発送までの日数」と「お届けまでの日数」を足した日からです。休業日は発送までの日数に数えません。"
            data-test="delivery-dates-form"
            @submit.prevent="save"
        >
            <AppField as="div" layout="row" label="お届け日の指定" :error="firstError(errors, 'dates')"
                :hint="preview ? `今注文すると、${preview.first}から${preview.last}までを選べます（保存した設定で計算しています）。` : '「受けない」のあいだは、お客さんの画面にお届け日の欄は表示されません。'">
                <ChoicePills v-model="datesChoice" legend="お届け日の指定" :options="[{ value: 'off', label: '受けない' }, { value: 'on', label: '受ける' }]" :disabled="!canEdit" test-prefix="delivery-dates" />
            </AppField>
            <AppField as="div" layout="row" label="時間帯" optional hint="ここで選んだ時間帯の中から、お客さんが選べます。1 つも選ばなければ時間帯の欄は表示されません（どの運送会社でも選べる区分だけを用意しています）。" :error="firstError(errors, 'slots') ?? firstError(errors, 'slots.0')">
                <div class="flex flex-wrap gap-x-6">
                    <AppCheckbox
                        v-for="option in slotOptions"
                        :key="option.value"
                        :model-value="draft.slots.includes(option.value)"
                        :label="option.label"
                        :disabled="!canEdit"
                        :test="`delivery-slot-${option.value}`"
                        @update:model-value="(on) => toggle(draft.slots, option.value, on)"
                    />
                </div>
            </AppField>
            <AppField layout="row" label="発送までの日数" hint="受付の日から何日目に発送するか（休業日は数えません）。0 なら受付の日に発送します。" :error="firstError(errors, 'ship_days')">
                <AppInput v-model="draft.ship_days" type="number" min="0" max="30" step="1" inputmode="numeric" required :disabled="!canEdit" data-test="delivery-ship-days" />
            </AppField>
            <AppField layout="row" label="お届けまでの日数" hint="発送からお届けまでのいちばん短い日数（全国一律）。遠い地域に届く日数を見込んで決めてください。" :error="firstError(errors, 'transit_days')">
                <AppInput v-model="draft.transit_days" type="number" min="0" max="14" step="1" inputmode="numeric" required :disabled="!canEdit" data-test="delivery-transit-days" />
            </AppField>
            <AppField layout="row" label="選べる日数" hint="いちばん早い日から何日分を選べるようにするか（60 日まで）。" :error="firstError(errors, 'span_days')">
                <AppInput v-model="draft.span_days" type="number" min="1" max="60" step="1" inputmode="numeric" required :disabled="!canEdit" data-test="delivery-span-days" />
            </AppField>
            <AppField layout="row" label="締め切りの時刻" optional hint="この時刻を過ぎた注文は、翌日の受付として数えます（日本時間）。空欄なら締め切りを設けません。" :error="firstError(errors, 'cutoff')">
                <AppInput v-model="draft.cutoff" type="time" step="60" :disabled="!canEdit" data-test="delivery-cutoff" />
            </AppField>
            <AppField as="div" layout="row" label="定休日（曜日）" optional hint="発送しない曜日です（6 つまで）。" :error="firstError(errors, 'closed_weekdays') ?? firstError(errors, 'closed_weekdays.0')">
                <div class="flex flex-wrap gap-x-6">
                    <AppCheckbox
                        v-for="(name, index) in WEEKDAYS"
                        :key="index"
                        :model-value="draft.closed_weekdays.includes(index)"
                        :label="`${name}曜日`"
                        :disabled="!canEdit"
                        :test="`delivery-closed-${index}`"
                        @update:model-value="(on) => toggle(draft.closed_weekdays, index, on)"
                    />
                </div>
            </AppField>
            <!-- 札の入力は名前を自分で出すので、項目の枠（AppField）で包まない -->
            <TagInput
                v-model="draft.closed_dates"
                label="臨時の休業日（任意）"
                hint="年末年始や臨時の休みを、2026-12-29 の形で 1 日ずつ入力します（120 日まで）。"
                placeholder="2026-12-29"
                :error="closedDatesError()"
                :maxlength="10"
                :max="120"
                :disabled="!canEdit"
                test="delivery-closed-dates"
            />
            <AppText v-if="!canEdit" size="sm" tone="soft" data-test="settings-read-only">設定を変更できるのは「運用」以上の権限のある人だけです。</AppText>
            <FormActions v-else sticky>
                <template v-if="dirty" #meta><span data-test="settings-unsaved">保存していない変更があります</span></template>
                <AppButton type="submit" variant="primary" :disabled="busy" :loading="busy" data-test="save-delivery-dates">お届け日時の設定を保存する</AppButton>
            </FormActions>
        </AppPanel>
    </SettingsLayout>
</template>
