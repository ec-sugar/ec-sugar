import { expect, runFixture, test } from './support/fixtures.js';
import { port } from './env.js';

// 店頭のカート。JS あり（ページを移らずに入れ、横からカートが出る）と、JS なし（フォームでページを移る）。
// CSP と Trusted Types の違反の見張りは全場面に自動で掛かる（核の部品は HTML の文字列を差し込まない）

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

test('JS あり: ページを移らずに入れ、横からカートが出る。カートのページは読み込み直さずに数が変わる', async ({ page, makeTenant }) => {
    const tenant = makeTenant({ listings: 2, stock: 3, theme: 'kinari' });

    await page.goto(storefront(tenant, '/products'));
    await page.getByTestId('product-card').filter({ hasText: '商品 1' }).click();
    const detail = page.url();

    // 詳細の数は「− 1 +」。在庫（3）で + は押せなくなる
    await expect(page.getByTestId('quantity-decrease')).toBeDisabled();
    await page.getByTestId('quantity-increase').click();
    await page.getByTestId('quantity-increase').click();
    await expect(page.getByTestId('quantity')).toHaveValue('3');
    await expect(page.getByTestId('quantity-increase')).toBeDisabled();
    await page.getByTestId('quantity-decrease').click();

    await page.getByTestId('add-to-cart').click();
    const drawer = page.getByTestId('cart-drawer');
    await expect(drawer).toBeVisible();
    expect(page.url()).toBe(detail);
    await expect(page.getByTestId('drawer-message')).toHaveText('カートに入れました。');
    await expect(drawer.getByTestId('drawer-line')).toHaveCount(1);
    await expect(drawer.getByTestId('drawer-line')).toContainText('商品 1');
    await expect(page.getByTestId('drawer-subtotal')).toHaveText('¥2,000');
    await expect(page.getByTestId('cart-count')).toHaveText('2');

    // Esc で閉じる。上限を超える数は、横のカートに理由が出る
    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
    await page.getByTestId('quantity-increase').click();
    await page.getByTestId('add-to-cart').click();
    await expect(page.getByTestId('drawer-message')).toHaveText('この商品は 3 点までです。');
    await expect(page.getByTestId('cart-count')).toHaveText('2');

    // カートのページ: 読み込み直さない（ページに付けた印が残る）
    await page.getByTestId('drawer-to-cart').click();
    await expect(page).toHaveURL(storefront(tenant, '/cart'));
    await page.evaluate(() => { window.__stillHere = true; });
    await page.getByTestId('increase').click();
    await expect(page.getByTestId('line-quantity')).toHaveText('3');
    await expect(page.getByTestId('cart-subtotal')).toHaveText('¥3,000');
    await expect(page.getByTestId('increase')).toBeDisabled();
    await expect(page.getByTestId('cart-count')).toHaveText('3');
    await page.getByTestId('decrease').click();
    await expect(page.getByTestId('line-quantity')).toHaveText('2');
    expect(await page.evaluate(() => window.__stillHere)).toBe(true);

    // 最後の 1 品を外すと、空の案内（ページを描き直す）
    await page.getByTestId('remove-line').click();
    await expect(page.getByTestId('cart-empty')).toBeVisible();
    await expect(page.getByTestId('cart-count')).toBeHidden();
});

test('在庫が減った後: 「−」で数を直せ、行の知らせと打ち消しが読み込み直さずに書き換わる', { tag: '@nightly' }, async ({ page, makeTenant }) => {
    const tenant = makeTenant({ listings: 1, stock: 4 });

    await page.goto(storefront(tenant, '/products'));
    await page.getByTestId('product-card').first().click();
    for (let i = 0; i < 3; i++) {
        await page.getByTestId('quantity-increase').click();
    }
    await expect(page.getByTestId('quantity')).toHaveValue('4');
    await page.getByTestId('add-to-cart').click();
    await expect(page.getByTestId('cart-count')).toHaveText('4');

    // 入れた後に在庫が 4 → 2
    expect(runFixture('stock', tenant.id, '-2').available).toBe(2);
    await page.goto(storefront(tenant, '/cart'));
    const line = page.getByTestId('cart-line');
    const total = line.locator('[data-field="total"]');
    await expect(page.getByTestId('cart-notice')).toHaveText('在庫が少なくなりました（残り 2 点）。数量を減らしてください。');
    await expect(line).toHaveAttribute('data-unavailable', '');
    await expect(total).toHaveCSS('text-decoration-line', 'line-through');
    await expect(page.getByTestId('increase')).toBeDisabled();
    await page.evaluate(() => { window.__stillHere = true; });

    // 「−」は 3 点を送る。受け付けるが、まだ在庫より多いので知らせと打ち消しは残る
    await page.getByTestId('decrease').click();
    await expect(page.getByTestId('line-quantity')).toHaveText('3');
    await expect(page.getByTestId('cart-message')).toHaveText('数量を変更しました。');
    await expect(page.getByTestId('cart-notice')).toHaveText('在庫が少なくなりました（残り 2 点）。数量を減らしてください。');
    await expect(line).toHaveAttribute('data-unavailable', '');

    // 在庫まで減らすと、知らせが消えて打ち消しも外れ、小計に入る
    await page.getByTestId('decrease').click();
    await expect(page.getByTestId('line-quantity')).toHaveText('2');
    await expect(page.getByTestId('cart-notice')).toBeHidden();
    await expect(line).not.toHaveAttribute('data-unavailable');
    await expect(total).toHaveCSS('text-decoration-line', 'none');
    await expect(page.getByTestId('cart-subtotal')).toHaveText('¥2,000');
    await expect(page.getByTestId('cart-count')).toHaveText('2');
    await expect(page.getByTestId('increase')).toBeDisabled();

    // 逆向き: 画面を開いたまま売り切れになると、「−」の答えで知らせと打ち消しが新しく出る（隠していた欄に入る）
    expect(runFixture('stock', tenant.id, '-2').available).toBe(0);
    await page.getByTestId('decrease').click();
    await expect(page.getByTestId('line-quantity')).toHaveText('1');
    await expect(page.getByTestId('cart-notice')).toHaveText('売り切れになりました。');
    await expect(line).toHaveAttribute('data-unavailable', '');
    await expect(total).toHaveCSS('text-decoration-line', 'line-through');
    await expect(page.getByTestId('cart-subtotal')).toHaveText('¥0');
    await expect(page.getByTestId('cart-count')).toBeHidden();
    expect(await page.evaluate(() => window.__stillHere)).toBe(true);
});

test('JS なし: フォームでページを移って同じことができる', async ({ browser, makeTenant }) => {
    const tenant = makeTenant({ listings: 1, stock: 5, theme: 'kinari' });
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();

    await page.goto(storefront(tenant, '/products'));
    await page.getByTestId('product-card').first().click();
    await page.getByTestId('quantity').fill('2');
    await page.getByTestId('add-to-cart').click();
    await expect(page).toHaveURL(storefront(tenant, '/cart'));
    await expect(page.getByTestId('cart-message')).toHaveText('カートに入れました。');
    await page.getByTestId('increase').click();
    await expect(page.getByTestId('line-quantity')).toHaveText('3');
    await expect(page.getByTestId('cart-count')).toHaveText('3');
    await context.close();
});

test('横のカートが無いテーマでは、入れた後にカートのページへ移る。別のサイトから送らせると 419', async ({ page, makeTenant }) => {
    const tenant = makeTenant({ listings: 1, stock: 5 });
    // 今のテーマはどれも横のカートを持つので、核の部品が探す前に取り除いて「持たないテーマ」にする
    await page.addInitScript(() => {
        new MutationObserver(() => document.querySelector('dialog[data-cart-drawer]')?.remove())
            .observe(document, { childList: true, subtree: true });
    });

    await page.goto(storefront(tenant, '/products'));
    await page.getByTestId('product-card').first().click();
    await page.getByTestId('quantity-increase').click();
    await page.getByTestId('add-to-cart').click();
    await expect(page).toHaveURL(storefront(tenant, '/cart'));
    await expect(page.getByTestId('line-quantity')).toHaveText('2');

    // 別のサイトのページから送らせた「カートに入れる」は、CSRF の合い言葉が無いので 419（同じサイトの中からの送信は、ブラウザの Sec-Fetch-Site で通す）
    const add = storefront(tenant, '/cart/items');
    await page.route('http://attacker.test/**', (route) => route.fulfill({
        contentType: 'text/html',
        body: `<form id="f" method="POST" action="${add}"><input name="listing_id" value="x"><input name="quantity" value="1"></form>`,
    }));
    const refused = page.waitForResponse((response) => response.url() === add);
    await page.goto('http://attacker.test/');
    await page.evaluate(() => document.getElementById('f').submit());
    expect((await refused).status()).toBe(419);
    await expect(page.getByRole('heading', { name: '画面の有効期限が切れました' })).toBeVisible();

    // 断られても、カートはそのまま
    await page.goto(storefront(tenant, '/cart'));
    await expect(page.getByTestId('line-quantity')).toHaveText('2');
});
