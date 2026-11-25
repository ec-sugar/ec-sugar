<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, ApiError, describe } from '../api.js';
import { errorsOf, firstError, STATUS_LABELS, STATUS_TONES } from '../format.js';
import { crumbsFor } from '../navigation.js';
import { helpLink } from '../help/index.js';
import { canInTenant, tenantName } from '../tenants.js';
import { fromServer, plainOf, specsFromServer, specsToServer, toServer } from '../description.js';
import { labelsOf, runSteps } from '../listingSave.js';
import AppButton from '../components/ui/AppButton.vue';
import AppCheckbox from '../components/ui/AppCheckbox.vue';
import AppField from '../components/ui/AppField.vue';
import AppIcon from '../components/ui/AppIcon.vue';
import AppInput from '../components/ui/AppInput.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppReveal from '../components/ui/AppReveal.vue';
import AppSelect from '../components/ui/AppSelect.vue';
import AppText from '../components/ui/AppText.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import FormActions from '../components/ui/FormActions.vue';
import FormOutline from '../components/ui/FormOutline.vue';
import InsetSection from '../components/ui/InsetSection.vue';
import LoadingText from '../components/ui/LoadingText.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';
import ListingAddons from './listing/ListingAddons.vue';
import ListingApps from './listing/ListingApps.vue';
import ListingCategories from './listing/ListingCategories.vue';
import ListingPhotos from './listing/ListingPhotos.vue';
import ListingStock from './listing/ListingStock.vue';
import ListingVariants from './listing/ListingVariants.vue';
import DescriptionEditor from '../components/listing/DescriptionEditor.vue';
import SpecEditor from '../components/listing/SpecEditor.vue';
import SeoFields from '../components/SeoFields.vue';

// 商品の登録（listing が無いとき）と編集を、1 枚の長いフォームで（上から順に埋めていく形）。
// 上から 基本 → 写真 → 説明 → 種類（色・サイズ）→ オプション → 在庫 → App（使っている App が商品ごとの欄を持つ店だけ。ADR 0050）→ 詳しい設定（畳む）。広い画面では横に節の目次、下に浮かせた保存の帯。
// 保存は下の 1 つのボタンで、変えた所の API を決まった順に呼ぶ（listingSave.js。途中で失敗したら止めて、保存できたもの・できなかったもの・まだのものを通知する）。
// 編集は読んだバージョン（version）を送り、先に保存されていたら 409。写真と在庫の入庫・調整は、押すとすぐ反映する（ファイルと在庫の記録の操作）
const props = defineProps({
    tenant: { type: String, required: true },
    listing: { type: String, default: null },
});
const router = useRouter();
const isNew = computed(() => props.listing === null);

const options = ref(null);
const current = ref(null);
const loadError = ref(null);
// 説明はブロックの並び（blocks）・仕様の表（specs）・一覧に出す短い説明（summary）。ADR 0032
// 検索の結果に出す題と説明（seo_title・seo_description。空なら自動。ADR 0061）
// shipping_method_id は配送の方法（空ならいちばん上の方法）、free_shipping は送料無料の商品（ADR 0064）
const form = reactive({ seller_id: '', kind: 'product', title: '', blocks: [], specs: [], summary: '', unit_amount: null, tax_class: 'standard', sku: '', status: 'draft', seo_title: '', seo_description: '', shipping_method_id: '', free_shipping: false });
// ショップの写真（説明の写真のブロックで選ぶ）
const media = ref([]);
// 読んだときの説明の 3 つ（変えたものだけを送るため。項目ごとの JSON）
const loadedDescription = ref({});
const fieldErrors = ref({});
const error = ref(null);
const message = ref(null);
const conflict = ref(false);
const saving = ref(false);
// 途中で止まった保存の通知（{ done, failed: { label, reason, section }, pending, publishing }）
const outcome = ref(null);
// 読んだ（保存した）ときの基本の情報。保存していない変更があるかを見る
const loadedForm = ref({});
const previewing = ref(false);
// ブラウザが新しいタブを止めたときだけ、リンクを出して押してもらう
const previewUrl = ref(null);
const categoryTree = ref(null);
// 詳しい設定（品番・カテゴリなど）は畳んでおく
const detailsOpen = ref(false);
// 節の部品を作り直す番号（読み込み直すと、書きかけを捨てて読んだ値から始める）
const generation = ref(0);

const photosRef = ref(null);
const variantsRef = ref(null);
const addonsRef = ref(null);
const stockRef = ref(null);
const appsRef = ref(null);
const categoriesRef = ref(null);
const outlineRef = ref(null);

const base = computed(() => `/api/tenants/${encodeURIComponent(props.tenant)}`);
const pricing = computed(() => options.value?.kinds.find((k) => k.kind === (current.value?.kind ?? form.kind))?.pricing ?? 'fixed');
const mediaBase = computed(() => `${base.value}/shop-media`);
const listingBase = computed(() => `${base.value}/listings/${encodeURIComponent(props.listing ?? '')}`);
const title = computed(() => (isNew.value ? '新しい商品' : (current.value?.title ?? '商品')));
// 変えるのは運用の段階から（閲覧だけの人にはボタンを出さない。見た目だけで、サーバーも 403 で断る）
const canEdit = computed(() => canInTenant(props.tenant, 'operate'));
// 色・サイズなどの選ぶ項目がある商品（ADR 0036）。品番と在庫は種類ごとに「種類」の節で扱う
const hasOptions = computed(() => (current.value?.options?.length ?? 0) > 0);
// 新しい商品で、選ぶ項目を書いている（品番と最初の在庫は、種類ができてから種類ごとに）
const draftAxes = computed(() => isNew.value && (variantsRef.value?.draftAxes ?? false));
const crumbs = computed(() => crumbsFor(isNew.value ? 'listing-new' : 'listing', props.tenant, tenantName(props.tenant), title.value));
const sellers = computed(() => options.value?.sellers ?? []);
const sellerLocations = computed(() => sellers.value.find((s) => s.id === form.seller_id)?.locations ?? []);
const kindLabel = computed(() => options.value?.kinds.find((k) => k.kind === 'product')?.label ?? '商品');

function fill(listing) {
    current.value = listing;
    Object.assign(form, {
        seller_id: listing.seller_id,
        kind: listing.kind,
        title: listing.title,
        blocks: fromServer(listing.description_blocks),
        specs: specsFromServer(listing.specs),
        summary: listing.summary ?? '',
        unit_amount: listing.unit_amount,
        tax_class: listing.tax_class ?? 'standard',
        sku: listing.sku ?? '',
        status: listing.status,
        seo_title: listing.seo_title ?? '',
        seo_description: listing.seo_description ?? '',
        shipping_method_id: listing.shipping_method_id ?? '',
        free_shipping: listing.free_shipping === true,
    });
    loadedDescription.value = descriptionJson();
    loadedForm.value = formSnapshot();
}

// 新しい商品の空の形（出品者は選択肢の最初。読み込みの途中で押しても必須の欄が空で止まらない）
function blank() {
    current.value = null;
    Object.assign(form, { seller_id: options.value?.sellers[0]?.id ?? '', kind: 'product', title: '', blocks: [], specs: [], summary: '', unit_amount: null, tax_class: 'standard', sku: '', status: 'draft', seo_title: '', seo_description: '', shipping_method_id: '', free_shipping: false });
    loadedDescription.value = descriptionJson();
    loadedForm.value = formSnapshot();
}

// 保存の手順の後の商品（基本の情報の書きかけは残す）。選ぶ項目の有り無しが変わったときだけ品番を読み直す
function setListing(listing) {
    if ((listing.options?.length ?? 0) !== (current.value?.options?.length ?? 0)) {
        form.sku = listing.sku ?? '';
        loadedForm.value = { ...loadedForm.value, sku: form.sku };
    }

    current.value = listing;
}

function setImages(images) {
    current.value = { ...current.value, images };
}

// 在庫の操作は在庫だけを受け取る（商品のほかの値とバージョンを動かさない。保存していない編集の衝突の見張りを保つ）
function setStocks(listing) {
    const variants = Object.fromEntries(listing.variants.map((v) => [v.id, v]));
    current.value = {
        ...current.value,
        stocks: listing.stocks,
        variants: current.value.variants.map((v) => (variants[v.id] ? { ...v, stocks: variants[v.id].stocks } : v)),
    };
}

// 説明の写真のブロックから新しく上げた写真も、ショップの写真の一覧に入れる
function addMedia(item) {
    media.value = [item, ...media.value];
}

// 自分で経路を移す（作った後に編集の URL へ）ときは、読み直さず、離れる前の確認も出さない
let skipLoad = false;
let leaving = false;

async function load() {
    if (skipLoad && current.value?.id === props.listing) {
        skipLoad = false;

        return;
    }

    skipLoad = false;
    error.value = null;
    message.value = null;
    outcome.value = null;
    loadError.value = null;
    conflict.value = false;
    fieldErrors.value = {};
    previewUrl.value = null;
    generation.value++;

    try {
        options.value ??= await api('GET', `${base.value}/listing-options`);

        if (isNew.value) {
            blank();
        }

        // 選ぶのに要るのは写真だけ。使っている所は数えない（全部の商品の説明を開かない）
        media.value = (await api('GET', `${mediaBase.value}?usage=none`)).items;
        categoryTree.value = (await api('GET', `${base.value}/categories`)).categories;

        if (!isNew.value) {
            fill((await api('GET', listingBase.value)).listing);
        }
    } catch (e) {
        if (options.value === null || (!isNew.value && current.value?.id !== props.listing)) {
            loadError.value = e;
        } else {
            error.value = describe(e);
        }
    }
}

watch(() => [props.tenant, props.listing], load, { immediate: true });

// 説明の 3 つ（ブロック・仕様の表・短い説明）を、送る形で
function descriptionPayload() {
    return { description_blocks: toServer(form.blocks), specs: specsToServer(form.specs), summary: form.summary };
}

function descriptionJson() {
    return Object.fromEntries(Object.entries(descriptionPayload()).map(([key, value]) => [key, JSON.stringify(value)]));
}

// 基本の情報の今の値（保存していない変更があるかを見るため。数は文字にそろえる）
function formSnapshot() {
    const amount = form.unit_amount === null || form.unit_amount === '' ? '' : String(form.unit_amount);

    return { title: form.title, unit_amount: amount, tax_class: form.tax_class, sku: form.sku, status: form.status, shipping_method_id: form.shipping_method_id, free_shipping: String(form.free_shipping), seo_title: form.seo_title, seo_description: form.seo_description, ...descriptionJson() };
}

// 配送の方法と送料無料を読んだときから変えたか（詳しい設定の「変更あり」の印）
const shippingDirty = computed(() => (loadedForm.value.shipping_method_id ?? '') !== form.shipping_method_id || (loadedForm.value.free_shipping ?? 'false') !== String(form.free_shipping));

const basicDirty = computed(() => {
    if (!options.value || (!isNew.value && !current.value)) {
        return false;
    }

    const now = formSnapshot();

    return Object.keys(now).some((key) => now[key] !== loadedForm.value[key]);
});

const descriptionDirty = computed(() => {
    const now = descriptionJson();

    return basicDirty.value && Object.keys(now).some((key) => now[key] !== loadedForm.value[key]);
});

// 検索と共有の欄だけを変えたか（ADR 0061）
const seoDirty = computed(() => (loadedForm.value.seo_title ?? '') !== form.seo_title || (loadedForm.value.seo_description ?? '') !== form.seo_description);

// どこかに保存していない変更があるか（写真と在庫の操作は押すとすぐ反映するので、新しい商品の覚えた写真と最初の在庫だけ）
const dirty = computed(() => basicDirty.value
    || !!variantsRef.value?.dirty
    || !!addonsRef.value?.dirty
    || !!categoriesRef.value?.dirty
    || !!photosRef.value?.dirty
    || !!stockRef.value?.dirty
    || !!appsRef.value?.dirty);

// 書き直し始めたら、前の「保存しました」は消す
watch(dirty, (now) => {
    if (now) {
        message.value = null;
    }
});

// 節の目次（変えた節に印）
const outline = computed(() => [
    { id: 'listing-basic', label: '基本', changed: basicDirty.value && !descriptionDirty.value && !seoDirty.value },
    { id: 'listing-photos', label: '写真', changed: !!photosRef.value?.dirty },
    { id: 'listing-description', label: '説明', changed: descriptionDirty.value },
    { id: 'listing-variants', label: '種類（色・サイズ）', changed: !!variantsRef.value?.dirty },
    { id: 'listing-addons', label: 'オプション', changed: !!addonsRef.value?.dirty },
    { id: 'listing-stock', label: '在庫', changed: !!stockRef.value?.dirty },
    ...(appsRef.value?.visible ? [{ id: 'listing-apps', label: 'App', changed: !!appsRef.value?.dirty }] : []),
    { id: 'listing-seo', label: '検索と共有', changed: seoDirty.value },
    { id: 'listing-details', label: '詳しい設定', changed: !!categoriesRef.value?.dirty || (loadedForm.value.sku ?? '') !== form.sku || shippingDirty.value },
]);

// 畳んだ「詳しい設定」へ移るときは開く
function openSection(id) {
    if (id === 'listing-details') {
        detailsOpen.value = true;
    }
}

function goTo(id) {
    openSection(id);
    nextTick(() => outlineRef.value?.go(id));
}

// ショップで見られないときの一言（ショップに出す条件はサーバーが決め、出ていれば shop_url が来る）
const notInShop = computed(() => {
    if (!current.value || current.value.shop_url) {
        return null;
    }

    // 買取の商品・見積もりで決まる商品は、ショップに商品の画面が無い（プレビューも出さない）
    if (!current.value.previewable) {
        return 'この種類の商品は、ショップの商品の画面には表示されません。';
    }

    return current.value.status === 'active'
        ? '今はショップに表示されていません（販売者が停止中・0 円の商品など）。プレビューで見た目を確認できます。'
        : '公開中の商品だけがショップに表示されます。プレビューで見た目を確認できます。';
});

// プレビュー: 管理画面のログインはショップに届かないので、サーバーが署名つきの期限つきのリンク（30 分）を作る。
// 押した直後に空のタブを開いてから移す（待った後に開くと、ブラウザがポップアップとして止める）。開いたタブからこの画面を触らせない（opener を切る）
async function openPreview() {
    previewing.value = true;
    previewUrl.value = null;
    error.value = null;
    const tab = window.open('', '_blank');

    if (tab) {
        tab.opener = null;
    }

    try {
        const { url } = await api('POST', `${listingBase.value}/preview`);

        // 移る先は http(s) の URL だけ（サーバーが組み立てたもの）
        if (!['http:', 'https:'].includes(new URL(url).protocol)) {
            throw new Error('invalid preview url');
        }

        if (tab) {
            tab.location.replace(url);
        } else {
            previewUrl.value = url;
        }
    } catch (e) {
        tab?.close();
        error.value = describe(e);
    } finally {
        previewing.value = false;
    }
}

// 今ある商品で公開に変える（下書き・終了 → 公開中）ときは、ほかの保存がすべて済んでから最後に公開する（途中で失敗したら公開しない）
const publishing = computed(() => !isNew.value && current.value !== null && form.status === 'active' && current.value.status !== 'active');

// 基本の情報と説明のうち、読んだときから変えた項目だけ（題だけの保存で、保存済みの説明を書き換えない。
// 保存済みの値に今は断る文字があって外して見せているときも、説明に触れなければそのまま残る。ADR 0032）
function basicChanges() {
    const now = formSnapshot();
    const changes = {};

    if (now.title !== loadedForm.value.title) {
        changes.title = form.title;
    }

    if (now.unit_amount !== loadedForm.value.unit_amount) {
        changes.unit_amount = form.unit_amount === '' ? null : form.unit_amount;
    }

    if (now.tax_class !== loadedForm.value.tax_class) {
        changes.tax_class = form.tax_class;
    }

    // 選ぶ項目のある商品の品番は種類ごと（商品の欄からは送らない）
    if (!hasOptions.value && now.sku !== loadedForm.value.sku) {
        changes.sku = form.sku;
    }

    if (now.status !== loadedForm.value.status && !publishing.value) {
        changes.status = form.status;
    }

    for (const key of ['seo_title', 'seo_description']) {
        if (now[key] !== loadedForm.value[key]) {
            changes[key] = form[key];
        }
    }

    // 配送の方法と送料無料（ADR 0064）
    if (now.shipping_method_id !== loadedForm.value.shipping_method_id) {
        changes.shipping_method_id = form.shipping_method_id || null;
    }

    if (now.free_shipping !== loadedForm.value.free_shipping) {
        changes.free_shipping = form.free_shipping;
    }

    const description = descriptionPayload();

    for (const [key, value] of Object.entries(description)) {
        if (JSON.stringify(value) !== loadedDescription.value[key]) {
            changes[key] = value;
        }
    }

    return changes;
}

// 基本の情報の誤り（422）は欄の下に。投げ直して、保存を止める
async function basicRequest(method, path, body) {
    fieldErrors.value = {};

    try {
        return (await api(method, path, body)).listing;
    } catch (e) {
        fieldErrors.value = errorsOf(e);
        throw e;
    }
}

function createStep() {
    return {
        label: '基本の情報と説明',
        section: 'listing-basic',
        basic: true,
        run: () => {
            const body = { ...descriptionPayload(), seller_id: form.seller_id, kind: form.kind, title: form.title, unit_amount: form.unit_amount === '' ? null : form.unit_amount, tax_class: form.tax_class, status: 'draft', seo_title: form.seo_title, seo_description: form.seo_description,
                shipping_method_id: form.shipping_method_id || null, free_shipping: form.free_shipping };

            // 色・サイズを分ける商品の品番は種類ごと
            if (!draftAxes.value) {
                body.sku = form.sku;
            }

            return basicRequest('POST', `${base.value}/listings`, body);
        },
    };
}

function updateStep(changes) {
    return {
        label: '基本の情報と説明',
        section: 'listing-basic',
        basic: true,
        run: (listing) => basicRequest('PATCH', `${base.value}/listings/${encodeURIComponent(listing.id)}`, { ...changes, version: listing.version }),
    };
}

function publishStep() {
    return {
        label: '公開',
        section: 'listing-basic',
        basic: true,
        run: (listing) => basicRequest('PATCH', `${base.value}/listings/${encodeURIComponent(listing.id)}`, { status: 'active', version: listing.version }),
    };
}

// 1 つの手順が済んだら、画面の商品をそれにそろえる（基本の情報の手順は、読んだ値も置き換える）
function applySaved(listing, step) {
    if (step?.basic) {
        fill(listing);
    } else {
        setListing(listing);
    }
}

// 失敗の理由の一言（入力の誤りは欄の下にも出す）
function reasonOf(e) {
    if (e?.message === 'photos') {
        return `${e.subject}を追加できませんでした（理由は写真の欄に表示しています）。`;
    }

    const first = Object.values(errorsOf(e)).flat()[0];
    const subject = e?.subject ? `${e.subject}: ` : '';

    if (first) {
        return `${subject}${first}`;
    }

    return `${subject}${describe(e)}`;
}

async function save(publish = false) {
    if (saving.value) {
        return;
    }

    message.value = null;
    error.value = null;
    outcome.value = null;
    fieldErrors.value = {};

    const steps = [];

    if (isNew.value) {
        steps.push(createStep());

        if (photosRef.value?.dirty) {
            steps.push(photosRef.value.step());
        }

        if (stockRef.value?.dirty) {
            steps.push(stockRef.value.step());
        }
    } else {
        const changes = basicChanges();

        if (Object.keys(changes).length > 0) {
            steps.push(updateStep(changes));
        }
    }

    steps.push(...(variantsRef.value?.steps() ?? []));

    if (addonsRef.value?.dirty) {
        steps.push(addonsRef.value.step());
    }

    if (categoriesRef.value?.dirty) {
        steps.push(categoriesRef.value.step());
    }

    steps.push(...(appsRef.value?.steps() ?? []));

    const publishNow = isNew.value ? publish : publishing.value;

    if (publishNow) {
        steps.push(publishStep());
    }

    if (steps.length === 0) {
        message.value = '変更はありません。';

        return;
    }

    saving.value = true;
    const wasNew = isNew.value;

    try {
        const result = await runSteps(steps, current.value, applySaved);

        // 新しい商品は、作れたら編集の URL へ（画面はそのまま。書きかけの残りも残る）
        if (wasNew && result.listing) {
            skipLoad = true;
            leaving = true;

            try {
                await router.replace({ name: 'listing', params: { tenant: props.tenant, listing: result.listing.id } });
            } finally {
                leaving = false;
            }
        }

        if (result.failed) {
            const { step, error: e } = result.failed;

            if (e instanceof ApiError && e.status === 409) {
                conflict.value = true;
            }

            if (step.section === 'listing-details' || ['sku', 'kind', 'seller_id', 'shipping_method_id', 'free_shipping'].some((field) => fieldErrors.value[field])) {
                detailsOpen.value = true;
            }

            outcome.value = {
                done: labelsOf(result.done),
                failed: { label: step.label, reason: e instanceof ApiError && e.status === 409 ? (e.body?.message ?? describe(e)) : reasonOf(e), section: fieldErrors.value.sku ? 'listing-details' : step.section },
                pending: labelsOf(result.pending),
                publishing: publishNow && !result.done.some((s) => s.label === '公開'),
            };
        } else if (wasNew) {
            message.value = publishNow ? '公開しました。' : '下書きで保存しました。';
        } else {
            message.value = publishNow ? '保存して公開しました。' : '保存しました。';
        }
    } finally {
        saving.value = false;
    }
}

// 保存していない変更があるまま離れるときは確認する（画面の中の移動と、タブを閉じる・読み直す）
let removeGuard = null;

function warnUnsaved(event) {
    if (dirty.value && canEdit.value) {
        event.preventDefault();
    }
}

onMounted(() => {
    removeGuard = router.beforeEach((to, from) => {
        const same = to.name === from.name && to.params.tenant === from.params.tenant && to.params.listing === from.params.listing;

        if (leaving || same || !dirty.value || !canEdit.value) {
            return true;
        }

        return window.confirm('保存していない変更があります。保存せずにこの画面を離れますか？');
    });
    window.addEventListener('beforeunload', warnUnsaved);
});

onBeforeUnmount(() => {
    removeGuard?.();
    window.removeEventListener('beforeunload', warnUnsaved);
});

// 0 円の品は、店の設定で売ると決めた店だけショップに出る。入れ間違いに気づけるよう案内する
const amountHint = computed(() => {
    if (form.unit_amount !== null && form.unit_amount !== '' && Number(form.unit_amount) === 0) {
        return '0 円の商品は、設定の「注文の案内」で「0 円の品を売る」をオンにした店でだけショップに表示され、支払いなしで注文できます。';
    }

    if (hasOptions.value || draftAxes.value) {
        return '税込み・1 円単位。種類ごとに価格を入力しないときは、この価格で販売します';
    }

    return pricing.value === 'fixed' ? '税込み・1 円単位の整数で入力します' : '見積もりで価格が決まる商品は、空欄でもかまいません';
});

const removalNote = computed(() => variantsRef.value?.removalNote ?? null);
</script>

<template>
    <PageHeader :help="helpLink(isNew ? 'listing-new' : 'listing', tenant)" :title="title" :crumbs="crumbs">
        <template v-if="!isNew && current" #actions>
            <StatusBadge :tone="STATUS_TONES[current.status]">{{ STATUS_LABELS[current.status] }}</StatusBadge>
            <AppButton v-if="current.shop_url" size="sm" :href="current.shop_url" data-test="open-in-shop"><AppIcon name="external" />ショップで見る</AppButton>
            <AppButton v-if="current.previewable" size="sm" :loading="previewing" data-test="open-preview" @click="openPreview"><AppIcon name="eye" />プレビュー</AppButton>
        </template>
    </PageHeader>
    <div v-if="!isNew && current && (notInShop || dirty || previewUrl)" class="flex flex-wrap items-center justify-end gap-x-4 gap-y-2">
        <AppText v-if="notInShop" size="sm" tone="soft" data-test="not-in-shop">{{ notInShop }}</AppText>
        <AppText v-if="dirty" size="sm" tone="soft" data-test="preview-unsaved">保存していない変更があります。プレビューとショップには、保存した内容が表示されます。</AppText>
        <AppButton v-if="previewUrl" size="sm" :href="previewUrl" data-test="preview-link"><AppIcon name="eye" />プレビューを開く（30 分有効）</AppButton>
    </div>
    <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>

    <ErrorState v-if="loadError" :error="loadError" />
    <LoadingText v-else-if="options === null && !error" />
    <div v-else-if="options && (isNew || current)" class="flex items-start gap-6">
        <div class="flex min-w-0 flex-1 flex-col gap-6" data-test="listing-form">
            <AppPanel id="listing-basic" tabindex="-1" title="基本" description="ここだけ入力すれば保存できます。">
                <div class="flex flex-col gap-6">
                    <AppField v-if="isNew && sellers.length > 1" label="販売者" layout="row" hint="この商品を売る販売者" :error="firstError(fieldErrors, 'seller_id')">
                        <AppSelect v-model="form.seller_id" required data-test="listing-seller">
                            <option v-for="seller in sellers" :key="seller.id" :value="seller.id">{{ seller.name }}</option>
                        </AppSelect>
                    </AppField>
                    <AppField label="商品名" layout="row" hint="ショップの商品の画面と一覧に表示されます（200 文字まで）" :error="firstError(fieldErrors, 'title')">
                        <AppInput v-model="form.title" required maxlength="200" :aria-invalid="!!firstError(fieldErrors, 'title')" data-test="listing-title" />
                    </AppField>
                    <AppField label="価格（円）" layout="row" :hint="amountHint" :error="firstError(fieldErrors, 'unit_amount') ?? firstError(fieldErrors, 'listing')">
                        <AppInput v-model="form.unit_amount" type="number" min="0" step="1" inputmode="numeric" :required="pricing === 'fixed'" :aria-invalid="!!firstError(fieldErrors, 'unit_amount')" data-test="listing-amount" />
                    </AppField>
                    <AppField label="税率" layout="row" hint="食品・飲み物（お酒を除く）は軽減 8%。価格は税込みのまま、注文の明細と領収書の消費税の内訳に使います" :error="firstError(fieldErrors, 'tax_class')">
                        <AppSelect v-model="form.tax_class" :aria-invalid="!!firstError(fieldErrors, 'tax_class')" data-test="listing-tax-class">
                            <option v-for="taxClass in options.tax_classes ?? []" :key="taxClass.value" :value="taxClass.value">{{ taxClass.label }}</option>
                        </AppSelect>
                    </AppField>
                    <AppField v-if="!isNew" label="状態" layout="row" hint="公開中の商品だけがショップに表示されます。「公開中」に変えると、ほかの変更を保存した後に公開します" :error="firstError(fieldErrors, 'status')">
                        <AppSelect v-model="form.status" data-test="listing-status">
                            <option v-for="value in options.statuses" :key="value" :value="value">{{ STATUS_LABELS[value] ?? value }}</option>
                        </AppSelect>
                    </AppField>
                </div>
            </AppPanel>

            <ListingPhotos
                id="listing-photos"
                :key="`photos-${generation}`"
                ref="photosRef"
                tabindex="-1"
                :tenant-base="base"
                :listing="current"
                :disabled="!canEdit"
                @update:images="setImages"
            />

            <AppPanel id="listing-description" tabindex="-1" title="説明" description="お客さんが買う前に知りたいこと（素材・大きさ・使い方・注意）を書きます。ショップでは、テーマのデザインで表示されます。">
                <AppField label="短い説明" layout="row" optional hint="商品の一覧で、商品名の下に表示されます（1 行）" :error="firstError(fieldErrors, 'summary')">
                    <AppInput v-model="form.summary" :maxlength="options.description_limits.summary" :aria-invalid="!!firstError(fieldErrors, 'summary')" data-test="listing-summary" />
                </AppField>
                <InsetSection title="商品の説明" description="段落・見出し・箇条書き・写真・表・注意書きを並べて作ります。">
                    <AppNotice v-if="current?.description_repaired" tone="warning" data-test="description-repaired">
                        保存済みの説明に、今は使えない文字（文字の向きを変える制御文字など）や崩れた部分があったため、取り除いて表示しています。説明を編集して保存すると、この状態で保存されます。説明を変更せずに保存したときは、保存済みの説明はそのままです。
                    </AppNotice>
                    <AppText v-if="firstError(fieldErrors, 'description_blocks')" size="sm" tone="danger" role="alert">{{ firstError(fieldErrors, 'description_blocks') }}</AppText>
                    <DescriptionEditor v-model="form.blocks" :limits="options.description_limits" :media="media" :media-base="mediaBase" :errors="fieldErrors" :disabled="!canEdit" @uploaded="addMedia" />
                </InsetSection>
                <InsetSection title="仕様の表" description="素材・サイズ・原産国など、項目と値の組です。ショップでは説明の下に表で表示されます。">
                    <AppText v-if="firstError(fieldErrors, 'specs')" size="sm" tone="danger" role="alert">{{ firstError(fieldErrors, 'specs') }}</AppText>
                    <SpecEditor v-model="form.specs" :limits="options.description_limits" :errors="fieldErrors" :disabled="!canEdit" />
                </InsetSection>
            </AppPanel>

            <ListingVariants
                id="listing-variants"
                :key="`variants-${generation}`"
                ref="variantsRef"
                tabindex="-1"
                :tenant-base="base"
                :listing="current"
                :disabled="!canEdit"
                @stock="(id) => { stockRef?.choose(id) }"
            />

            <ListingAddons id="listing-addons" :key="`addons-${generation}`" ref="addonsRef" tabindex="-1" :tenant-base="base" :listing="current" :disabled="!canEdit" />

            <ListingStock
                id="listing-stock"
                :key="`stock-${generation}`"
                ref="stockRef"
                tabindex="-1"
                :tenant-base="base"
                :listing="current"
                :locations="sellerLocations"
                :adjustment-reasons="options.adjustment_reasons"
                :per-variant="draftAxes"
                :disabled="!canEdit"
                @update:stocks="setStocks"
            />

            <ListingApps id="listing-apps" :key="`apps-${generation}`" ref="appsRef" tabindex="-1" :tenant-base="base" :listing="current" :disabled="!canEdit" />

            <AppPanel id="listing-seo" tabindex="-1" title="検索と共有" description="Google などの検索結果と、LINE・X で共有した時に表示されるタイトルと説明です。空欄なら商品名と説明から自動で作ります。共有の写真は 1 枚目の写真です。">
                <SeoFields
                    v-model:title="form.seo_title"
                    v-model:description="form.seo_description"
                    :fallback-title="form.title"
                    :fallback-description="form.summary || plainOf(form.blocks)"
                    :shop-name="tenantName(tenant)"
                    :url="current?.shop_url ?? null"
                    auto-note="空欄なら短い説明から、短い説明も無ければ説明の最初の部分から作ります。"
                    :errors="fieldErrors"
                    :disabled="!canEdit"
                    test="listing-seo"
                />
            </AppPanel>

            <AppPanel id="listing-details" tabindex="-1" title="詳しい設定" description="品番・カテゴリなど。入力しなくても販売できます。">
                <template #actions>
                    <AppButton size="sm" variant="ghost" :aria-expanded="detailsOpen ? 'true' : 'false'" aria-controls="listing-details-body" data-test="listing-details-toggle" @click="detailsOpen = !detailsOpen">
                        {{ detailsOpen ? '閉じる' : '開く' }}
                    </AppButton>
                </template>
                <AppReveal id="listing-details-body" :show="detailsOpen" class="flex flex-col gap-6">
                    <AppField
                        v-if="!hasOptions && !draftAxes"
                        label="品番"
                        layout="row"
                        optional
                        hint="お店の中で商品を見分ける記号。英数字と . _ -（販売者の中で重ならないもの）"
                        :error="firstError(fieldErrors, 'sku')"
                    >
                        <AppInput v-model="form.sku" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9._\-]*" autocomplete="off" :aria-invalid="!!firstError(fieldErrors, 'sku')" data-test="listing-sku" />
                    </AppField>
                    <AppText v-else size="sm" tone="soft" data-test="sku-by-variant">色・サイズを分ける商品の品番は、「種類」の表で種類ごとに入力します。</AppText>
                    <AppField v-if="isNew && options.kinds.length > 1" label="商品の扱い" layout="row" :hint="`ふつうの商品は「${kindLabel}」のままで大丈夫です`" :error="firstError(fieldErrors, 'kind')">
                        <AppSelect v-model="form.kind" required data-test="listing-kind">
                            <option v-for="kind in options.kinds" :key="kind.kind" :value="kind.kind">{{ kind.label }}</option>
                        </AppSelect>
                    </AppField>
                    <AppField
                        v-if="(options.shipping_methods ?? []).length > 0"
                        label="配送の方法"
                        layout="row"
                        hint="配送の方法が違う商品をいっしょに買うと、別々の荷物で送り、送料は方法ごとにかかります。方法と送料は 設定 › 配送と送料 で設定します"
                        :error="firstError(fieldErrors, 'shipping_method_id')"
                    >
                        <AppSelect v-model="form.shipping_method_id" :disabled="!canEdit" data-test="listing-shipping-method">
                            <option value="">{{ options.shipping_methods[0].name }}（いちばん上の方法）</option>
                            <option v-for="method in options.shipping_methods.slice(1)" :key="method.id" :value="method.id">{{ method.name }}</option>
                        </AppSelect>
                    </AppField>
                    <AppField as="div" label="送料無料" layout="row" hint="この商品を含む荷物は、同じ荷物で送るほかの商品も送料無料になります" :error="firstError(fieldErrors, 'free_shipping')">
                        <AppCheckbox v-model="form.free_shipping" label="送料無料の商品にする" :disabled="!canEdit" test="listing-free-shipping" />
                    </AppField>
                    <ListingCategories :key="`categories-${generation}`" ref="categoriesRef" :tenant-base="base" :tenant="tenant" :listing="current" :tree="categoryTree" :disabled="!canEdit" />
                </AppReveal>
            </AppPanel>

            <div class="sticky bottom-4 z-10 flex flex-col gap-2">
                <AppNotice v-if="message" tone="success" data-test="save-message">{{ message }}</AppNotice>
                <AppNotice v-if="outcome" :tone="conflict ? 'warning' : 'error'" data-test="save-outcome">
                    <span v-if="conflict">{{ outcome.failed.reason }}</span>
                    <span v-else>保存が途中で止まりました。もう一度「保存する」を押すと、まだ保存していない部分だけを保存します。</span>
                    <ul class="flex flex-col gap-1">
                        <li v-if="outcome.done" data-test="save-done">保存できたもの: {{ outcome.done }}</li>
                        <li v-if="!conflict" data-test="save-failed">
                            保存できなかったもの: {{ outcome.failed.label }}（{{ outcome.failed.reason }}）
                            <AppButton size="sm" variant="ghost" data-test="go-failed" @click="goTo(outcome.failed.section)">その項目へ移動</AppButton>
                        </li>
                        <li v-if="outcome.pending" data-test="save-pending">まだ保存していないもの: {{ outcome.pending }}</li>
                        <li v-if="outcome.publishing" data-test="save-not-published">公開はまだです（下書きのままです）。</li>
                    </ul>
                    <div v-if="conflict">
                        <AppButton size="sm" data-test="reload-listing" @click="load">最新の内容を読み込み直す（入力した変更は消えます）</AppButton>
                    </div>
                </AppNotice>
                <FormActions sticky>
                    <template #meta>
                        <span v-if="!isNew && current">バージョン {{ current.version }}</span>
                        <span v-if="dirty && canEdit" data-test="unsaved">{{ !isNew && current ? '・' : '' }}保存していない変更があります</span>
                    </template>
                    <AppButton variant="ghost" :to="{ name: 'listings', params: { tenant } }">一覧に戻る</AppButton>
                    <AppText v-if="!canEdit" size="sm" tone="soft" data-test="listing-read-only">商品と在庫を変更できるのは「運用」以上の権限のある人だけです。</AppText>
                    <template v-else-if="isNew">
                        <AppButton :loading="saving" :disabled="saving" data-test="save-draft" @click="save(false)">下書きで保存</AppButton>
                        <AppButton variant="primary" :loading="saving" :disabled="saving" data-test="publish-listing" @click="save(true)">公開する</AppButton>
                    </template>
                    <ConfirmButton
                        v-else-if="removalNote"
                        label="保存する"
                        confirm-label="取り除いて保存する"
                        variant="primary"
                        size="md"
                        :loading="saving"
                        :disabled="saving || conflict"
                        test="save-listing"
                        @confirm="save(false)"
                    >{{ removalNote }}</ConfirmButton>
                    <AppButton v-else variant="primary" :loading="saving" :disabled="saving || conflict" data-test="save-listing" @click="save(false)">保存する</AppButton>
                </FormActions>
            </div>
        </div>
        <FormOutline ref="outlineRef" :items="outline" label="この商品の項目" class="sticky top-8 hidden w-48 shrink-0 lg:flex" @go="openSection" />
    </div>
</template>
