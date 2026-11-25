import { expect, test } from './support/fixtures.js';

// 店の選び方とホーム

test('店の切り替えから店の一覧へ移って店を選び、ホームと記録の連鎖を確かめる（名前の HTML は文字として出る）', async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 3, htmlName: true });
    // 見られる店が 2 つ以上のときの画面（1 つだとホームがその店へ直接入る）。ほかの場面が作った店に頼らない
    makeTenant({ listings: 0 });

    await page.goto(`/tenants/${tenant.id}`);
    await page.getByTestId('tenant-switcher').click();
    await page.getByTestId('shop-switcher-panel').getByTestId('nav-tenants').click();
    const row = page.getByTestId('tenant-row').filter({ hasText: tenant.id });
    await expect(row).toContainText(tenant.name);
    await row.getByRole('link').click();

    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}$`));
    await expect(page.getByTestId('tenant-name')).toContainText(tenant.name);
    // この店のデータで決まった開設の手順（商品が公開中なので「最初の商品」は済）
    await page.getByTestId('setup-toggle').click();
    await expect(page.getByTestId('setup-step-listing')).toHaveAttribute('data-done', 'true');
    // 名前の HTML が実行されていない
    expect(await page.evaluate(() => window.__xss)).toBeUndefined();
    await expect(page.locator('main img')).toHaveCount(0);

    // 店の切り替えにはこの店が選ばれている
    await page.getByTestId('tenant-switcher').click();
    await expect(page.getByTestId('switch-shop').and(page.locator('[aria-current]'))).toHaveCount(1);
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('shop-switcher-panel')).toHaveCount(0);

    await page.goto(`/tenants/${tenant.id}/settings/records`);
    await page.getByTestId('verify-events').click();
    await expect(page.getByTestId('verify-result')).toContainText('改竄は見つかりませんでした');

    // 存在しないテナントは「見つかりません」
    await page.goto('/tenants/e2enone');
    await expect(page.getByRole('alert')).toContainText('見つかりませんでした');
});

test('管理画面から店頭を新しいタブで開ける（メニューと概要の見出し）', { tag: '@nightly' }, async ({ page, context, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1 });
    await page.goto(`/tenants/${tenant.id}`);

    const link = page.getByTestId('open-storefront');
    await expect(link).toHaveAttribute('target', '_blank');
    await expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    const [shop] = await Promise.all([context.waitForEvent('page'), link.click()]);
    await shop.waitForLoadState();
    expect(new URL(shop.url()).hostname).toBe(`${tenant.id}.localhost`);
    await expect(shop.getByTestId('product-card').first()).toBeVisible();
    await shop.close();

    await expect(page.getByTestId('open-storefront-header')).toHaveAttribute('href', new RegExp(`^http://${tenant.id}\\.localhost:\\d+/$`));
});

test('見られる店が 1 つなら、ホームからその店の画面へ直接入る（既定は 1 システム・1 店舗）', async ({ page, staff, makeTenant }) => {
    const tenant = makeTenant();
    await staff(tenant, 'read');

    await page.goto('/');
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}$`));
    // 選ぶ店が無いので、切り替えのボタンは店の名前と運営全体だけ（店の一覧は出さない）。パンくずにも「店の一覧」の段を出さない
    await expect(page.getByTestId('tenant-switcher')).toHaveCount(0);
    await expect(page.getByTestId('shop-name')).toContainText(tenant.name);
    await page.getByTestId('shop-name').click();
    await expect(page.getByTestId('nav-login-history')).toBeVisible();
    await expect(page.getByTestId('nav-tenants')).toHaveCount(0);
    await expect(page.getByTestId('switch-shop')).toHaveCount(0);
    await page.keyboard.press('Escape');
    await page.goto(`/tenants/${tenant.id}/listings`);
    await expect(page.getByRole('navigation', { name: '現在地' }).getByRole('link', { name: '店の一覧' })).toHaveCount(0);
    // 店の外の画面（パスキー）でも、その店のメニューが出る
    await page.goto('/passkeys');
    await expect(page.getByRole('navigation', { name: '店のメニュー' }).getByRole('link', { name: '商品', exact: true })).toBeVisible();

    // ログインし直しても、戻り先が無ければホーム（＝その店）へ
    await page.getByRole('button', { name: 'ログアウト' }).click();
    await page.getByTestId('login').click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}$`));
});

test('見られる店が 2 つ以上なら、ホームは店の一覧', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    makeTenant();
    makeTenant();

    await page.goto('/');
    await expect(page).toHaveURL(/\/tenants$/);
});
