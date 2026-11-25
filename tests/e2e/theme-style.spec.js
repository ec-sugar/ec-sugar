import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// 店の見た目（ADR 0097）。ページの編集の画面の左の「見た目」の札で、テーマが用意した色の組・主な色・字の組・角の丸みを選ぶ。
// 変えると下書きに保存し、真ん中のプレビューの CSS だけを読み直す（ページは読み直さない）。公開するとショップの色が変わる。
// テーマは角砂糖（白と黒が既定、藍の組がある）

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;
const accentOf = (target) => target.locator('html').evaluate((html) => getComputedStyle(html).getPropertyValue('--color-accent').trim().toLowerCase());

test('「見た目」の札で色の組を替えるとプレビューの色が変わり、公開するとショップの色が変わる', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, theme: 'default' });

    await page.goto(`/tenants/${tenant.id}/theme/pages/home`);
    await expect(page.getByRole('heading', { name: 'トップの編集' })).toBeVisible();
    const preview = page.frameLocator('[data-test="page-preview"]');
    await expect(preview.locator('[data-section]').first()).toBeVisible();
    await expect.poll(() => accentOf(preview)).toBe('#18181b');

    // 「見た目」の札。プレビューを読み直さずに CSS だけを替える（枠の中に印を付けて、残っていることを確かめる）
    await page.getByTestId('tab-look').click();
    await expect(page.getByTestId('style-panel')).toBeVisible();
    await preview.locator('html').evaluate((html) => html.setAttribute('data-still-here', '1'));
    await page.getByTestId('palette-indigo').click();
    await expect.poll(() => accentOf(preview)).toBe('#3451b2');
    await expect(page.getByTestId('draft-state')).toHaveText('下書き（まだ公開していません）');
    await expect(preview.locator('html')).toHaveAttribute('data-still-here', '1');

    // 字の組と角の丸みは外枠の印で受ける。主な色は自分で決められ、「組の色に戻す」で組の色へ
    await page.getByTestId('font-mincho').check();
    await expect(preview.locator('html')).toHaveAttribute('data-sugar-font', 'mincho');
    await page.getByTestId('accent-hex').fill('1e3a8a');
    await expect.poll(() => accentOf(preview)).toBe('#1e3a8a');
    await page.getByTestId('accent-reset').click();
    await expect.poll(() => accentOf(preview)).toBe('#3451b2');
    // 地に近い色は知らせる（保存は止めない）
    await page.getByTestId('accent-hex').fill('fafafa');
    await expect(page.getByTestId('style-warning-accent_on_canvas')).toBeVisible();
    await expect.poll(() => accentOf(preview)).toBe('#fafafa');
    await page.getByTestId('accent-reset').click();
    await expect(page.getByTestId('style-warning-accent_on_canvas')).toHaveCount(0);
    await expect.poll(() => accentOf(preview)).toBe('#3451b2');

    // 下書きはまだショップに出ていない
    const shop = await page.context().newPage();
    await shop.goto(storefront(tenant));
    await expect.poll(() => accentOf(shop)).toBe('#18181b');
    await expect(shop.locator('link[data-sugar-theme-style]')).toHaveCount(0);

    // 公開すると、ショップがテーマの CSS の後に小さな CSS を読み、藍になる
    await page.getByTestId('publish-page').click();
    await page.getByTestId('confirm-publish-page').click();
    await expect(page.getByRole('status').filter({ hasText: '公開しました' })).toBeVisible();
    await expect(page.getByTestId('draft-state')).toHaveText('公開中の内容と同じ');
    await shop.reload();
    await expect(shop.locator('link[data-sugar-theme-style]')).toHaveCount(1);
    await expect.poll(() => accentOf(shop)).toBe('#3451b2');
    await expect(shop.locator('html')).toHaveAttribute('data-sugar-font', 'mincho');
    await shop.close();

    if (process.env.E2E_SCREENSHOTS) {
        await page.screenshot({ path: 'test-results/screens/theme-style.png', fullPage: true });
    }
});
