import { expect, test } from './support/fixtures.js';
import { port, stripeUrl } from './env.js';

// 注文の操作。ショップで注文して偽の Stripe で払い、管理画面で発送 → 完了、もう 1 つは返金して取り消す（再確認つき）

const shop = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

// お客さんとして注文して払う（別のブラウザの文脈）。返すのは注文番号
async function orderAndPay(browser, tenant) {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.route('**/postal/*', (route) => route.fulfill({ json: { addresses: [] } }));
    await page.goto(shop(tenant, '/products'));
    await page.getByTestId('product-card').first().click();
    await page.getByTestId('add-to-cart').click();
    await expect(page.getByTestId('cart-count').first()).toHaveText('1');
    await page.goto(shop(tenant, '/checkout'));
    await page.getByTestId('buyer-email').fill('hanako@example.com');
    await page.getByTestId('buyer-name').fill('山田 花子');
    await page.getByTestId('buyer-phone').fill('09012345678');
    await page.getByTestId('buyer-postal').fill('1500001');
    await page.getByTestId('buyer-prefecture').selectOption('東京都');
    await page.getByTestId('buyer-city').fill('渋谷区');
    await page.getByTestId('buyer-line1').fill('神宮前 1-2-3');
    await page.getByTestId('to-confirm').click();
    await page.getByTestId('place-order').click();
    await expect(page).toHaveURL(new RegExp(`^${stripeUrl}/pay/cs_test_`));
    await page.getByTestId('fake-stripe-done').click();
    await expect(page.getByTestId('payment-paid')).toBeVisible();
    const number = (await page.getByTestId('order-number').textContent()).toLowerCase();
    await context.close();

    return number;
}

test('払われた注文を発送して完了にし、別の注文は返金して取り消す', async ({ page, browser, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, stock: 5, stripe: true });
    const shipping = await orderAndPay(browser, tenant);
    const cancelling = await orderAndPay(browser, tenant);

    // 一覧に対応の状況（入金済み）
    await page.goto(`/tenants/${tenant.id}/transactions`);
    await expect(page.getByTestId('transaction-row')).toHaveCount(2);
    await expect(page.getByTestId('transaction-row').first()).toContainText('入金済み');

    // 発送 → 完了
    await page.goto(`/tenants/${tenant.id}/transactions/${shipping}`);
    await expect(page.getByTestId('order-status')).toHaveText('入金済み');
    await page.getByTestId('ship-carrier').selectOption('yamato');
    await page.getByTestId('ship-tracking').fill('1234-5678-9012');
    await page.getByTestId('ship').click();
    await expect(page.getByRole('status')).toContainText('発送を登録しました');
    await expect(page.getByTestId('order-status')).toHaveText('発送済み');
    await page.getByTestId('complete').click();
    await page.getByTestId('confirm-complete').click();
    await expect(page.getByTestId('order-status')).toHaveText('完了');
    await expect(page.getByTestId('order-actions')).not.toContainText('注文を取り消す');
    // お客さんへの知らせ（支払いの確定と発送）を送った履歴
    await expect(page.getByTestId('sent-mail-row')).toHaveCount(2);
    await expect(page.getByTestId('sent-mails')).toContainText('ご注文の商品を発送しました');

    // お客さんはご注文の確認で、番号とメールから状況を見られる（お問い合わせ番号も）
    const customer = await browser.newContext();
    const lookup = await customer.newPage();
    await lookup.goto(shop(tenant, '/orders'));
    await lookup.getByTestId('lookup-order-number').fill(shipping.toUpperCase());
    await lookup.getByTestId('lookup-email').fill('HANAKO@example.com');
    await lookup.getByTestId('lookup-submit').click();
    await expect(lookup.getByTestId('order-status-label')).toHaveText('完了');
    await expect(lookup.getByTestId('shipment')).toContainText('お問い合わせ番号 1234-5678-9012');
    await customer.close();

    // 取消（返金）。お金が動くので、パスキーで本人確認をしてから
    await page.goto(`/tenants/${tenant.id}/transactions/${cancelling}`);
    await page.getByTestId('cancel-order').click();
    await expect(page.getByTestId('cancel-confirm-text')).toContainText('¥1,000 を Stripe でお客さんに返金し');
    await page.getByTestId('confirm-cancel').click();
    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await expect(dialog).toBeVisible();
    // 本人確認をやめたら取り消さず、ボタンは元に戻る
    await dialog.getByRole('button', { name: 'やめる' }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.getByTestId('confirm-cancel')).toBeHidden();
    await expect(page.getByTestId('order-status')).not.toHaveText('注文取消し');
    await page.getByTestId('cancel-order').click();
    await page.getByTestId('confirm-cancel').click();
    await expect(dialog).toBeVisible();
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();
    await expect(page.getByRole('status')).toContainText('¥1,000 を Stripe で返金しました');
    await expect(page.getByTestId('order-status')).toHaveText('注文取消し');
    await expect(page.getByTestId('journal-row').filter({ hasText: '返金' })).toHaveCount(1);
});

test('返金が要る支払いの表に金額が出る', async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ stray: true });
    await page.goto(`/tenants/${tenant.id}`);
    await page.getByTestId('stray-notice').getByRole('link').first().click();
    // 前は数の欄の部品に値を渡しておらず、金額の欄が空だった（ヘルプの写しを撮っていて見つけた）
    await expect(page.getByTestId('stray-row')).toHaveCount(1);
    await expect(page.getByTestId('stray-row')).toContainText('¥1,500');
});

test('発送した注文の一部を返金し、返金の履歴と一部返金の札が出る', async ({ page, browser, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, stock: 5, stripe: true });
    const order = await orderAndPay(browser, tenant);

    await page.goto(`/tenants/${tenant.id}/transactions/${order}`);
    await page.getByTestId('ship').click();
    await expect(page.getByTestId('order-status')).toHaveText('発送済み');

    // 返品・返金（ADR 0048）: 金額だけの返金を選び、確かめの画面で額と税率ごとの内訳を見てから、パスキーで本人確認をして返金する
    await expect(page.getByTestId('return-form')).toBeVisible();
    await page.getByTestId('return-amount').fill('300');
    await page.getByTestId('return-reason').selectOption('goodwill');
    await page.getByTestId('return-check').click();
    await expect(page.getByTestId('return-preview')).toContainText('¥300');
    await page.getByTestId('return-submit').click();
    await expect(page.getByTestId('return-confirm-text')).toContainText('¥300 を Stripe でお客さんに返金します');
    await page.getByTestId('return-confirm').click();
    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await expect(dialog).toBeVisible();
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();
    await expect(page.getByTestId('returns').getByRole('status')).toContainText('¥300 を Stripe で返金しました');

    // 注文の状態は発送済みのまま、一部返金の札と返金の履歴（誰が・額）。台帳に返金と調整の仕訳
    await expect(page.getByTestId('order-status')).toHaveText('発送済み');
    await expect(page.getByTestId('refund-summary-state')).toContainText('一部返金');
    await expect(page.getByTestId('return-history-row')).toHaveCount(1);
    await expect(page.getByTestId('return-history-row')).toContainText('お詫び・お見舞い');
    await expect(page.getByTestId('return-history-state')).toHaveText('返金済み');
    await expect(page.getByTestId('journal-row').filter({ hasText: '調整' })).toHaveCount(1);

    await page.goto(`/tenants/${tenant.id}/transactions`);
    await expect(page.getByTestId('refund-state-badge')).toHaveText('一部返金');
});
