import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// ページの雛形。自由なページを作るときに雛形を選ぶと区画がよい並びで入り、プレビューに並び、公開するとショップに出る。
// ページの編集の画面の「雛形から作り直す」は、確かめてから下書きだけを置き換える（お知らせの帯・フッターは残る。公開するまでショップは変わらない）。
// どちらもスマホの幅（390px）で横にはみ出さない

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth);
const types = (locator) => locator.evaluateAll((elements) => elements.map((e) => e.dataset.sectionType));

test('雛形を選んで自由なページを作ると、区画が並んでプレビューに出て、公開するとショップに出る', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 2 });

    await page.goto(`/tenants/${tenant.id}/pages`);
    await page.getByTestId('page-title').fill('ご注文の流れ');
    await page.getByTestId('page-slug').fill('how-to');

    // 雛形は区画の並びをワイヤーの絵で見せる。白紙が選ばれた形で始まる
    await expect(page.getByTestId('template-blank')).toHaveAttribute('aria-checked', 'true');
    const howTo = page.getByTestId('template-how_to');
    await expect(howTo.getByTestId('template-row')).toHaveCount(5);
    await expect(howTo.locator('svg[data-wireframe="steps-row"]')).toHaveCount(1);
    await expect(page.getByTestId('template-shop_top').locator('svg[data-wireframe="hero-band"]')).toHaveCount(1);
    await howTo.click();
    await expect(howTo).toHaveAttribute('aria-checked', 'true');
    await expect(page.getByTestId('template-blank')).toHaveAttribute('aria-checked', 'false');
    if (process.env.E2E_SCREENSHOTS) {
        await page.getByTestId('page-templates').screenshot({ path: 'test-results/screens/page-templates-picker.png' });
    }
    await page.getByTestId('add-page').click();
    await expect(page.getByRole('status').filter({ hasText: '雛形のセクションが入っています' })).toBeVisible();

    // 中身を作る: 雛形の並びが下書きに入っている
    await page.locator('[data-test="page-row"][data-page-slug="how-to"]').getByTestId('edit-page-content').click();
    await expect(page.getByRole('heading', { name: 'ご注文の流れの編集' })).toBeVisible();
    const rows = page.getByTestId('section-row');
    await expect(rows).toHaveCount(5);
    expect(await types(rows)).toEqual(['basic_text', 'basic_steps', 'basic_features', 'basic_faq', 'basic_cta']);
    const preview = page.frameLocator('[data-test="page-preview"]');
    await expect(preview.locator('.sugar-steps')).toContainText('商品を選ぶ');
    await expect(preview.locator('.sugar-steps__number')).toHaveText(['1', '2', '3', '4']);

    await page.getByTestId('publish-page').click();
    await page.getByTestId('confirm-publish-page').click();
    await expect(page.getByRole('status').filter({ hasText: '公開しました' })).toBeVisible();

    // ショップ（スマホの幅）: 同じ並びで出て、横にはみ出さない
    const shop = await page.context().newPage();
    await shop.setViewportSize({ width: 390, height: 844 });
    await shop.goto(storefront(tenant, '/pages/how-to'));
    expect(await types(shop.locator('main [data-section]'))).toEqual(['basic_text', 'basic_steps', 'basic_features', 'basic_faq', 'basic_cta']);
    await expect(shop.getByText('ご注文の流れ').first()).toBeVisible();
    expect(await overflow(shop)).toBeLessThanOrEqual(390);
    if (process.env.E2E_SCREENSHOTS) {
        await shop.screenshot({ path: 'test-results/screens/page-templates-how-to-390.png', fullPage: true });
    }
    await shop.close();
});

test('雛形から作り直すと、確かめてから下書きだけを置き換え、公開するまでショップは変わらない', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 2, theme: 'kinari' });

    await page.goto(`/tenants/${tenant.id}/theme`);
    await page.getByTestId('open-page-editor').click();
    await expect(page.getByRole('heading', { name: 'トップの編集' })).toBeVisible();
    const rows = page.getByTestId('section-row');
    const before = await types(rows);
    expect(before[0]).toBe('announcement');

    // 選んで、やめると何も変わらない
    await page.getByTestId('rebuild-from-template').click();
    await page.getByTestId('rebuild-menu').getByTestId('template-brand_story').click();
    await page.getByTestId('apply-template').click();
    await expect(page.getByText('「ブランドストーリー」の 6 セクションに置き換えます')).toBeVisible();
    await page.getByTestId('rebuild-menu').getByRole('button', { name: 'やめる' }).click();
    expect(await types(rows)).toEqual(before);

    // 置き換える: お知らせの帯とフッターは残り、間に雛形の並び
    await page.getByTestId('apply-template').click();
    await page.getByTestId('confirm-apply-template').click();
    await expect(page.getByRole('status').filter({ hasText: '雛形の並びに作り直しました' })).toBeVisible();
    await expect(rows).toHaveCount(8);
    expect(await types(rows)).toEqual(['announcement', 'hero', 'basic_story', 'basic_numbers', 'basic_story', 'basic_gallery', 'basic_cta', 'footer']);
    await expect(page.getByTestId('draft-state')).toContainText('下書き');
    const preview = page.frameLocator('[data-test="page-preview"]');
    await expect(preview.locator('.sugar-numbers')).toContainText('数字で見るお店');

    // ショップはまだ前のまま（公開していない）
    const shop = await page.context().newPage();
    await shop.setViewportSize({ width: 390, height: 844 });
    await shop.goto(storefront(tenant));
    await expect(shop.locator('main [data-section-type="basic_numbers"]')).toHaveCount(0);

    // 公開するとショップに出る。スマホの幅で横にはみ出さない
    await page.getByTestId('publish-page').click();
    await page.getByTestId('confirm-publish-page').click();
    await expect(page.getByRole('status').filter({ hasText: '公開しました' })).toBeVisible();
    await shop.reload();
    await expect(shop.locator('main [data-section-type="basic_numbers"]')).toHaveCount(1);
    await expect(shop.locator('main [data-section-type="basic_story"]')).toHaveCount(2);
    expect(await overflow(shop)).toBeLessThanOrEqual(390);
    if (process.env.E2E_SCREENSHOTS) {
        await shop.screenshot({ path: 'test-results/screens/page-templates-brand-story-kinari-390.png', fullPage: true });
        await page.getByTestId('rebuild-from-template').click();
        await page.getByTestId('rebuild-menu').screenshot({ path: 'test-results/screens/page-templates-rebuild.png' });
    }
    await shop.close();
});
