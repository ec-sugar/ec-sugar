import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// 店頭（ADR 0018）。テナントのホストで、既定のテーマの画面を本物のブラウザで開く。CSP 違反の見張りは全場面に自動で掛かる

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

test('店頭のトップに公開中の商品が並び、特商法の表記と「見つからない」もテーマの画面で出る', async ({ page, makeTenant }) => {
    // 公開中 3（「商品 1」だけ在庫 3、ほかは在庫なし）と下書き 1
    const tenant = makeTenant({ listings: 3, drafts: 1, stock: 3 });

    await page.goto(storefront(tenant));
    await expect(page).toHaveTitle(tenant.name);
    await expect(page.getByRole('link', { name: tenant.name })).toBeVisible();
    const cards = page.getByTestId('product-card');
    await expect(cards).toHaveCount(3);
    await expect(cards.filter({ hasText: '商品 1' })).not.toContainText('売り切れ');
    await expect(cards.filter({ hasText: '商品 2' })).toContainText('売り切れ');
    await expect(page.locator('main')).not.toContainText('下書きの商品');

    await page.getByRole('link', { name: '特定商取引法に基づく表記' }).click();
    await expect(page.getByRole('heading', { name: '特定商取引法に基づく表記' })).toBeVisible();
    await expect(page.getByTestId('legal-empty')).toBeVisible();

    const missing = await page.goto(storefront(tenant, '/nope'));
    expect(missing.status()).toBe(404);
    await expect(page.getByTestId('error-page')).toContainText('ページが見つかりません');
    await page.getByRole('link', { name: 'トップへ戻る' }).click();
    await expect(cards).toHaveCount(3);
});

test('既定のテーマ（角砂糖）: 同梱の書体 Jost が自分のオリジンから読まれ、カートに入れると横からカートが出る', { tag: '@nightly' }, async ({ page, makeTenant }) => {
    const tenant = makeTenant({ listings: 2, stock: 3, htmlName: true });
    const fonts = [];
    page.on('response', (response) => response.url().endsWith('.woff2') && fonts.push(response));

    await page.goto(storefront(tenant));
    await expect(page.getByTestId('hero')).toBeVisible();
    await expect(page.getByTestId('product-card')).toHaveCount(2);
    expect(await page.evaluate(() => window.__xss)).toBeUndefined();

    await page.evaluate(() => document.fonts.ready);
    expect(await page.evaluate(() => document.fonts.check('500 20px "Jost"'))).toBe(true);
    expect(fonts.length).toBeGreaterThan(0);
    for (const font of fonts) {
        expect(new URL(font.url()).host).toBe(new URL(page.url()).host);
        expect(font.status()).toBe(200);
    }

    // カートが空なら数の印は出さない。入れるとページを移らずに横からカートが出て、数が付く
    await expect(page.getByTestId('cart-count')).toBeHidden();
    await page.getByTestId('product-card').filter({ hasText: '商品 1' }).click();
    const detail = page.url();
    await page.getByTestId('add-to-cart').click();
    const drawer = page.getByTestId('cart-drawer');
    await expect(drawer).toBeVisible();
    expect(page.url()).toBe(detail);
    await expect(page.getByTestId('drawer-message')).toHaveText('カートに入れました。');
    await expect(drawer.getByTestId('drawer-line')).toContainText('商品 1');
    await expect(page.getByTestId('cart-count')).toHaveText('1');
    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
});

test('公式テーマ 1（kinari）の店でも、同梱の書体が自分のオリジンから読まれ、CSP の違反が出ない', { tag: '@nightly' }, async ({ page, makeTenant }) => {
    const tenant = makeTenant({ listings: 2, stock: 3, theme: 'kinari', htmlName: true });
    const fonts = [];
    page.on('response', (response) => response.url().endsWith('.woff2') && fonts.push(response));

    await page.goto(storefront(tenant));
    await expect(page.getByTestId('shop-logo')).toBeVisible();
    await expect(page.getByTestId('product-card')).toHaveCount(2);
    expect(await page.evaluate(() => window.__xss)).toBeUndefined();

    await page.evaluate(() => document.fonts.ready);
    expect(await page.evaluate(() => document.fonts.check('500 20px "Cormorant Garamond"'))).toBe(true);
    expect(fonts.length).toBeGreaterThan(0);
    for (const font of fonts) {
        expect(new URL(font.url()).host).toBe(new URL(page.url()).host);
        expect(font.status()).toBe(200);
    }

    await page.goto(storefront(tenant, '/legal/commerce'));
    await expect(page.getByTestId('legal-empty')).toBeVisible();
});
