import { reactive } from 'vue';
import { api } from './api.js';
import { formatAmount, formatDate, formatDay } from './format.js';

// App の画面（ADR 0051）の画面の側の補助。
// - 店ごとの「App の画面」の並び（GET /api/tenants/{店}/app-pages）を店ごとに 1 回だけ読み、メニューとパンくず・タブの題に使う。
//   表示のためだけ（見られるか・押せるかはサーバーが決める）。App を使う・使わないを切り替えたら読み直す（refresh）
// - App が渡した値（核の決めた型だけ。app/Core/Plugin/AdminPage/CellType.php）を、画面に出す文字・状態の札・核の画面へのリンクにする。
//   HTML は描かない（文字として差し込むだけ）。リンクの行き先は核の経路の名前から作り、URL を API から受け取らない

// 店の ID → [{ plugin, title }]（まだ読んでいない店は無い）
export const appPageDirectory = reactive({ pages: {} });
// 店ごとに、最後に読み始めた問い合わせの番号（古い答えが後から届いても使わない）
const latest = {};
const inFlight = {};

const PLUGIN_NAME = /^[a-z][a-z0-9-]{1,31}$/;

function validPages(pages) {
    return Array.isArray(pages)
        ? pages.filter((page) => typeof page?.plugin === 'string' && PLUGIN_NAME.test(page.plugin) && typeof page.title === 'string' && page.title !== '')
            .map((page) => ({ plugin: page.plugin, title: page.title }))
        : [];
}

/**
 * 店の App の画面の並びを読む（読んだ店は読み直さない。refresh で読み直す）。読めなければ何も足さない（メニューは App の画面なしのまま）
 */
export function loadAppPages(tenant, { refresh = false } = {}) {
    if (typeof tenant !== 'string' || tenant === '') {
        return Promise.resolve();
    }

    if (!refresh && (appPageDirectory.pages[tenant] !== undefined || inFlight[tenant])) {
        return inFlight[tenant] ?? Promise.resolve();
    }

    const ticket = (latest[tenant] ?? 0) + 1;
    latest[tenant] = ticket;
    const request = api('GET', `/api/tenants/${encodeURIComponent(tenant)}/app-pages`)
        .then((result) => {
            if (latest[tenant] === ticket) {
                appPageDirectory.pages[tenant] = validPages(result?.pages);
            }
        })
        .catch(() => {
            // 読めない（権限が無い・通信の誤り）ときは項目を足さないだけ。画面はそれぞれの API が断る
        })
        .finally(() => {
            if (inFlight[tenant] === request) {
                delete inFlight[tenant];
            }
        });
    inFlight[tenant] = request;

    return request;
}

export function appPagesOf(tenant) {
    return (typeof tenant === 'string' ? appPageDirectory.pages[tenant] : null) ?? [];
}

export function appPageTitle(tenant, plugin) {
    return appPagesOf(tenant).find((page) => page.plugin === plugin)?.title ?? null;
}

// ログアウトのとき（次にログインした運営に、前の人の店の App を見せない）
export function forgetAppPages() {
    appPageDirectory.pages = {};
}

// --- 値の描き方（CellType ごと） ---

// 状態の色の種類 → 状態の札（StatusBadge）の色。知らない種類は控えめ
const TONES = { good: 'ok', warn: 'warn', bad: 'danger', muted: 'neutral' };

// リンクの種類 → 核の画面の経路の名前と引数の名前（router.js）。ここに無い種類は文字だけ
const LINK_ROUTES = {
    customer: { name: 'customer', param: 'customer' },
    transaction: { name: 'transaction', param: 'transaction' },
    listing: { name: 'listing', param: 'listing' },
};

const ULID = /^[0-9a-z]{26}$/;

export function statusTone(tone) {
    return typeof tone === 'string' && Object.hasOwn(TONES, tone) ? TONES[tone] : 'neutral';
}

/**
 * リンクの値（{ label, kind, id }）から、核の画面への経路を作る。種類が分からない・ID の形でないときは null（文字だけ出す）。
 * ID は ULID（大文字と小文字を区別しない。店の記録は小文字で持つので小文字にそろえる）
 */
export function linkRoute(value, tenant) {
    if (value === null || typeof value !== 'object' || typeof value.kind !== 'string' || typeof value.id !== 'string') {
        return null;
    }

    const target = Object.hasOwn(LINK_ROUTES, value.kind) ? LINK_ROUTES[value.kind] : null;
    const id = value.id.toLowerCase();

    if (target === null || !ULID.test(id) || typeof tenant !== 'string') {
        return null;
    }

    return { name: target.name, params: { tenant, [target.param]: id } };
}

/**
 * 値を画面に出す文字にする。値が無ければ「—」。状態とリンクは文字の部分（label）
 */
export function cellText(type, value) {
    if (value === null || value === undefined) {
        return '—';
    }

    switch (type) {
        case 'money':
            return typeof value === 'number' ? formatAmount(value) : '—';
        case 'number':
            return typeof value === 'number' ? String(value) : '—';
        case 'date':
            return formatDay(value);
        case 'datetime':
            return formatDate(value);
        case 'status':
        case 'link':
            return typeof value?.label === 'string' ? value.label : '—';
        default:
            return typeof value === 'string' ? value : '—';
    }
}

// 数の列（右揃え）
export function isNumeric(type) {
    return type === 'number' || type === 'money';
}
