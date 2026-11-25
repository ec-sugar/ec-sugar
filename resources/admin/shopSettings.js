import { computed, reactive, ref, watch } from 'vue';
import { api, describe } from './api.js';
import { ConfirmationCancelled, withConfirmation } from './confirmation.js';
import { errorsOf } from './format.js';
import { session } from './session.js';
import { canInTenant } from './tenants.js';

// 店の設定（注文の案内・特商法の表記・ショップの公開・同意の帯。送料の表は設定 › 配送が自分で読む。ADR 0064）の写し。設定は 1 項目 1 画面なので、画面どうしでこの写しを分け合い、
// 別の画面へ移っても書きかけを残す（前の 1 枚の画面で「ほかの欄を保存しても書きかけは消えない」としていた約束を、画面をまたいでも守る）。
// 読むのは GET …/shop-settings、保存は項目ごとの PUT（API は まま）。サーバーの値が正本で、ここは表示と書きかけのため
// 写しは読んだ運営のもの。ログインし直した・別の運営に変わったら捨てる（書きかけを次の人に見せない）
const store = reactive({ operator: null, tenant: null, server: null, drafts: null });

const SECTIONS = ['terms', 'legal', 'privacy', 'free', 'publish', 'consent'];

function fromServer(data) {
    return {
        terms: { delivery: data.order_terms.delivery ?? '', returns: data.order_terms.returns ?? '' },
        legal: data.legal.map((row) => ({ label: row.label, value: row.value })),
        // プライバシーポリシーの本文（ADR 0094。無ければ空の文字）
        privacy: { body: data.privacy_policy ?? '' },
        free: { sell: data.sell_free_items === true },
        // ショップの公開。パスワードの値は持たない（置いたかどうかだけ）
        publish: { status: data.publish.status, has_password: data.publish.has_password === true },
        // 解析・広告のタグの同意の帯を出すか（既定は出さない）
        consent: { show: data.consent_banner === true },
    };
}

// 数の欄は入力の途中で文字にも数にもなるので、文字にそろえて比べる
function same(a, b) {
    const text = (value) => JSON.stringify(value, (key, v) => (typeof v === 'number' ? String(v) : v));

    return text(a) === text(b);
}

function isDirty(section) {
    return store.server !== null && store.drafts !== null && !same(store.drafts[section], fromServer(store.server)[section]);
}

// サーバーの新しい値を受ける。書きかけ（saved 以外で、前のサーバーの値から変えた項目）は残す
function receive(tenant, data, saved = null) {
    const next = fromServer(data);
    const operator = session.operator?.id ?? null;

    if (store.operator !== operator || store.tenant !== tenant || store.drafts === null) {
        Object.assign(store, { operator, tenant, server: data, drafts: next });

        return;
    }

    const drafts = Object.fromEntries(SECTIONS.map((section) => [section, section !== saved && isDirty(section) ? store.drafts[section] : next[section]]));
    Object.assign(store, { server: data, drafts });
}

/**
 * 設定の 1 項目の画面。section は terms・legal・privacy・free・publish・consent、request(draft) は保存の PUT（パスと送る中身）、done は済んだときの通知。
 * 変えるのは運用の段階から（canEdit が偽なら欄を押せなくし、保存のボタンを出さない。サーバーも 403 で断る）
 */
export function useShopSettingsSection(tenant, section, request, done) {
    const loadError = ref(null);
    const busy = ref(false);
    const errors = ref({});
    const error = ref(null);
    const message = ref(null);

    const base = computed(() => `/api/tenants/${encodeURIComponent(tenant.value)}/shop-settings`);
    const loaded = computed(() => store.operator === (session.operator?.id ?? null) && store.tenant === tenant.value && store.drafts !== null);
    const draft = computed(() => (loaded.value ? store.drafts[section] : null));
    const dirty = computed(() => loaded.value && isDirty(section));
    const canEdit = computed(() => canInTenant(tenant.value, 'operate'));

    async function load() {
        loadError.value = null;

        try {
            const id = tenant.value;
            receive(id, await api('GET', base.value));
        } catch (e) {
            loadError.value = e;
        }
    }

    watch(tenant, load, { immediate: true });

    async function save() {
        busy.value = true;
        errors.value = {};
        error.value = null;
        message.value = null;

        try {
            const id = tenant.value;
            const { path, body } = request(store.drafts[section]);
            // 再確認（423）を求められたら、パスキーで確認してから送り直す（ショップを公開にするとき。App の設定と同じ流れ）
            receive(id, await withConfirmation(() => api('PUT', `${base.value}/${path}`, body)), section);
            message.value = done;
        } catch (e) {
            errors.value = errorsOf(e);
            error.value = e instanceof ConfirmationCancelled
                ? e.userMessage
                : Object.keys(errors.value).length > 0 ? '入力を確認してください。誤りのある項目の下に理由を出しています。' : describe(e);
        } finally {
            busy.value = false;
        }
    }

    return { loaded, loadError, draft, dirty, canEdit, busy, errors, error, message, save };
}

// 写しを捨てる（ログアウトのとき）
export function forgetShopSettings() {
    Object.assign(store, { operator: null, tenant: null, server: null, drafts: null });
}
