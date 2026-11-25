<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, describe } from '../api.js';
import { flatten } from '../categories.js';
import {
    COUPON_KINDS, COUPON_METHODS, COUPON_STATE_LABELS, COUPON_STATE_TONES, COUPON_TARGETS,
    discountLabel, fromLocalInput, generateCode, toLocalInput, usageLabel,
} from '../coupons.js';
import { errorsOf, firstError } from '../format.js';
import { crumbsFor } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { canInTenant, tenantName } from '../tenants.js';
import AppButton from '../components/ui/AppButton.vue';
import AppCheckbox from '../components/ui/AppCheckbox.vue';
import AppField from '../components/ui/AppField.vue';
import AppIcon from '../components/ui/AppIcon.vue';
import AppInput from '../components/ui/AppInput.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppSelect from '../components/ui/AppSelect.vue';
import AppText from '../components/ui/AppText.vue';
import CheckList from '../components/ui/CheckList.vue';
import ChoicePills from '../components/ui/ChoicePills.vue';
import CodeText from '../components/ui/CodeText.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import FormActions from '../components/ui/FormActions.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import SearchField from '../components/ui/SearchField.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';
import TextLink from '../components/ui/TextLink.vue';

// クーポン・自動の割引を作る（coupon が無いとき）・直すを、1 枚のフォームで（ADR 0054 の区切り 2・ADR 0065。商品の登録と同じ 1 枚の形）。
// 上から 基本（作り方・コードか名前・メモ）→ 値引き（種類と値）→ 対象（全品・カテゴリ・商品）→ 条件（最低の購入額・対象の数・期間・回数・会員だけ）。直すときは上に今の状態と止める・再開。
// 作り方（クーポンか自動の割引）は作るときだけ選ぶ（一覧の「自動の割引を作る」は ?method=automatic で開く）。自動の割引はコードを持たず、お客さんに見せる名前を持つ。
// コードは作った後に変えない。一度でも注文に使われたクーポンは、種類・値・上限の額・対象を変えない（サーバーも断る。CouponService::FIXED_ONCE_USED）。
// 守るのはサーバー（値の幅・対象の ID がこの店にあるか・段階）。ここの出し分けは見た目のため
const props = defineProps({
    tenant: { type: String, required: true },
    coupon: { type: String, default: null },
});
const router = useRouter();
const route = useRoute();
const isNew = computed(() => props.coupon === null);
const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}`);
const canEdit = computed(() => canInTenant(props.tenant, 'operate'));

const current = ref(null);
const loadError = ref(null);
const error = ref(null);
const message = ref(null);
const fieldErrors = ref({});
const saving = ref(false);
const switching = ref(false);
const categories = ref([]);
// 商品を選ぶ欄の候補（名前の順に 100 点まで。言葉で絞れる）と、選んだ商品の名前（候補に無くても出せるように）
const listingOptions = ref([]);
const listingQuery = ref('');
const listingNames = reactive({});

const form = reactive(blank());

function blank() {
    // 1 人の回数の既定: クーポンは 1 回、自動の割引は何回でも（ADR 0065）
    const method = route.query.method === 'automatic' ? 'automatic' : 'code';

    return {
        method, code: '', label: '', name: '', kind: 'fixed', value: null, max_discount: null, target: 'all', target_ids: [],
        min_subtotal: null, min_quantity: null, starts_at: '', ends_at: '', usage_limit: null, per_customer_limit: method === 'automatic' ? null : 1, members_only: false,
    };
}

function fill(coupon) {
    current.value = coupon;
    Object.assign(form, {
        method: coupon.method,
        code: coupon.code ?? '',
        label: coupon.label ?? '',
        name: coupon.name,
        kind: coupon.kind,
        value: coupon.kind === 'free_shipping' ? null : coupon.value,
        max_discount: coupon.max_discount,
        target: coupon.target,
        target_ids: [...coupon.target_ids],
        min_subtotal: coupon.min_subtotal,
        min_quantity: coupon.min_quantity,
        starts_at: toLocalInput(coupon.starts_at),
        ends_at: toLocalInput(coupon.ends_at),
        usage_limit: coupon.usage_limit,
        per_customer_limit: coupon.per_customer_limit,
        members_only: coupon.members_only,
    });

    for (const target of coupon.targets ?? []) {
        listingNames[target.id] = target.name;
    }
}

const locked = computed(() => current.value?.locked === true);
const automatic = computed(() => form.method === 'automatic');
const title = computed(() => {
    if (isNew.value) {
        return automatic.value ? '新しい自動の割引' : '新しいクーポン';
    }

    return (current.value?.method === 'automatic' ? current.value?.label : current.value?.code) ?? 'クーポン';
});
const crumbs = computed(() => crumbsFor(isNew.value ? 'coupon-new' : 'coupon', props.tenant, tenantName(props.tenant), title.value));
const categoryOptions = computed(() => categories.value.map((c) => ({ value: c.id, label: c.name, depth: c.depth })));
const pickable = computed(() => listingOptions.value.filter((l) => !form.target_ids.includes(l.id)));
const targetName = (id) => listingNames[id] ?? '削除した商品';
// 種類・値・対象は、使われた後は変えられない（段階の足りない人にも押せなくする）
const fixedDisabled = computed(() => !canEdit.value || locked.value);

async function loadListings() {
    const params = new URLSearchParams({ per_page: '100', sort: 'title' });

    if (listingQuery.value.trim() !== '') {
        params.set('q', listingQuery.value.trim());
    }

    const { listings } = await api('GET', `${base.value}/listings?${params}`);
    listingOptions.value = listings;

    for (const listing of listings) {
        listingNames[listing.id] ??= listing.title;
    }
}

async function searchListings() {
    try {
        await loadListings();
    } catch (e) {
        error.value = describe(e);
    }
}

async function load() {
    loadError.value = null;
    error.value = null;
    message.value = null;
    fieldErrors.value = {};

    // 新しく作る時は、欄を出す前に空にする（読み込みの後に空にすると、読み込みの間に入れた文字が消える）
    if (isNew.value) {
        current.value = null;
        Object.assign(form, blank());
    }

    try {
        categories.value = flatten((await api('GET', `${base.value}/categories`)).categories);
        await loadListings();

        if (!isNew.value) {
            fill((await api('GET', `${base.value}/coupons/${encodeURIComponent(props.coupon)}`)).coupon);
        }
    } catch (e) {
        loadError.value = e;
    }
}

watch(() => [props.tenant, props.coupon, route.query.method], load, { immediate: true });

// 作るときに作り方を替えたら、1 人の回数の既定も合わせる（クーポンは 1 回、自動の割引は何回でも）
function setMethod(method) {
    if (method === form.method) {
        return;
    }

    if (form.per_customer_limit === (form.method === 'automatic' ? null : 1)) {
        form.per_customer_limit = method === 'automatic' ? null : 1;
    }

    form.method = method;
}

function addListing(id) {
    if (id && !form.target_ids.includes(id)) {
        form.target_ids = [...form.target_ids, id];
    }
}

function removeListing(id) {
    form.target_ids = form.target_ids.filter((x) => x !== id);
}

// 対象の種類を替えたら、前の種類で選んだ ID は外す（カテゴリの ID を商品として送らない）
function setTarget(target) {
    if (target !== form.target) {
        form.target = target;
        form.target_ids = [];
    }
}

const blankToNull = (value) => (value === '' || value === null || value === undefined ? null : value);

function payload() {
    return {
        ...(isNew.value ? { method: form.method } : {}),
        ...(isNew.value && !automatic.value ? { code: form.code } : {}),
        ...(automatic.value ? { label: form.label } : {}),
        name: form.name,
        kind: form.kind,
        value: form.kind === 'free_shipping' ? null : blankToNull(form.value),
        max_discount: form.kind === 'percent' ? blankToNull(form.max_discount) : null,
        target: form.target,
        target_ids: form.target === 'all' ? [] : form.target_ids,
        min_subtotal: blankToNull(form.min_subtotal),
        min_quantity: blankToNull(form.min_quantity),
        starts_at: fromLocalInput(form.starts_at),
        ends_at: fromLocalInput(form.ends_at),
        usage_limit: blankToNull(form.usage_limit),
        per_customer_limit: blankToNull(form.per_customer_limit),
        members_only: form.members_only,
    };
}

async function save() {
    saving.value = true;
    error.value = null;
    message.value = null;
    fieldErrors.value = {};

    try {
        if (isNew.value) {
            const { coupon } = await api('POST', `${base.value}/coupons`, payload());
            fill(coupon);
            await router.replace({ name: 'coupon', params: { tenant: props.tenant, coupon: coupon.id } });
            message.value = automatic.value ? '自動の割引を作りました。条件に合うカートに、コード無しで適用されます。' : 'クーポンを作りました。ショップでコードを入力すると使えます。';
        } else {
            fill((await api('PUT', `${base.value}/coupons/${encodeURIComponent(props.coupon)}`, payload())).coupon);
            message.value = '保存しました。';
        }
    } catch (e) {
        fieldErrors.value = errorsOf(e);
        // 自動の割引の数の上限（20 件）は欄の誤りでないので、上の通知に出す
        error.value = fieldError('method') ?? (Object.keys(fieldErrors.value).length > 0 ? '入力を確認してください。誤りのある項目の下に理由を出しています。' : describe(e));
    } finally {
        saving.value = false;
    }
}

async function setStatus(action) {
    switching.value = true;
    error.value = null;
    message.value = null;

    try {
        const { coupon } = await api('POST', `${base.value}/coupons/${encodeURIComponent(props.coupon)}/${action}`);
        current.value = { ...current.value, ...coupon };
        message.value = action === 'pause'
            ? (current.value.method === 'automatic' ? '停止しました。ショップのカートで適用されなくなりました。' : '停止しました。ショップでこのコードを入力しても使えません。')
            : '再開しました。';
    } catch (e) {
        error.value = describe(e);
    } finally {
        switching.value = false;
    }
}

const fieldError = (field) => firstError(fieldErrors.value, field);
const targetError = computed(() => fieldError('target_ids') ?? Object.entries(fieldErrors.value).find(([key]) => key.startsWith('target_ids.'))?.[1]?.[0] ?? null);
</script>

<template>
    <PageHeader :help="helpLink(isNew ? 'coupon-new' : 'coupon', tenant)" :title="title" :crumbs="crumbs">
        <template v-if="!isNew && current" #actions>
            <StatusBadge :tone="COUPON_STATE_TONES[current.state]" data-test="coupon-state">{{ COUPON_STATE_LABELS[current.state] ?? current.state }}</StatusBadge>
        </template>
    </PageHeader>
    <AppNotice v-if="message" tone="success" data-test="coupon-message">{{ message }}</AppNotice>
    <AppNotice v-if="error" tone="error" data-test="coupon-error">{{ error }}</AppNotice>

    <ErrorState v-if="loadError" :error="loadError" />
    <LoadingText v-else-if="!isNew && current === null" />
    <div v-else class="flex flex-col gap-6" data-test="coupon-form">
        <AppPanel v-if="!isNew && current" title="使われた数と状態" description="支払い前の注文と支払い済みの注文を数えます。支払われずに取り消された注文は数えません。">
            <template #actions>
                <template v-if="canEdit">
                    <ConfirmButton
                        v-if="current.status === 'active'"
                        label="停止"
                        confirm-label="停止する"
                        variant="secondary"
                        :loading="switching"
                        test="pause-coupon"
                        @confirm="setStatus('pause')"
                    >{{ current.method === 'automatic' ? '停止すると、ショップのカートで適用されなくなります' : '停止すると、ショップでこのコードを入力しても使えなくなります' }}（使われた注文はそのままです）。いつでも再開できます。</ConfirmButton>
                    <AppButton v-else size="sm" :loading="switching" data-test="resume-coupon" @click="setStatus('resume')">再開する</AppButton>
                </template>
            </template>
            <div class="flex flex-wrap items-center gap-6">
                <AppText data-test="coupon-usage">使われた数: {{ usageLabel(current) }}</AppText>
                <AppText tone="soft" size="sm">値引き: {{ discountLabel(current) }}</AppText>
            </div>
            <AppNotice v-if="locked" tone="info" :live="false" data-test="coupon-locked">
                すでに注文に使われたため、値引きの種類・額・対象は変更できません（使われた注文の記録と合わなくなるため）。変えたいときは、このクーポンを停止して新しいクーポンを作ってください。期間・条件・回数・メモは変更できます。
            </AppNotice>
        </AppPanel>

        <AppPanel title="基本" :description="automatic ? 'コードは不要です。条件に合うカートに自動で適用されます（複数が当てはまるときは、いちばんお得な 1 つだけ）。メモはお客さんには見えません。' : 'お客さんはカートでコードを入力して使います。メモはお客さんには見えません。'">
            <div class="flex flex-col gap-6">
                <AppField v-if="isNew" as="div" label="作り方" layout="row" hint="作った後は変更できません" :error="fieldError('method')">
                    <ChoicePills :model-value="form.method" :options="COUPON_METHODS" legend="作り方" test-prefix="coupon-method" :disabled="!canEdit" @update:model-value="setMethod" />
                </AppField>
                <AppField
                    v-if="automatic"
                    label="お客さんに表示する名前"
                    layout="row"
                    hint="カート・注文・メール・領収書の値引きの行と、商品の画面に表示されます（40 文字まで）。例: 秋のセール 全品 10%・まとめ買い 3 点以上で 10%"
                    :error="fieldError('label')"
                >
                    <AppInput v-model="form.label" maxlength="40" :disabled="!canEdit" data-test="coupon-label" />
                </AppField>
                <AppField
                    v-if="isNew && !automatic"
                    as="div"
                    label="コード"
                    layout="row"
                    hint="英数字とハイフンで 3〜32 文字。大文字と小文字は区別しません。作った後は変更できません"
                    :error="fieldError('code')"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <AppInput v-model="form.code" maxlength="32" autocomplete="off" aria-label="コード" class="max-w-xs" :aria-invalid="!!fieldError('code')" data-test="coupon-code" />
                        <AppButton size="sm" :disabled="!canEdit" data-test="generate-code" @click="form.code = generateCode()"><AppIcon name="refresh" />自動で作る</AppButton>
                    </div>
                </AppField>
                <AppField v-else-if="!automatic" as="div" label="コード" layout="row" hint="作った後は変更できません">
                    <CodeText data-test="coupon-code-fixed">{{ current?.code }}</CodeText>
                </AppField>
                <AppField label="メモ" layout="row" hint="店の中で見分けるための名前で、お客さんには見えません（100 文字まで）。例: 秋のセール・はじめてのお客さん" :error="fieldError('name')">
                    <AppInput v-model="form.name" maxlength="100" :disabled="!canEdit" data-test="coupon-name" />
                </AppField>
            </div>
        </AppPanel>

        <AppPanel title="値引き">
            <div class="flex flex-col gap-6">
                <AppField as="div" label="種類" layout="row" :error="fieldError('kind')">
                    <ChoicePills v-model="form.kind" :options="COUPON_KINDS" legend="値引きの種類" test-prefix="coupon-kind" :disabled="fixedDisabled" />
                </AppField>
                <AppField v-if="form.kind === 'fixed'" label="値引きする金額（円）" layout="row" hint="対象の商品の合計より大きいときは、合計の額まで値引きします" :error="fieldError('value')">
                    <AppInput v-model="form.value" type="number" min="1" step="1" inputmode="numeric" class="max-w-48" :disabled="fixedDisabled" data-test="coupon-value" />
                </AppField>
                <template v-else-if="form.kind === 'percent'">
                    <AppField label="値引きする割合（%）" layout="row" hint="1〜100。1 円未満は切り捨てます" :error="fieldError('value')">
                        <AppInput v-model="form.value" type="number" min="1" max="100" step="1" inputmode="numeric" class="max-w-48" :disabled="fixedDisabled" data-test="coupon-value" />
                    </AppField>
                    <AppField label="値引きの上限額（円）" layout="row" optional hint="入力すると、割合で計算した値引きがこの額を超えないようにします" :error="fieldError('max_discount')">
                        <AppInput v-model="form.max_discount" type="number" min="1" step="1" inputmode="numeric" class="max-w-48" :disabled="fixedDisabled" data-test="coupon-max-discount" />
                    </AppField>
                </template>
                <AppText v-else size="sm" tone="soft" data-test="coupon-free-shipping">このクーポンを使った注文は、送料が 0 円になります。</AppText>
            </div>
        </AppPanel>

        <AppPanel title="対象" description="値引きの対象にする商品です。カテゴリで選ぶときは、商品が直接入っているカテゴリで判断します（下の階層のカテゴリの商品も対象にするなら、そのカテゴリも選んでください）。">
            <div class="flex flex-col gap-6">
                <AppField as="div" label="対象の商品" layout="row" :error="fieldError('target')">
                    <ChoicePills :model-value="form.target" :options="COUPON_TARGETS" legend="対象の商品" test-prefix="coupon-target" :disabled="fixedDisabled" @update:model-value="setTarget" />
                </AppField>
                <AppField v-if="form.target === 'categories'" as="div" label="カテゴリ" layout="row" hint="いくつでも選べます（100 個まで）" :error="targetError">
                    <AppText v-if="categoryOptions.length === 0" size="sm" tone="soft" data-test="coupon-no-categories">
                        カテゴリがまだありません。<TextLink :to="{ name: 'categories', params: { tenant } }">カテゴリの画面</TextLink>で作れます。
                    </AppText>
                    <CheckList v-else v-model="form.target_ids" :options="categoryOptions" legend="対象のカテゴリ" test-prefix="coupon-category" variant="chips" :disabled="fixedDisabled" />
                </AppField>
                <AppField v-if="form.target === 'listings'" as="div" label="商品" layout="row" hint="100 点まで。下書きや終了の商品も選べます（買えるのはショップに公開中の商品だけです）" :error="targetError">
                    <div class="flex flex-col gap-3">
                        <ol v-if="form.target_ids.length > 0" class="flex flex-col gap-1">
                            <li v-for="(id, index) in form.target_ids" :key="id" class="flex flex-wrap items-center gap-2" data-test="coupon-picked-listing">
                                <AppText size="sm" class="grow">{{ index + 1 }}. {{ targetName(id) }}</AppText>
                                <AppButton size="sm" variant="ghost" :disabled="fixedDisabled" :aria-label="`${targetName(id)}を削除`" @click="removeListing(id)">削除</AppButton>
                            </li>
                        </ol>
                        <AppText v-else size="sm" tone="soft">まだ選んでいません。</AppText>
                        <template v-if="!fixedDisabled">
                            <SearchField v-model="listingQuery" label="商品名・品番で探す" data-test="coupon-listing-search" @submit="searchListings" />
                            <AppSelect :model-value="''" aria-label="商品を追加" data-test="coupon-add-listing" @update:model-value="addListing">
                                <option value="">商品を追加（{{ pickable.length }} 点から選ぶ）</option>
                                <option v-for="l in pickable" :key="l.id" :value="l.id">{{ l.title }}</option>
                            </AppSelect>
                        </template>
                    </div>
                </AppField>
            </div>
        </AppPanel>

        <AppPanel title="条件" description="空欄の条件は付けません。">
            <div class="flex flex-col gap-6">
                <AppField label="最低購入金額（円）" layout="row" optional hint="値引き前の商品の合計がこの額以上のときに使えます" :error="fieldError('min_subtotal')">
                    <AppInput v-model="form.min_subtotal" type="number" min="1" step="1" inputmode="numeric" class="max-w-48" :disabled="!canEdit" data-test="coupon-min-subtotal" />
                </AppField>
                <AppField label="対象の商品の数（点）" layout="row" optional hint="対象の商品が合わせてこの数以上のときに使えます（まとめ買い）。段階を付けたいときは、数と値引きの違う自動の割引を複数作ります（2 点で 5%・3 点以上で 10% など。いちばんお得なものが適用されます）" :error="fieldError('min_quantity')">
                    <AppInput v-model="form.min_quantity" type="number" min="1" max="1000" step="1" inputmode="numeric" class="max-w-48" :disabled="!canEdit" data-test="coupon-min-quantity" />
                </AppField>
                <AppField label="開始日時" layout="row" optional hint="空欄なら、作ったときから使えます" :error="fieldError('starts_at')">
                    <AppInput v-model="form.starts_at" type="datetime-local" class="max-w-64" :disabled="!canEdit" data-test="coupon-starts-at" />
                </AppField>
                <AppField label="終了日時" layout="row" optional hint="この日時になると使えなくなります。空欄なら、停止するまで使えます" :error="fieldError('ends_at')">
                    <AppInput v-model="form.ends_at" type="datetime-local" class="max-w-64" :disabled="!canEdit" data-test="coupon-ends-at" />
                </AppField>
                <AppField label="全体で使える回数" layout="row" optional hint="先着の数です。空欄なら回数の上限を付けません" :error="fieldError('usage_limit')">
                    <AppInput v-model="form.usage_limit" type="number" min="1" step="1" inputmode="numeric" class="max-w-48" :disabled="!canEdit" data-test="coupon-usage-limit" />
                </AppField>
                <AppField label="1 人が使える回数" layout="row" optional hint="注文のメールアドレスで数えます。空欄なら何回でも使えます" :error="fieldError('per_customer_limit')">
                    <AppInput v-model="form.per_customer_limit" type="number" min="1" max="1000" step="1" inputmode="numeric" class="max-w-48" :disabled="!canEdit" data-test="coupon-per-customer" />
                </AppField>
                <AppField as="div" label="会員限定" layout="row" hint="ショップにログインしたお客さんだけが使えます" :error="fieldError('members_only')">
                    <AppCheckbox v-model="form.members_only" label="会員だけが使える" :disabled="!canEdit" test="coupon-members-only" />
                </AppField>
            </div>
        </AppPanel>

        <FormActions sticky>
            <AppButton variant="ghost" :to="{ name: 'coupons', params: { tenant } }">一覧に戻る</AppButton>
            <AppText v-if="!canEdit" size="sm" tone="soft" data-test="coupon-read-only">クーポン・自動の割引を作成・編集できるのは、「運用」以上の権限のある人だけです。</AppText>
            <AppButton v-else variant="primary" :loading="saving" :disabled="saving" data-test="save-coupon" @click="save">{{ isNew ? (automatic ? '自動の割引を作る' : 'クーポンを作る') : '保存する' }}</AppButton>
        </FormActions>
    </div>
</template>
