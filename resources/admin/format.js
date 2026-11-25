// 表示の整形。金額は最小通貨単位の整数で受け取る（円なら 1 円単位）

export function formatAmount(amount, currency = 'JPY') {
    if (amount === null || amount === undefined) {
        return '—';
    }

    // ショップ・メールと同じ形（円は半角の ¥ と 3 桁ごとのカンマ。サーバーの App\Core\Money\Money と合わせる）
    const sign = amount < 0 ? '-' : '';
    const digits = Math.abs(Number(amount)).toLocaleString('ja-JP');

    return currency === 'JPY' ? `${sign}¥${digits}` : `${sign}${digits} ${currency}`;
}

export function formatDate(value) {
    return value ? new Date(value).toLocaleString('ja-JP', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

// 日付だけ（「2026-11-01」の形で受け取る。App の画面の date。ADR 0051）。時刻を足さずに読む（UTC の 0 時として読むと時差で日がずれる）
export function formatDay(value) {
    const parts = typeof value === 'string' ? /^(\d{4})-(\d{2})-(\d{2})$/.exec(value) : null;

    if (parts === null) {
        return '—';
    }

    return new Date(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3])).toLocaleDateString('ja-JP', { dateStyle: 'medium' });
}

// バイト数を読みやすく（1,024 ごとに KB・MB・GB・TB。運営ダッシュボードの空き容量）
export function formatBytes(bytes) {
    if (bytes === null || bytes === undefined) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let value = Number(bytes);
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(unit === 0 || value >= 100 ? 0 : 1)} ${units[unit]}`;
}

export const STATUS_LABELS = { draft: '下書き', active: '公開中', archived: '終了' };

// 状態のバッジの色の丸（意味は文字で出す）
export const STATUS_TONES = { draft: 'neutral', active: 'ok', archived: 'warn' };

export function errorsOf(error) {
    return error?.status === 422 ? (error.body?.errors ?? {}) : {};
}

export function firstError(errors, field) {
    return errors[field]?.[0] ?? null;
}

// 取引の表示名（値はサーバーの列挙型の値）
export const TRANSACTION_STATUS_LABELS = { open: '進行中', completed: '完了', cancelled: '取消', refunded: '返金済み' };
export const TRANSACTION_STATUS_TONES = { open: 'accent', completed: 'ok', cancelled: 'neutral', refunded: 'warn' };
// 物販の対応の状況（日本の EC の受注でよく使う言葉に寄せる）
export const ORDER_STATUS_LABELS = { awaiting_payment: '入金待ち', paid: '入金済み', confirmed: '受付済み', cod_pending: '代引き・発送待ち', on_site_pending: '当日払い・来店待ち', shipped: '発送済み', completed: '完了', cancelled: '注文取消し', refunded: '返金済み' };
export const ORDER_STATUS_TONES = { awaiting_payment: 'warn', paid: 'accent', confirmed: 'accent', cod_pending: 'accent', on_site_pending: 'accent', shipped: 'accent', completed: 'ok', cancelled: 'neutral', refunded: 'warn' };
// 返品・一部返金の札（取引の状態は変えずに、返した額で見せる。ADR 0048）
export const REFUND_STATE_LABELS = { partial: '一部返金', full: '全額返金' };
export const REFUND_STATE_TONES = { partial: 'warn', full: 'neutral' };
export const PHASE_LABELS = {
    quote: '見積もり', agreement: '合意', payment: '支払い', fulfillment: '配送', acceptance: '検収', settlement: '精算',
    cancel: '取消', dispute: '紛争', refund: '返金',
};
export const ROLE_LABELS = { customer: '顧客', seller: '販売者' };
export const JOURNAL_LABELS = {
    charge: '請求', tax: '消費税', payment: '支払いの受領', fee: '手数料', withholding: '源泉徴収', payout: '送金', refund: '返金', adjustment: '調整',
};
export const ACCOUNT_LABELS = {
    customer: '顧客', seller: '販売者', platform: 'プラットフォーム（手数料）', provider: '決済代行（預かり）', tax: '消費税', withholding: '源泉所得税',
};
export const QUOTE_STATUS_LABELS = { proposed: '提案中', accepted: '採用', rejected: '不採用', withdrawn: '取り下げ' };
export const FULFILLMENT_METHOD_LABELS = { shipping: '配送', pickup: '店頭受取', digital: 'デジタル', in_person: 'サービスの提供', inbound: 'お客さんからの送付・持ち込み', none: 'なし' };
export const FULFILLMENT_STATUS_LABELS = { pending: '準備中', shipped: '発送済み', completed: '完了', cancelled: '取消' };

export function shortId(id) {
    return id ? id.slice(0, 10).toUpperCase() : '—';
}

// お客さんの記録の番号。ULID の頭の 10 文字は作った時刻なので、今までの注文から移行で一度に作った記録は頭がそろう。
// 後ろの 8 文字（でたらめな部分）で見分ける
export function customerNumber(id) {
    return id ? id.slice(-8).toUpperCase() : '—';
}

export function formatRate(bp) {
    return `${(bp / 100).toFixed(bp % 100 === 0 ? 0 : 1)}%`;
}
