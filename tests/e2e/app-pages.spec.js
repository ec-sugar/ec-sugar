import { existsSync } from 'node:fs';
import { expect, test } from './support/fixtures.js';

// App の画面（ADR 0051）。App が中身を渡し、核の部品が描く。定期便を使う店で、メニューの「App」の下の「定期便」→ 一覧（状態のタブ）→ 1 件 →
// お休みにする（確かめの文を読んでから押す）→ 状態が変わる。閲覧のスタッフには操作のボタンを出さない。App を使っていない店ではメニューに出ない。
// 一覧と 1 件の画面に、お客さんの名前・メール・届け先を出さない（API も返さない）。
// E2E_SCREENSHOTS=1 のときは写しも撮る（test-results/screens/app-records.png・app-record.png）

function mainMenu(page) {
    return page.getByRole('navigation', { name: '店のメニュー' });
}

async function expectNoPersonal(page) {
    for (const secret of ['定期 花子', 'teiki@example.com', '神宮前', '1500001', '09012345678']) {
        await expect(page.locator('main')).not.toContainText(secret);
    }
}

// 例は定期便の App。定期便の App が入っていない木（公開用の 1.0。ADR 0098）では、このファイルの場面を飛ばす
test.skip(!existsSync('plugins/plugin-subscription/composer.json'), '定期便の App が入っていない木（公開用の 1.0。ADR 0098）');

test('定期便を使う店で、メニューから一覧と 1 件を開き、確かめてからお休みにすると状態が変わる', async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ subscriptions: true });
    const { active, paused, listing, customer } = tenant.subscriptions;

    await page.goto(`/tenants/${tenant.id}`);
    // 「App」のすぐ下に「定期便」
    const menu = mainMenu(page);
    await expect(menu.getByTestId('nav-app-subscription')).toBeVisible();
    const labels = await menu.locator('a:not([data-test="open-storefront"])').allTextContents();
    expect(labels.map((label) => label.trim())[labels.map((label) => label.trim()).indexOf('App') + 1]).toBe('定期便');
    await menu.getByTestId('nav-app-subscription').click();

    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/apps/subscription$`));
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('定期便');
    await expect(menu.getByTestId('nav-app-subscription')).toHaveAttribute('aria-current', 'page');
    await expect(menu.getByTestId('nav-apps')).not.toHaveAttribute('aria-current', 'page');
    await expect(page).toHaveTitle(`定期便 — ${tenant.name} — EC-SUGAR 管理`);
    await expect(page.getByRole('navigation', { name: '現在地' }).getByRole('listitem').last()).toHaveText('定期便');
    // 「説明を見る」は定期便の説明のページの一覧の節へ
    await expect(page.getByTestId('help-link')).toHaveAttribute('href', new RegExp(`^/help/subscriptions\\?shop=${tenant.id}#list$`));

    // 状態のタブ（件数つき）。既定は先頭の「続いている」
    await expect(page.getByTestId('tab-active')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('tab-active')).toContainText('1');
    await expect(page.getByTestId('tab-paused')).toContainText('1');
    const row = page.locator(`[data-test="app-row"][data-record="${active}"]`);
    await expect(page.getByTestId('app-row')).toHaveCount(1);
    // 値の形ごと: 金額は「¥」の形（1,200 × 2 − 10%）、状態は札、お客さんは核の「お客さん」の画面へのリンク
    await expect(row.getByTestId('app-cell-listing')).toHaveText('定期便の品');
    await expect(row.getByTestId('app-cell-total')).toHaveText('¥2,160');
    await expect(row.getByTestId('app-cell-status')).toHaveText('継続中');
    await expect(row.getByTestId('app-cell-customer').getByRole('link')).toHaveAttribute('href', `/tenants/${tenant.id}/customers/${customer}`);
    await expectNoPersonal(page);

    // 絞り込みは URL に持つ（タブを押すとお休みの定期便だけ）
    await page.getByTestId('tab-paused').click();
    await expect(page).toHaveURL(/\?filter=paused$/);
    await expect(page.locator(`[data-test="app-row"][data-record="${paused}"]`)).toBeVisible();
    await page.getByTestId('tab-stopped').click();
    await expect(page.getByTestId('app-records-none')).toContainText('「停止中（お支払いができない）」の定期便はありません');
    await page.getByTestId('tab-active').click();
    await expect(row).toBeVisible();

    if (process.env.E2E_SCREENSHOTS) {
        await page.screenshot({ path: 'test-results/screens/app-records.png', fullPage: true });
    }

    // 1 件の画面: 見出しは商品、事実の並びに状態と商品へのリンク、回の履歴、押せる操作
    await row.getByTestId('app-cell-listing').getByRole('link').click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/apps/subscription/${active}$`));
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('定期便の品');
    await expect(page.getByRole('navigation', { name: '現在地' }).getByRole('link', { name: '定期便' })).toBeVisible();
    const facts = page.getByTestId('app-facts');
    await expect(facts).toContainText('継続中');
    await expect(facts.getByRole('link', { name: '定期便の品' })).toHaveAttribute('href', `/tenants/${tenant.id}/listings/${listing}`);
    await expect(page.getByTestId('app-table-row').first().getByTestId('app-cell-status')).toHaveText('予定');
    await expect(page.getByTestId('app-action-pause')).toBeVisible();
    await expect(page.getByTestId('app-action-cancel')).toBeVisible();
    await expectNoPersonal(page);

    // お休みにする: 1 回目で確かめの文が出て、2 回目で送る
    await page.getByTestId('app-action-pause').click();
    await expect(page.getByTestId('app-actions')).toContainText('お客さんにはメールでお知らせします');
    await page.getByTestId('confirm-app-action-pause').click();
    await expect(page.getByTestId('app-action-done')).toHaveText('「お休みにする」が済みました。');
    await expect(facts).toContainText('お休み');
    // お休みの間に押せるのは再開と解約
    await expect(page.getByTestId('app-action-pause')).toHaveCount(0);
    await expect(page.getByTestId('app-action-resume')).toBeVisible();

    if (process.env.E2E_SCREENSHOTS) {
        await page.screenshot({ path: 'test-results/screens/app-record.png', fullPage: true });
    }

    // 一覧に戻ると、お休みのタブに入っている
    await page.getByRole('navigation', { name: '現在地' }).getByRole('link', { name: '定期便' }).click();
    await expect(page.getByTestId('tab-paused')).toContainText('2');
});

test('閲覧のスタッフは定期便を見られるが、操作のボタンは出ない（サーバーも断る）', async ({ page, staff, makeTenant }) => {
    const tenant = makeTenant({ subscriptions: true });
    const { active } = tenant.subscriptions;
    await staff(tenant, 'read');

    await page.goto(`/tenants/${tenant.id}/apps/subscription/${active}`);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('定期便の品');
    await expect(page.getByTestId('app-actions-no-permission')).toBeVisible();
    await expect(page.getByTestId('app-action-pause')).toHaveCount(0);
    await expect(mainMenu(page).getByTestId('nav-app-subscription')).toBeVisible();

    // 見た目だけでなく、サーバーも断る（閲覧の段階では 403）
    const status = await page.evaluate(async (path) => {
        const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length) ?? '');
        const response = await fetch(path, { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });

        return response.status;
    }, `/api/tenants/${tenant.id}/apps/subscription/records/${active}/actions/pause`);
    expect(status).toBe(403);
});

test('App を使っていない店では、メニューに App の画面が出ず、開いても見つからない。使うにするとメニューに出る', async ({ page, operator, makeTenant }) => {
    void operator;
    const plain = makeTenant();

    await page.goto(`/tenants/${plain.id}`);
    await expect(page.getByTestId('tenant-name')).toHaveText(plain.name);
    await expect(mainMenu(page).getByTestId('nav-apps')).toBeVisible();
    await expect(mainMenu(page).getByTestId('nav-app-subscription')).toHaveCount(0);

    await page.goto(`/tenants/${plain.id}/apps/subscription`);
    await expect(page.getByTestId('error-state')).toContainText('見つかりませんでした');

    // App の画面から「定期便」を使うにすると、読み込み直さなくてもメニューに出る（止めると消える）
    await mainMenu(page).getByTestId('nav-apps').click();
    const row = page.getByTestId('tenant-plugin-row').filter({ hasText: '定期便' });
    await row.getByTestId('tenant-plugin-toggle').click();
    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();
    await expect(row.getByTestId('tenant-plugin-state')).toHaveText('使う');
    await expect(mainMenu(page).getByTestId('nav-app-subscription')).toBeVisible();
    await mainMenu(page).getByTestId('nav-app-subscription').click();
    await expect(page.getByTestId('app-records-empty')).toContainText('まだ定期便はありません');
    await mainMenu(page).getByTestId('nav-apps').click();
    await row.getByTestId('tenant-plugin-toggle').click();
    await expect(row.getByTestId('tenant-plugin-state')).toHaveText('使わない');
    await expect(mainMenu(page).getByTestId('nav-app-subscription')).toHaveCount(0);
});
