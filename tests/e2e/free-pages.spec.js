import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// 自由なページ（ADR 0039 区切り 3）。「ページ」の一覧で作り、ページの編集の画面で区画を足して公開すると、ショップの /pages/{URL} に出てメニューに載る。
// 公開するまでは、ショップでは見つからない

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

test('ページを作って区画を足し、公開するとショップで見え、メニューに載る', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1 });

    // 一覧で作る（最初は下書き）
    await page.goto(`/tenants/${tenant.id}/pages`);
    await page.getByTestId('page-title').fill('私たちについて');
    await page.getByTestId('page-slug').fill('about');
    await page.getByTestId('add-page').click();
    const row = page.locator('[data-test="page-row"][data-page-slug="about"]');
    await expect(row.getByTestId('page-status')).toHaveText('下書き');

    // 下書きのページはショップでは見つからない
    const shop = await page.context().newPage();
    const missing = await shop.goto(storefront(tenant, '/pages/about'));
    expect(missing.status()).toBe(404);

    // 中身を作る（ページの編集の画面へ。既定のテーマの雛形の最初の並びは、核の汎用の区画「見出しと文」）
    await row.getByTestId('edit-page-content').click();
    await expect(page.getByRole('heading', { name: '私たちについての編集' })).toBeVisible();
    const rows = page.getByTestId('section-row');
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toHaveAttribute('data-section-type', 'basic_text');
    await page.getByTestId('field-0-heading').fill('店の歩み');

    // 写真と文を足す（選んでいる見出しと文の後ろへ）
    await page.getByTestId('add-section').click();
    await page.getByTestId('add-basic_image_text-image_right').click();
    await expect(rows).toHaveCount(2);
    await page.getByTestId('field-1-heading').fill('小さな工房から');
    const preview = page.frameLocator('[data-test="page-preview"]');
    await expect(preview.locator('[data-section-type="basic_image_text"]')).toContainText('小さな工房から');
    await expect(preview.getByTestId('free-page-title')).toHaveText('私たちについて');

    // 公開する（このページも公開の状態になる）
    await page.getByTestId('publish-page').click();
    await page.getByTestId('confirm-publish-page').click();
    await expect(page.getByRole('status').filter({ hasText: '公開しました' })).toBeVisible();

    // ショップに出て、メニューに載る（並びは公開した順）
    await shop.goto(storefront(tenant, '/pages/about'));
    await expect(shop.getByTestId('free-page-title')).toHaveText('私たちについて');
    const order = await shop.locator('[data-section]').evaluateAll((elements) => elements.map((e) => e.dataset.sectionType));
    expect(order).toEqual(['basic_text', 'basic_image_text']);
    await expect(shop.getByText('店の歩み')).toBeVisible();
    await shop.goto(storefront(tenant));
    await shop.getByTestId('nav-page').filter({ hasText: '私たちについて' }).click();
    await expect(shop).toHaveURL(storefront(tenant, '/pages/about'));
    if (process.env.E2E_SCREENSHOTS) {
        await page.screenshot({ path: 'test-results/screens/free-page-editor.png', fullPage: true });
        await shop.screenshot({ path: 'test-results/screens/free-page-shop.png', fullPage: true });
    }
    await shop.close();

    // 一覧に戻ると公開中
    await page.getByTestId('editor-back').click();
    await expect(page.locator('[data-test="page-row"][data-page-slug="about"]').getByTestId('page-status')).toHaveText('公開中');
    if (process.env.E2E_SCREENSHOTS) {
        await page.screenshot({ path: 'test-results/screens/free-pages.png', fullPage: true });
    }
});
