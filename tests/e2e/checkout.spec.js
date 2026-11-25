import { expect, runFixture, test } from './support/fixtures.js';
import { port, stripeUrl } from './env.js';

// 注文の入力と最終確認。郵便番号の答えはブラウザの中で差し替える（テストから外の API を呼ばない）

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

test('郵便番号から住所が入り、お届け先を分けて、最終確認の画面に進む', async ({ page, makeTenant }) => {
    const tenant = makeTenant({ listings: 1, stock: 5, theme: 'kinari' });
    await page.route('**/postal/1500001', (route) => route.fulfill({ json: { addresses: [{ prefecture: '東京都', city: '渋谷区', town: '神宮前' }] } }));
    await page.route('**/postal/0089999', (route) => route.fulfill({ json: { addresses: [] } }));

    await page.goto(storefront(tenant, '/products'));
    await page.getByTestId('product-card').first().click();
    await page.getByTestId('add-to-cart').click();
    await page.getByTestId('drawer-to-cart').click();
    await page.getByTestId('to-checkout').click();
    await expect(page).toHaveURL(storefront(tenant, '/checkout'));

    // 誤りのまま送ると、理由が出る
    await page.getByTestId('to-confirm').click();
    await expect(page.getByTestId('checkout-errors')).toBeVisible();

    await page.getByTestId('buyer-email').fill('hanako@example.com');
    await page.getByTestId('buyer-name').fill('山田 花子');
    await page.getByTestId('buyer-phone').fill('09012345678');
    await page.getByTestId('buyer-postal').fill('1500001');
    await expect(page.getByTestId('buyer-city')).toHaveValue('渋谷区');
    await expect(page.getByTestId('buyer-prefecture')).toHaveValue('東京都');
    await expect(page.getByTestId('buyer-line1')).toHaveValue('神宮前');
    await page.getByTestId('buyer-line1').fill('神宮前 1-2-3');

    // お届け先を分ける。見つからない郵便番号では、何も入れずに案内だけ（008 はまだ使われていない上 3 桁なので、手で選んだ都道府県と照らさずに通る。ADR 0064）
    await expect(page.getByTestId('recipient-name')).toBeHidden();
    await page.getByTestId('same-as-buyer').uncheck();
    await page.getByTestId('recipient-name').fill('山田 太郎');
    await page.getByTestId('recipient-phone').fill('0312345678');
    await page.getByTestId('recipient-postal').fill('0089999');
    await expect(page.getByText('住所が見つかりませんでした')).toBeVisible();
    await page.getByTestId('recipient-prefecture').selectOption('北海道');
    await page.getByTestId('recipient-city').fill('札幌市中央区');
    await page.getByTestId('recipient-line1').fill('北 1 条西 2');

    await page.getByTestId('to-confirm').click();
    await expect(page).toHaveURL(storefront(tenant, '/checkout/confirm'));
    await expect(page.getByTestId('confirm-buyer')).toContainText('〒150-0001 東京都渋谷区神宮前 1-2-3');
    await expect(page.getByTestId('confirm-recipient')).toContainText('山田 太郎 様');
    await expect(page.getByTestId('confirm-recipient')).toContainText('〒008-9999 北海道札幌市中央区北 1 条西 2');
    await expect(page.getByTestId('confirm-total')).toHaveText('¥1,000');

    // 入力に戻ると、入れた値が残っている
    await page.getByTestId('back-to-input').click();
    await expect(page.getByTestId('buyer-line1')).toHaveValue('神宮前 1-2-3');
    await expect(page.getByTestId('same-as-buyer')).not.toBeChecked();
    await expect(page.getByTestId('recipient-name')).toHaveValue('山田 太郎');
});

// 入力して最終確認の画面まで（ご依頼主と同じお届け先）
async function toConfirm(page, tenant) {
    await page.goto(storefront(tenant, '/products'));
    await page.getByTestId('product-card').first().click();
    await page.getByTestId('add-to-cart').click();
    await expect(page.getByTestId('cart-count').first()).toHaveText('1');
    await page.goto(storefront(tenant, '/checkout'));
    await page.getByTestId('buyer-email').fill('hanako@example.com');
    await page.getByTestId('buyer-name').fill('山田 花子');
    await page.getByTestId('buyer-phone').fill('09012345678');
    await page.getByTestId('buyer-postal').fill('1500001');
    await page.getByTestId('buyer-prefecture').selectOption('東京都');
    await page.getByTestId('buyer-city').fill('渋谷区');
    await page.getByTestId('buyer-line1').fill('神宮前 1-2-3');
    await page.getByTestId('to-confirm').click();
    await expect(page).toHaveURL(storefront(tenant, '/checkout/confirm'));
}

test('注文を確定すると Stripe の支払いの画面へ移り、払うと通知で確定する。カートは空。二度押しでも 1 件だけ', async ({ page, makeTenant }) => {
    const tenant = makeTenant({ listings: 1, stock: 5, theme: 'kinari', stripe: true });
    await page.route('**/postal/*', (route) => route.fulfill({ json: { addresses: [] } }));
    await toConfirm(page, tenant);

    // 二度押し（ほぼ同時に 2 回送る）。支払いの画面（偽の Stripe）へ移る。金額はサーバーの取引から
    await page.getByTestId('place-order').dblclick();
    await expect(page).toHaveURL(new RegExp(`^${stripeUrl}/pay/cs_test_`));
    await expect(page.getByTestId('fake-stripe-amount')).toHaveText('¥1,000');

    // 支払わずに戻る: 受け付けた画面に「まだ」と「お支払いへ進む」
    await page.getByTestId('fake-stripe-back').click();
    await expect(page).toHaveURL(storefront(tenant, '/checkout/complete'));
    await expect(page.getByTestId('payment-due')).toBeVisible();
    await expect(page.getByTestId('order-total')).toHaveText('¥1,000');
    const number = await page.getByTestId('order-number').textContent();
    expect(number).toMatch(/^[0-9A-Z]{26}$/);
    await expect(page.getByTestId('cart-count')).toBeHidden();
    expect(runFixture('sales', tenant.id)).toEqual({ sales: 1, reserved: 1 });

    // 戻ってきた印だけでは「済み」にしない（Webhook で確かめるまで「確かめています」）
    await page.goto(storefront(tenant, '/checkout/complete?returned=1'));
    await expect(page.getByTestId('payment-pending')).toBeVisible();

    // もう一度「お支払いへ進む」は同じ支払いの画面。支払うと、偽の Stripe が署名した通知を店へ送ってから戻す
    await page.getByTestId('pay').click();
    await expect(page).toHaveURL(new RegExp(`^${stripeUrl}/pay/cs_test_`));
    await expect(page.getByTestId('fake-stripe-reference')).toHaveText(number.toLowerCase());
    await page.getByTestId('fake-stripe-done').click();
    await expect(page.getByTestId('payment-paid')).toBeVisible();
    await expect(page.getByTestId('pay')).toBeHidden();

    // 確認の画面を開き直しても、入力は消えていて、もう確定できない
    await page.goto(storefront(tenant, '/checkout/confirm'));
    await expect(page).toHaveURL(storefront(tenant, '/cart'));
    await expect(page.getByTestId('cart-empty')).toBeVisible();
    await page.goto(storefront(tenant, '/checkout/complete'));
    await expect(page.getByTestId('order-number')).toHaveText(number);
});

test('最後の 1 点を二人が同時に確定すると、一人だけが買える', async ({ browser, makeTenant }) => {
    const tenant = makeTenant({ listings: 1, stock: 1, stripe: true });
    const pages = [];
    for (let i = 0; i < 2; i++) {
        const page = await (await browser.newContext()).newPage();
        await page.route('**/postal/*', (route) => route.fulfill({ json: { addresses: [] } }));
        await toConfirm(page, tenant);
        pages.push(page);
    }

    await Promise.all(pages.map((page) => page.getByTestId('place-order').click()));
    await Promise.all(pages.map((page) => page.waitForURL(/(\/pay\/cs_test_[a-f0-9]+|\/cart)$/)));
    const urls = pages.map((page) => (page.url().endsWith('/cart') ? 'cart' : 'stripe')).sort();
    expect(urls).toEqual(['cart', 'stripe']);

    const refused = pages.find((page) => page.url().endsWith('/cart'));
    await expect(refused.getByText('現在ご購入いただけない商品があります')).toBeVisible();
    expect(runFixture('sales', tenant.id)).toEqual({ sales: 1, reserved: 1 });
    await Promise.all(pages.map((page) => page.context().close()));
});
