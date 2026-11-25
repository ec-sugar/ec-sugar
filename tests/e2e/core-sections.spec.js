import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// 核の共通の区画。どのテーマでも「＋ 区画を足す」に出て、足したらすぐ見本の値で形になり、公開するとショップに出る。
// スマホの幅（390px）でも横にはみ出さず、よくある質問は押すと開く（<details>。JavaScript が無くても開く）。
// 全種類 × 全パターンの 390px の確かめは theme-patterns.spec.js（下ごしらえ --every-pattern がトップに並べる）

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

test('核の共通の区画を足して公開すると、ショップに出て、スマホの幅ではみ出さず、よくある質問が開く', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 2, theme: 'kinari' });

    await page.goto(`/tenants/${tenant.id}/theme`);
    await page.getByTestId('open-page-editor').click();
    await expect(page.getByRole('heading', { name: 'トップの編集' })).toBeVisible();
    const rows = page.getByTestId('section-row');
    const before = await rows.count();
    const preview = page.frameLocator('[data-test="page-preview"]');

    // 絵は核の語彙（見本帳の形）。足すと見本の値が入り、プレビューにすぐ形が出る
    await page.getByTestId('add-section').click();
    await expect(page.getByTestId('add-basic_faq-list').locator('svg')).toHaveAttribute('data-wireframe', 'faq-list');
    await expect(page.getByTestId('add-basic_product-split')).toBeVisible();
    await page.getByTestId('add-basic_faq-list').click();
    await expect(rows).toHaveCount(before + 1);
    await expect(preview.locator('.sugar-faq[data-pattern="list"]')).toContainText('送料はいくらですか？');

    for (const id of ['add-basic_testimonials-scroll', 'add-basic_features-three', 'add-basic_story-timeline', 'add-basic_cta-band']) {
        await page.getByTestId('add-section').click();
        await page.getByTestId(id).click();
    }
    await expect(rows).toHaveCount(before + 5);
    await expect(preview.locator('.sugar-voices')).toContainText('ここにお客さんの声が入ります');
    await expect(preview.locator('.sugar-features svg[data-icon]').first()).toBeVisible();
    await expect(preview.locator('.sugar-story[data-pattern="timeline"] li')).toHaveCount(3);
    await expect(preview.locator('.sugar-cta')).toContainText('呼びかけの見出しを入力してください');

    await page.getByTestId('publish-page').click();
    await page.getByTestId('confirm-publish-page').click();
    await expect(page.getByRole('status').filter({ hasText: '公開しました' })).toBeVisible();

    // ショップ（スマホの幅）: 足した区画が出て、横にはみ出さない
    const shop = await page.context().newPage();
    await shop.setViewportSize({ width: 390, height: 844 });
    await shop.goto(storefront(tenant));
    for (const type of ['basic_faq', 'basic_testimonials', 'basic_features', 'basic_story', 'basic_cta']) {
        await expect(shop.locator(`main [data-section-type="${type}"]`)).toHaveCount(1);
    }
    expect(await shop.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    // 横に流れる声は、自分の箱の中だけが横に動く（格子に崩れない・札は画面より狭い）
    const rail = shop.locator('.sugar-voices[data-pattern="scroll"] .sugar-voices__list');
    expect(await rail.evaluate((e) => getComputedStyle(e).display)).toBe('flex');
    expect(await rail.evaluate((e) => e.scrollWidth > e.clientWidth)).toBe(true);
    expect((await shop.locator('.sugar-voices__item').first().boundingBox())?.width ?? 999).toBeLessThan(330);

    // よくある質問は閉じた形で出て、押すと開いて答えが見える。もう 1 度押すと閉じる
    const question = shop.locator('.sugar-faq__item').first();
    await expect(question).not.toHaveAttribute('open', '');
    await expect(question.locator('.sugar-faq__answer')).toBeHidden();
    await question.locator('summary').click();
    await expect(question).toHaveAttribute('open', '');
    await expect(question.locator('.sugar-faq__answer')).toHaveText('ここに回答を書いてください。');
    await question.locator('summary').click();
    await expect(question.locator('.sugar-faq__answer')).toBeHidden();

    if (process.env.E2E_SCREENSHOTS) {
        await shop.screenshot({ path: 'test-results/screens/core-sections-kinari-390.png', fullPage: true });
        await shop.setViewportSize({ width: 1440, height: 1000 });
        await shop.screenshot({ path: 'test-results/screens/core-sections-kinari-1440.png', fullPage: true });
        await page.getByTestId('add-section').click();
        await page.getByTestId('add-group-basic_testimonials').scrollIntoViewIfNeeded();
        await page.getByTestId('add-section-menu').screenshot({ path: 'test-results/screens/core-sections-picker.png' });
    }
    await shop.close();
});
