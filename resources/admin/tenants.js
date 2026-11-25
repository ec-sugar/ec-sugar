import { reactive } from 'vue';
import { api } from './api.js';
import { setSingleShop } from './navigation.js';

// メニューに出すテナントの名前（表示のためだけ）。一覧を 1 回だけ読む。名前が分からなければ ID を出す
// total: 見られる店の数（null は読む前）。1 なら店の一覧と店の選択を出さない（既定は 1 システム・1 店舗。CONCEPT 4.1）
export const tenantDirectory = reactive({ names: {}, storefronts: {}, levels: {}, loaded: false, total: null });

export async function loadTenantNames() {
    if (tenantDirectory.loaded) {
        return;
    }

    tenantDirectory.loaded = true;

    try {
        const result = await api('GET', '/api/tenants?per_page=100');
        tenantDirectory.total = result.meta?.total ?? result.tenants.length;

        for (const tenant of result.tenants) {
            tenantDirectory.names[tenant.id] = tenant.name;
            tenantDirectory.storefronts[tenant.id] = tenant.storefront_url;
            tenantDirectory.levels[tenant.id] = tenant.level;
        }

        setSingleShop(onlyTenant() !== null);
    } catch {
        tenantDirectory.loaded = false;
    }
}

// ログアウトのとき（次にログインした運営に、前の人の店の一覧を見せない）
export function forgetTenants() {
    tenantDirectory.names = {};
    tenantDirectory.storefronts = {};
    tenantDirectory.levels = {};
    tenantDirectory.total = null;
    tenantDirectory.loaded = false;
    setSingleShop(false);
}

// 見られる店がちょうど 1 つなら、その店の ID（店の一覧と店の選択を隠し、その店の管理画面として見せる）。表示のためだけ
export function onlyTenant() {
    const ids = Object.keys(tenantDirectory.names);

    return tenantDirectory.total === 1 && ids.length === 1 ? ids[0] : null;
}

export function rememberTenant(id, name, storefront = undefined, level = undefined) {
    tenantDirectory.names[id] = name;

    if (level !== undefined) {
        tenantDirectory.levels[id] = level;
    }

    if (storefront !== undefined) {
        tenantDirectory.storefronts[id] = storefront;
    }
}

export function tenantName(id) {
    return tenantDirectory.names[id] ?? id;
}

// 店頭の URL（サーバーが組み立てたもの。管理画面から店頭を開くリンク）
export function storefrontUrl(id) {
    return tenantDirectory.storefronts[id] ?? null;
}

// この店での自分の段階（read・operate・money。ADR 0024）。画面のボタンの出し分けのためだけで、守るのはサーバー
const LEVELS = { read: 1, operate: 2, money: 3 };

export function canInTenant(id, level) {
    const mine = tenantDirectory.levels[id];

    return mine !== undefined && mine !== null && LEVELS[mine] >= LEVELS[level];
}
