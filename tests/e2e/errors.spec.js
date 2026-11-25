import { expect, test } from './support/fixtures.js';

// 画面の中身を出せないときの見せ方。応答の状態ごとに、何が起きたかの一言と次にやることを出し、
// サーバーの中の事情（例外の文・SQL）は出さない。403・500・503・通信の失敗は API の応答を差し替えて作る

test('読み込めないときは、状態ごとの一言と次の操作を出し、サーバーの中の文は出さない', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1 });
    const overview = `**/api/tenants/${tenant.id}/overview`;
    const state = page.getByTestId('error-state');

    // 店が無い・任されていない（404）
    await page.goto('/tenants/e2enone');
    await expect(state).toContainText('見つかりませんでした');
    await expect(state.getByRole('link', { name: '店の一覧へ' })).toBeVisible();

    for (const [status, title, action] of [
        [403, 'この画面を見る権限がありません', '店の一覧へ'],
        [500, 'サーバーで問題が起きました', '読み込み直す'],
        [503, '一時的に使えません', '読み込み直す'],
    ]) {
        await page.route(overview, (route) => route.fulfill({
            status,
            contentType: 'application/json',
            body: JSON.stringify({ message: 'SQLSTATE[42P01]: relation "secret_table" does not exist' }),
        }));
        await page.goto(`/tenants/${tenant.id}`);
        await expect(state).toContainText(title);
        await expect(state.getByRole(action === '読み込み直す' ? 'button' : 'link', { name: action })).toBeVisible();
        await expect(page.locator('main')).not.toContainText('SQLSTATE');
        await expect(page.locator('main')).not.toContainText('secret_table');
        await page.unroute(overview);
    }

    // 通信できない（応答が無い）
    await page.route(`**/api/tenants/${tenant.id}/listings*`, (route) => route.abort());
    await page.goto(`/tenants/${tenant.id}/listings`);
    await expect(state).toContainText('通信できませんでした');
    await page.unroute(`**/api/tenants/${tenant.id}/listings*`);

    // 読み込み直すと、戻った応答で画面が出る
    await page.route(overview, (route) => route.fulfill({ status: 503, contentType: 'application/json', body: '{"message":""}' }));
    await page.goto(`/tenants/${tenant.id}`);
    await expect(state).toContainText('一時的に使えません');
    await page.unroute(overview);
    await state.getByRole('button', { name: '読み込み直す' }).click();
    await expect(page.getByTestId('tenant-name')).toContainText(tenant.name);
    await expect(state).toHaveCount(0);
});

test('画面の中で知らない経路へ移ると「この画面はありません」を出す', { tag: '@nightly' }, async ({ page, operator }) => {
    void operator;
    await page.goto('/tenants');
    await expect(page.getByRole('heading', { name: '店の一覧', level: 1 })).toBeVisible();
    await page.evaluate(() => {
        history.pushState({}, '', '/tenants/x/y/z');
        dispatchEvent(new PopStateEvent('popstate'));
    });
    await expect(page.getByTestId('error-state')).toContainText('この画面はありません');
    await page.getByTestId('error-state').getByRole('link', { name: '店の一覧へ' }).click();
    await expect(page).toHaveURL(/\/tenants$/);
});
