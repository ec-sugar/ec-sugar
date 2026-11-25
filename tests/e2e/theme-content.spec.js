import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// デザインのテーマの画面。上に今のテーマの大きな札（見本の絵・名前とバージョン・公開の状態・編集する・公開する）、
// 下にほかのテーマの札（試しに編集する・このテーマにする）。区画の中身はここでは入れず、ページの編集の画面だけで入れる

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

async function expectNoOverflow(page) {
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(0);
}

test('今のテーマの札に見本の絵と状態が出て、編集するでページの編集の画面へ、公開するでショップに出る', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, theme: 'kinari' });
    await page.goto(`/tenants/${tenant.id}`);
    await page.getByRole('navigation', { name: '店のメニュー' }).getByRole('link', { name: 'デザイン' }).click();
    await expect(page.getByRole('heading', { name: 'デザイン', level: 1 })).toBeVisible();

    // 区画の欄はこの画面に無い（中身はページの編集の画面だけで入れる）
    await expect(page.getByTestId('field-hero-heading')).toHaveCount(0);
    await expect(page.getByTestId('save-draft')).toHaveCount(0);

    const current = page.getByTestId('current-theme');
    await expect(current.getByTestId('theme-label')).toHaveText('公式テーマ 1（kinari）');
    await expect(current.getByTestId('theme-version')).toHaveText('バージョン 0.1.0');
    // 見本の絵はテーマのパッケージの中の絵を、管理画面の自分のオリジンから読む
    const shot = current.getByTestId('theme-shot').locator('img');
    await expect.poll(() => shot.evaluate((img) => img.complete && img.naturalWidth)).toBeGreaterThan(0);
    expect(new URL(await shot.evaluate((img) => img.currentSrc)).pathname).toBe('/api/themes/kinari/screenshot');
    await expect(current.getByTestId('current-theme-state')).toHaveText('まだ公開していません');
    await expect(page.getByTestId('publish-theme')).toBeDisabled();

    // 編集する: ページの編集の画面のトップ（このテーマ）へ。お知らせの帯を入れると下書きに保存される
    await page.getByTestId('open-page-editor').click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/theme/pages/home\\?theme=kinari$`));
    await expect(page.getByRole('heading', { name: 'トップの編集' })).toBeVisible();
    await page.getByTestId('field-0-text').fill('今週は送料無料');
    await expect(page.frameLocator('[data-test="page-preview"]').getByTestId('announcement')).toHaveText('今週は送料無料');
    await page.getByTestId('editor-back').click();

    // 戻ると「公開していない変更」があるので公開できる（確かめてから）。公開するとショップに出る
    await expect(page.getByRole('heading', { name: 'デザイン', level: 1 })).toBeVisible();
    await expect(page.getByTestId('publish-theme')).toBeEnabled();
    await page.getByTestId('publish-theme').click();
    await page.getByTestId('confirm-publish-theme').click();
    await expect(page.getByRole('status').filter({ hasText: '公開しました' })).toBeVisible();
    await expect(current.getByTestId('current-theme-state')).toHaveText('公開中');
    await expect(current.getByTestId('current-theme-note')).toContainText('に公開しました');
    await expect(page.getByTestId('publish-theme')).toBeDisabled();

    await page.goto(storefront(tenant));
    await expect(page.getByTestId('announcement')).toHaveText('今週は送料無料');
});

test('ほかのテーマを試しに編集でき、このテーマにすると今のテーマが替わる', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, theme: 'kinari' });
    await page.goto(`/tenants/${tenant.id}/theme`);
    await expect(page.getByTestId('current-theme').getByTestId('theme-label')).toHaveText('公式テーマ 1（kinari）');

    // ほかのテーマの札（今のテーマは出さない）。見本の絵の無いテーマ（テスト用の sample）は無地の枠
    const others = page.getByTestId('other-themes');
    await expect(others.getByTestId('theme-kinari')).toHaveCount(0);
    const fashion = others.getByTestId('theme-fashion');
    await expect(fashion.getByTestId('theme-label')).toHaveText('公式テーマ 2（fashion）');
    // 既定のテーマの名前は文書・公式サイトと同じ「角砂糖」
    await expect(others.getByTestId('theme-default').getByTestId('theme-label')).toHaveText('角砂糖');
    await expect(fashion.getByTestId('theme-shot').locator('img')).toHaveAttribute('src', /^\/api\/themes\/fashion\/screenshot\?v=/);
    await expect(others.getByTestId('theme-sample').getByTestId('theme-shot-empty')).toBeVisible();

    // 試しに編集する: そのテーマの下書きをページの編集の画面で（ショップでは使っていないと出る）
    await fashion.getByTestId('try-theme').click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/theme/pages/home\\?theme=fashion$`));
    await expect(page.getByText('テーマ「公式テーマ 2（fashion）」（ショップでは使っていません）')).toBeVisible();
    await page.getByTestId('editor-back').click();

    // このテーマにする: 確かめてから。やめると何も変わらない
    await fashion.getByTestId('choose-theme').click();
    await expect(fashion.getByText('ショップのデザインがこのテーマに切り替わります。')).toBeVisible();
    await fashion.getByRole('button', { name: 'やめる' }).click();
    await expect(page.getByTestId('current-theme').getByTestId('theme-label')).toHaveText('公式テーマ 1（kinari）');

    await fashion.getByTestId('choose-theme').click();
    await fashion.getByTestId('confirm-choose-theme').click();
    await expect(page.getByRole('status')).toContainText('ショップのテーマを「公式テーマ 2（fashion）」にしました');
    await expect(page.getByTestId('current-theme').getByTestId('theme-label')).toHaveText('公式テーマ 2（fashion）');
    await expect(others.getByTestId('theme-fashion')).toHaveCount(0);
    await expect(others.getByTestId('theme-kinari')).toBeVisible();

    await page.goto(storefront(tenant));
    await expect(page.locator('body')).toHaveAttribute('data-theme', 'fashion');
});

test('閲覧のスタッフには編集・公開・選び替えのボタンが出ない', async ({ page, staff, makeTenant }) => {
    const tenant = makeTenant({ theme: 'kinari' });
    await staff(tenant, 'read');
    await page.goto(`/tenants/${tenant.id}/theme`);
    await expect(page.getByTestId('current-theme').getByTestId('theme-label')).toHaveText('公式テーマ 1（kinari）');
    await expect(page.getByTestId('themes-read-only')).toBeVisible();
    await expect(page.getByTestId('open-page-editor')).toHaveCount(0);
    await expect(page.getByTestId('publish-theme')).toHaveCount(0);
    await expect(page.getByTestId('other-themes').getByTestId('theme-fashion')).toBeVisible();
    await expect(page.getByTestId('try-theme')).toHaveCount(0);
    await expect(page.getByTestId('choose-theme')).toHaveCount(0);
});

test('スマホの幅（390px）でも、テーマの札が横にはみ出さない', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ theme: 'kinari' });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/tenants/${tenant.id}/theme`);
    await expect(page.getByTestId('current-theme').getByTestId('theme-label')).toBeVisible();
    await expect(page.getByTestId('other-themes').getByTestId('theme-fashion')).toBeVisible();
    await expectNoOverflow(page);
    // 選び替えの問いを開いても収まる
    await page.getByTestId('theme-fashion').getByTestId('choose-theme').click();
    await expect(page.getByTestId('confirm-choose-theme')).toBeVisible();
    await expectNoOverflow(page);

    if (process.env.E2E_SCREENSHOTS) {
        await page.screenshot({ path: 'test-results/screens/theme-content-390.png', fullPage: true });
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.reload();
        await expect(page.getByTestId('other-themes').getByTestId('theme-fashion')).toBeVisible();
        await page.screenshot({ path: 'test-results/screens/theme-content-admin.png', fullPage: true });
    }
});
