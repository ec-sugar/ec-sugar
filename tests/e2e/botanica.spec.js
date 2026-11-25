import { expect, test } from './support/fixtures.js';
import { port, stripeUrl } from './env.js';

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

test('botanica の店で商品からカート・注文確認・支払い完了へ進める', { tag: '@nightly' }, async ({ page, makeTenant }) => {
    const tenant = makeTenant({ listings: 2, stock: 5, theme: 'botanica', stripe: true, htmlName: true });
    await page.route('**/postal/*', (route) => route.fulfill({ json: { addresses: [] } }));
    await page.goto(storefront(tenant));
    await expect(page.locator('body')).toHaveAttribute('data-theme', 'botanica');
    await expect(page.getByTestId('product-card')).toHaveCount(2);
    await expect(page.getByTestId('shop-logo')).toContainText(tenant.name);
    expect(await page.evaluate(() => window.__xss)).toBeUndefined();
    await page.getByTestId('product-card').filter({ hasText: '商品 1' }).click();
    await page.getByTestId('quantity-increase').click();
    await page.getByTestId('add-to-cart').click();
    await expect(page.getByTestId('cart-drawer')).toBeVisible();
    await expect(page.getByTestId('drawer-subtotal')).toHaveText('¥2,000');
    await page.getByTestId('drawer-to-cart').click();
    await expect(page.getByTestId('cart-count')).toHaveText('2');
    await page.goto(storefront(tenant, '/checkout'));
    await page.getByTestId('buyer-email').fill('botanica@example.com');
    await page.getByTestId('buyer-name').fill('山田 花子');
    await page.getByTestId('buyer-phone').fill('09012345678');
    await page.getByTestId('buyer-postal').fill('1500001');
    await page.getByTestId('buyer-prefecture').selectOption('東京都');
    await page.getByTestId('buyer-city').fill('渋谷区');
    await page.getByTestId('buyer-line1').fill('神宮前 1-2-3');
    await page.getByTestId('to-confirm').click();
    await expect(page).toHaveURL(storefront(tenant, '/checkout/confirm'));
    await page.getByTestId('place-order').click();
    await expect(page).toHaveURL(new RegExp(`^${stripeUrl}/pay/cs_test_`));
    await expect(page.getByTestId('fake-stripe-amount')).toHaveText('¥2,000');
    await page.getByTestId('fake-stripe-done').click();
    await expect(page.getByTestId('payment-paid')).toBeVisible();
    await expect(page.getByTestId('cart-count')).toBeHidden();
});

test('botanica の写しとスマホ幅・写真なし・空の商品一覧', { tag: '@nightly' }, async ({ page, makeTenant }) => {
    const tenant = makeTenant({ theme: 'botanica' });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(storefront(tenant));
    await expect(page.getByTestId('products-empty')).toBeVisible();
    await expect(page.getByTestId('hero-image')).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.goto(storefront(tenant, '/products'));
    await expect(page.getByTestId('product-card')).toHaveCount(0);
    if (process.env.E2E_SCREENSHOTS) {
        await page.goto(storefront(tenant));
        await page.screenshot({ path: 'test-results/screens/botanica-mobile.png', fullPage: true });
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.screenshot({ path: 'test-results/screens/botanica-desktop.png', fullPage: true });
    }
});
