// 金額の見せ方（ショップの核の JavaScript。サーバーの App\Core\Money\Money と同じ形）
// お客さんに見せる金額は、どこでも「¥5,400」（半角の「¥」と 3 桁ごとのカンマ）。「5,400 円」とは書かない。
// 円でない通貨は「1,234 USD」の形（取り違えて ¥ を付けない）。金額は最小通貨単位の整数で受け取る（ここでは計算しない）

export function formatMoney(amount, currency) {
    const digits = Math.abs(amount).toLocaleString('en-US');
    const sign = amount < 0 ? '-' : '';

    return String(currency).toUpperCase() === 'JPY' ? `${sign}¥${digits}` : `${sign}${digits} ${String(currency).toUpperCase()}`;
}

// 「2 点で ¥5,400」（サーバーの ProductView::quantityTotalLabel と同じ形）
export function formatQuantityTotal(total, quantity, currency) {
    return `${quantity} 点で ${formatMoney(total, currency)}`;
}
