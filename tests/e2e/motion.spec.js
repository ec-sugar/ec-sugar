import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// ショップの動き。強さはテーマの theme.json の motion（角砂糖・kinari は控えめ、fashion・botanica はしっかり）。
// 動きのせいで中身が見えない時間を作らない（JS が無くても・動きを減らす設定でも全部見える）。CSP と Trusted Types の違反の見張りは全場面に自動で掛かる

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

// ほかの場面は「動きを減らす」で走る（playwright.config.js）。ここは動きそのものを見るので戻す。
// 「動きを減らす設定」の場面だけ、その中で page.emulateMedia で reduce にする
test.use({ reducedMotion: 'no-preference' });

test('しっかりのテーマ: 最初から見えている札はそのまま、スクロールした先の札は浮かんで見える', { tag: '@nightly' }, async ({ page, makeTenant }) => {
    const tenant = makeTenant({ listings: 12, stock: 3, theme: 'fashion' });
    await page.setViewportSize({ width: 1280, height: 720 });

    await page.goto(storefront(tenant, '/products'));
    await expect(page.locator('html')).toHaveAttribute('data-motion', 'rich');
    await expect(page.locator('html')).toHaveAttribute('data-motion-ready', '');

    const cards = page.getByTestId('product-card');
    await expect(cards.first()).toHaveAttribute('data-motion-shown', '');
    await expect(cards.first()).toHaveCSS('opacity', '1');

    // 下の札は、画面に入ると印が付き、隠れたまま残らない
    const last = cards.last();
    await last.scrollIntoViewIfNeeded();
    await expect(last).toHaveAttribute('data-motion-shown', '');
    await expect(last).toHaveCSS('opacity', '1');
});

test('動きを減らす設定: 隠さず、ページを開いた時の動きも止まる', { tag: '@nightly' }, async ({ page, makeTenant }) => {
    const tenant = makeTenant({ listings: 12, stock: 3, theme: 'botanica' });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.setViewportSize({ width: 1280, height: 720 });

    await page.goto(storefront(tenant, '/'));
    await expect(page.locator('html')).toHaveAttribute('data-motion', 'rich');
    // 浮かぶ動きの印（data-motion-ready）を付けないので、何も隠れない
    await expect(page.locator('html')).not.toHaveAttribute('data-motion-ready', '');
    await expect(page.locator('[data-motion-intro]')).toHaveCSS('animation-name', 'none');
    await expect(page.getByTestId('product-card').last()).toHaveCSS('opacity', '1');
});

test('JS なし: 浮かぶ動きの札も最初から見える', { tag: '@nightly' }, async ({ browser, makeTenant }) => {
    const tenant = makeTenant({ listings: 12, stock: 3, theme: 'fashion' });
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 720 } });
    const page = await context.newPage();

    await page.goto(storefront(tenant, '/products'));
    await expect(page.locator('html')).not.toHaveAttribute('data-motion-ready', '');
    await expect(page.getByTestId('product-card').last()).toHaveCSS('opacity', '1');
    await context.close();
});

test('控えめのテーマ: スマホのメニューを開け閉めでき、カートの数が変わる', { tag: '@nightly' }, async ({ page, makeTenant }) => {
    const tenant = makeTenant({ listings: 1, stock: 3, theme: 'kinari' });
    await page.setViewportSize({ width: 390, height: 844 });

    await page.goto(storefront(tenant, '/'));
    await expect(page.locator('html')).toHaveAttribute('data-motion', 'subtle');
    // 控えめのテーマは浮かぶ動きを使わない
    await expect(page.locator('html')).not.toHaveAttribute('data-motion-ready', '');

    const menu = page.getByTestId('menu');
    const panel = menu.locator('[data-motion-menu-panel]');
    await menu.locator('summary').click();
    await expect(panel).toBeVisible();
    await expect(panel.getByRole('link', { name: 'すべての商品' })).toBeVisible();
    // 閉じる動きが終わってから閉じる。Esc でも閉じる
    await menu.locator('summary').click();
    await expect(menu).not.toHaveAttribute('open', '');
    await expect(panel).toBeHidden();
    await menu.locator('summary').click();
    await expect(panel).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(menu).not.toHaveAttribute('open', '');

    // カートに入れると数が変わり、動きの跡（style の値）が残らない
    await page.getByTestId('product-card').first().click();
    await page.getByTestId('add-to-cart').click();
    await expect(page.getByTestId('cart-count')).toHaveText('1');
    await expect.poll(() => page.getByTestId('cart-count').evaluate((element) => element.style.transform)).toBe('');
});
