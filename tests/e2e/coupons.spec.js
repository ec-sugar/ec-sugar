import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// クーポンの画面（ADR 0054）と自動の割引（ADR 0065）。管理画面で自動の割引を作ると、一覧に「自動」の札が付き、
// ショップの商品の画面に名前、カートに次に届く割引の一言と、届いた後の値引きの行が出る（コードは要らない）。
// CSP と Trusted Types の違反の見張りは全場面に自動で掛かる（support/fixtures.js）

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

test('自動の割引を作ると一覧に「自動」の札が付き、ショップのカートで数が届くとコード無しで効く', async ({ page, operator, makeTenant }) => {
    void operator;
    // 商品はどれも ¥1,000・在庫 5
    const tenant = makeTenant({ listings: 1, stock: 5 });

    await page.goto(`/tenants/${tenant.id}/coupons`);
    await page.getByTestId('new-automatic-discount').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('新しい自動の割引');
    // 自動の割引にはコードの欄が無く、お客さんに見せる名前の欄がある
    await expect(page.getByTestId('coupon-code')).toHaveCount(0);
    await page.getByTestId('coupon-label').fill('まとめ買い 10%');
    await page.getByTestId('coupon-name').fill('ブラウザのテストのまとめ買い');
    await page.getByTestId('coupon-kind-percent').check();
    await page.getByTestId('coupon-value').fill('10');
    await page.getByTestId('coupon-min-quantity').fill('2');
    // 1 人の回数の既定は空（何回でも）
    await expect(page.getByTestId('coupon-per-customer')).toHaveValue('');
    await page.getByTestId('save-coupon').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('まとめ買い 10%');

    // 一覧: 自分の割引の行に「自動」の札（同時に走るほかの場面の行が混ざっても、名前で絞る）
    await page.goto(`/tenants/${tenant.id}/coupons`);
    const row = page.getByTestId('coupon-row').filter({ hasText: 'まとめ買い 10%' });
    await expect(row.getByTestId('coupon-method')).toHaveText('自動');
    await expect(row).toContainText('2 点以上');

    // ショップ: 商品の画面に割引の名前
    await page.goto(storefront(tenant, '/products'));
    await page.getByTestId('product-card').filter({ hasText: '商品 1' }).click();
    await expect(page.getByTestId('product-discounts').getByTestId('product-discount')).toHaveText(['まとめ買い 10%']);

    // 1 点: まだ効かず、次に届く割引の一言。2 点にすると読み込み直さずに値引きの行が出て、一言は消える
    await page.getByTestId('add-to-cart').click();
    // 入れ終わる（カートの引き出しが開く）のを待ってからカートへ
    await expect(page.getByTestId('cart-drawer')).toBeVisible();
    await page.goto(storefront(tenant, '/cart'));
    await expect(page.getByTestId('discount-hint')).toHaveText('あと 1 点で「まとめ買い 10%」');
    await expect(page.getByTestId('cart-discount')).toBeHidden();
    await page.getByTestId('increase').click();
    await expect(page.getByTestId('line-quantity')).toHaveText('2');
    await expect(page.getByTestId('cart-discount-name')).toHaveText('まとめ買い 10%');
    await expect(page.getByTestId('cart-discount')).toHaveText('−¥200');
    await expect(page.getByTestId('discount-hint')).toBeHidden();
});
