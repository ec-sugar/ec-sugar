import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// 公式テーマの見本の絵（theme.json の screenshot）を撮る。THEME_SHOTS=1 のときだけ動く。
// 管理画面の「デザイン」のテーマの札に出す。新しい店（商品 6 つ・無地の写真と在庫・区画は保存していない＝テーマの見本の文と店の名前）のトップを、
// 1280 × 1800 の画面を半分の解像度（640 × 900）で撮って plugins/theme-<名前>/screenshot.jpg に置く（見本はワイヤーの形で: 写真は無地の枠）。
// ヒーローの下の商品の並びまで入るよう縦に長く撮る（管理画面の札は縦長の枠に出す）。
// テーマの見た目を変えたら撮り直す: THEME_SHOTS=1 npx playwright test theme-shots
test.skip(!process.env.THEME_SHOTS, 'テーマの見本の絵を撮るときだけ（THEME_SHOTS=1）');

const THEMES = ['default', 'kinari', 'fashion', 'botanica'];

for (const theme of THEMES) {
    test(`テーマ ${theme} の見本の絵`, { tag: '@nightly' }, async ({ browser, makeTenant }) => {
        const tenant = makeTenant({ listings: 6, theme, showcase: true });
        const context = await browser.newContext({ viewport: { width: 1280, height: 1800 }, deviceScaleFactor: 0.5 });
        const page = await context.newPage();
        await page.goto(`http://${tenant.id}.localhost:${port}/`);
        await expect(page.locator('body')).toContainText('SAMPLE SHOP');
        // 見えている写真が読み終わってから撮る
        await page.waitForLoadState('networkidle');
        await page.evaluate(() => Promise.all([...document.images].filter((img) => img.loading !== 'lazy').map((img) => img.decode().catch(() => null))));
        await page.screenshot({ path: `plugins/theme-${theme}/screenshot.jpg`, type: 'jpeg', quality: 72 });
        await context.close();
    });
}
