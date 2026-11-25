import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// 公式テーマの区画のパターン（ADR 0039 区切り 4）。どの区画もレスポンシブで、スマホの幅（390px）でも横にはみ出さない。
// 下ごしらえ（--every-pattern）がトップに区画の種類 × パターンをすべて並べ、自由なページ /pages/about をメニューに載せる

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth);

for (const theme of ['fashion', 'botanica']) {
    test(`${theme} のトップと自由なページはスマホの幅で横にはみ出さない`, { tag: '@nightly' }, async ({ page, makeTenant }) => {
        const tenant = makeTenant({ listings: 6, theme, everyPattern: true });
        await page.setViewportSize({ width: 390, height: 844 });

        await page.goto(storefront(tenant));
        await expect(page.locator('body')).toHaveAttribute('data-theme', theme);
        // 区画がすべて描かれている（どれも押して選ぶ印を持つ）
        expect(await page.locator('main [data-section]').count()).toBeGreaterThan(15);
        await expect(page.locator('[data-test="product-rail"][data-pattern="scroll"]').first()).toBeVisible();
        expect(await overflow(page)).toBeLessThanOrEqual(390);

        // メニューの自由なページから開く（スマホでも同じメニューに載る）
        await page.getByTestId('nav-page').first().click();
        await expect(page).toHaveURL(storefront(tenant, '/pages/about'));
        await expect(page.getByTestId('free-page-title')).toHaveText('私たちについて');
        await expect(page.getByTestId('nav-page').first()).toHaveAttribute('aria-current', 'page');
        expect(await page.locator('main [data-section]').count()).toBeGreaterThan(10);
        expect(await overflow(page)).toBeLessThanOrEqual(390);

        if (process.env.E2E_SCREENSHOTS) {
            await page.goto(storefront(tenant));
            await page.screenshot({ path: `test-results/screens/${theme}-patterns-mobile.png`, fullPage: true });
            await page.setViewportSize({ width: 1440, height: 1000 });
            await page.screenshot({ path: `test-results/screens/${theme}-patterns-desktop.png`, fullPage: true });
            await page.goto(storefront(tenant, '/pages/about'));
            await page.screenshot({ path: `test-results/screens/${theme}-free-page-desktop.png`, fullPage: true });
        }
    });
}

// 角砂糖と kinari。はみ出したときは右端にある要素（区画の ID・パターン）を挙げて落とす。
// 横に流れる並びの中の札は、並びの箱の中でスクロールするので右へ出ていてよい（箱が位置の基準を持たないと、読み上げ用の sr-only が箱の外に出てページが広がる）
async function expectNoOverflow(page, where) {
    const found = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        rightmost: [...document.querySelectorAll('body *')]
            .map((element) => {
                const section = element.closest('[data-section]');
                return [Math.round(element.getBoundingClientRect().right), `${section?.dataset.section ?? '-'} ${section?.dataset.pattern ?? section?.querySelector('[data-pattern]')?.dataset.pattern ?? ''} <${element.tagName.toLowerCase()} class="${String(element.className?.baseVal ?? element.className).slice(0, 80)}">`];
            })
            .sort((a, b) => b[0] - a[0])
            .slice(0, 5)
            .map(([right, what]) => `${right}px ${what}`),
    }));
    expect(found.scrollWidth, `${where}:\n${found.rightmost.join('\n')}`).toBeLessThanOrEqual(390);
}

for (const theme of ['default', 'kinari']) {
    test(`${theme} のどのパターンもスマホの幅で横にはみ出さず、自由なページをメニューから開ける`, { tag: '@nightly' }, async ({ page, makeTenant }) => {
        const tenant = makeTenant({ listings: 6, theme, everyPattern: true });
        await page.setViewportSize({ width: 390, height: 844 });

        // トップ: すべての区画を描き、横にはみ出さない
        await page.goto(storefront(tenant));
        expect(await page.locator('main [data-section]').count()).toBeGreaterThan(10);
        await expect(page.locator('[data-pattern="scroll"]').first()).toBeVisible();
        await expectNoOverflow(page, 'トップ');
        if (process.env.E2E_SCREENSHOTS) {
            await page.screenshot({ path: `test-results/screens/theme-patterns-${theme}-390.png`, fullPage: true });
        }

        // メニューから自由なページへ（kinari はスマホではメニューを開いてから）
        if (theme === 'kinari') {
            await page.getByTestId('menu').locator('summary').click();
            await page.getByTestId('menu-nav-page').filter({ hasText: '私たちについて' }).click();
        } else {
            await page.getByTestId('nav-page').filter({ hasText: '私たちについて' }).click();
        }
        await expect(page).toHaveURL(storefront(tenant, '/pages/about'));
        await expect(page.getByTestId('free-page-title')).toContainText('私たちについて');
        expect(await page.locator('main [data-section]').count()).toBeGreaterThan(5);
        await expectNoOverflow(page, '自由なページ');
        if (process.env.E2E_SCREENSHOTS) {
            await page.screenshot({ path: `test-results/screens/theme-patterns-${theme}-page-390.png`, fullPage: true });
            await page.setViewportSize({ width: 1440, height: 1000 });
            await page.goto(storefront(tenant));
            await page.screenshot({ path: `test-results/screens/theme-patterns-${theme}-1440.png`, fullPage: true });
        }
    });
}
