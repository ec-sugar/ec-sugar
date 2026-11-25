import { formatAmount } from './format.js';

// クーポン（ADR 0054）と自動の割引（ADR 0065）の表示の形。一覧（CouponsPage）と 1 枚の編集（CouponPage）が使う。
// 値はサーバーの答え（CouponController の present）の形で受け取る

// 今ショップで使えるか（サーバーの state。会員だけ・最低の購入額などカートで決まる条件は含まない）
export const COUPON_STATE_LABELS = { active: '有効', scheduled: '開始前', ended: '終了', used_up: '上限に達した', paused: '停止中' };
export const COUPON_STATE_TONES = { active: 'ok', scheduled: 'accent', ended: 'neutral', used_up: 'warn', paused: 'neutral' };

// 作り方（クーポンはコードを入れて使う。自動の割引はコード無しで、条件に合うカートで効く）
export const COUPON_METHODS = [
    { value: 'code', label: 'クーポン（コード）' },
    { value: 'automatic', label: '自動の割引' },
];
export const COUPON_METHOD_LABELS = { code: 'クーポン', automatic: '自動' };
export const COUPON_METHOD_TONES = { code: 'neutral', automatic: 'accent' };

// 一覧の題（クーポンはコード、自動の割引はお客さんに見せる名前）
export function couponTitle(coupon) {
    return coupon.method === 'automatic' ? coupon.label : coupon.code;
}

export const COUPON_KINDS = [
    { value: 'fixed', label: '金額で割引' },
    { value: 'percent', label: '割合（%）で割引' },
    { value: 'free_shipping', label: '送料無料' },
];

export const COUPON_TARGETS = [
    { value: 'all', label: 'すべての商品' },
    { value: 'categories', label: 'カテゴリを指定' },
    { value: 'listings', label: '商品を指定' },
];

// 値引き（「¥500 引き」「15% 引き（¥3,000 まで）」「送料無料」）
export function discountLabel(coupon) {
    if (coupon.kind === 'free_shipping') {
        return '送料無料';
    }

    if (coupon.kind === 'percent') {
        return `${coupon.value}% 引き${coupon.max_discount ? `（${formatAmount(coupon.max_discount)} まで）` : ''}`;
    }

    return `${formatAmount(coupon.value)} 引き`;
}

// 条件の要約（対象・最低の購入額・対象の数・会員だけ・1 人の回数）。一覧の 1 欄に「・」でつなぐ
export function conditionsSummary(coupon) {
    const count = coupon.target_ids?.length ?? 0;
    const parts = [
        coupon.target === 'categories' ? `カテゴリ ${count} 個` : coupon.target === 'listings' ? `商品 ${count} 点` : '全品',
    ];

    if (coupon.min_subtotal) {
        parts.push(`${formatAmount(coupon.min_subtotal)} 以上`);
    }

    if (coupon.min_quantity) {
        parts.push(`${coupon.min_quantity} 点以上`);
    }

    if (coupon.members_only) {
        parts.push('会員限定');
    }

    parts.push(coupon.per_customer_limit ? `1 人 ${coupon.per_customer_limit} 回` : '1 人何回でも可');

    return parts.join('・');
}

// 使われた数と上限（「3 / 100」。上限が無ければ「3」）
export function usageLabel(coupon) {
    return coupon.usage_limit ? `${coupon.used} / ${coupon.usage_limit}` : String(coupon.used);
}

function day(value) {
    return new Date(value).toLocaleString('ja-JP', { dateStyle: 'medium', timeStyle: 'short' });
}

// 期間（「11/1 0:00 から 11/30 23:59 まで」の形。無ければ「いつでも」）
export function periodLabel(coupon) {
    if (!coupon.starts_at && !coupon.ends_at) {
        return 'いつでも';
    }

    return [coupon.starts_at ? `${day(coupon.starts_at)} から` : null, coupon.ends_at ? `${day(coupon.ends_at)} まで` : null].filter(Boolean).join(' ');
}

// コードを作る（推測されにくい 8 文字。見分けにくい 0・O・1・I・L は使わない。ブラウザの暗号の乱数で）
const CODE_LETTERS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

export function generateCode(length = 8) {
    const bytes = new Uint8Array(length);
    let code = '';

    // 偏りを出さないように、文字の数で割り切れない大きな値は引き直す
    const limit = 256 - (256 % CODE_LETTERS.length);

    while (code.length < length) {
        crypto.getRandomValues(bytes);

        for (const byte of bytes) {
            if (byte < limit && code.length < length) {
                code += CODE_LETTERS[byte % CODE_LETTERS.length];
            }
        }
    }

    return code;
}

// 日時の欄（datetime-local。手元の時刻）とサーバーの ISO 8601 の行き来
export function toLocalInput(value) {
    if (!value) {
        return '';
    }

    const date = new Date(value);
    const pad = (n) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function fromLocalInput(value) {
    return value ? new Date(value).toISOString() : null;
}
