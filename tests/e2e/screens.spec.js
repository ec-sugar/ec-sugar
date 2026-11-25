import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// 主な画面の写しを test-results/screens/ に撮る（目で見た目を確かめる用）。E2E_SCREENSHOTS=1 のときだけ動く。
// ダークは読み込み直してから撮る（切り替えた直後は色の変化の途中が写る）
test.skip(!process.env.E2E_SCREENSHOTS, '写しを撮るときだけ（E2E_SCREENSHOTS=1）');

async function shoot(page, name) {
    await page.screenshot({ path: `test-results/screens/${name}.png`, fullPage: true });
}

test('主な画面の写し（ライトとダーク）', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 3, drafts: 1, stock: 3, transaction: true });

    await page.goto(`/tenants/${tenant.id}`);
    await expect(page.getByTestId('home-stats')).toBeVisible();
    await shoot(page, 'tenant');

    await page.goto(`/tenants/${tenant.id}/listings`);
    await expect(page.getByTestId('listing-row').first()).toBeVisible();
    await shoot(page, 'listings');

    await page.getByRole('link', { name: '商品 1', exact: true }).click();
    await expect(page.getByTestId('stock-row').first()).toBeVisible();
    await shoot(page, 'listing');

    await page.goto(`/tenants/${tenant.id}/transactions/${tenant.transaction.id}`);
    await page.getByTestId('show-personal').click();
    await expect(page.getByTestId('personal-customer')).toBeVisible();
    await shoot(page, 'transaction');

    // 店頭（テナントのホスト）
    await page.goto(`http://${tenant.id}.localhost:${port}/`);
    await expect(page.getByTestId('product-card').first()).toBeVisible();
    await shoot(page, 'storefront');

    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto(`http://${tenant.id}.localhost:${port}/`);
    await expect(page.getByTestId('product-card').first()).toBeVisible();
    await shoot(page, 'storefront-dark');

    for (const [name, path, ready] of [
        ['listings-dark', `/tenants/${tenant.id}/listings`, 'listing-row'],
        ['transaction-dark', `/tenants/${tenant.id}/transactions/${tenant.transaction.id}`, 'line-row'],
    ]) {
        await page.goto(path);
        await expect(page.getByTestId(ready).first()).toBeVisible();
        await shoot(page, name);
    }
});

test('メニューと設定の写し（店の切り替え・設定の目次と横の目次・スマホの幅）', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 2 });

    await page.goto(`/tenants/${tenant.id}/settings`);
    await expect(page.getByTestId('settings-basic')).toBeVisible();
    await shoot(page, 'settings');

    await page.getByTestId('settings-legal').click();
    await expect(page.getByTestId('settings-toc')).toBeVisible();
    await expect(page.getByTestId('add-legal')).toBeVisible();
    await shoot(page, 'settings-legal');

    await page.goto(`/tenants/${tenant.id}/categories`);
    await page.getByRole('button', { name: /店の切り替えと運営全体/ }).click();
    await expect(page.getByTestId('shop-switcher-panel')).toBeVisible();
    await page.screenshot({ path: 'test-results/screens/switcher.png' });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/tenants/${tenant.id}/listings`);
    await expect(page.getByTestId('listing-row').first()).toBeVisible();
    await shoot(page, 'mobile-listings');
    await page.getByTestId('menu-toggle').click();
    await shoot(page, 'mobile-menu');
    await page.goto(`/tenants/${tenant.id}/settings/shipping`);
    await expect(page.getByTestId('shipping-fee')).toBeVisible();
    await shoot(page, 'mobile-settings-shipping');
});
