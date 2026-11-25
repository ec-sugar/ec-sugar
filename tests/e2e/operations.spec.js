import { expect, runFixture, test } from './support/fixtures.js';

// 運営全体の様子（ADR 0042）: オーナーだけ。困りごとが先に出て、無ければ 1 行。店の一覧に困りごとの数。
// 同じ DB でほかの場面が同時に店を作るので、確かめは自分の店の ID で絞る（全体の数の値には頼らない）

test('オーナーには運営の様子が出て、店の困りごとが上の知らせと店の一覧に出る', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ stray: true });
    runFixture('collect', tenant.id);

    // 店の切り替えの窓の「運営全体」から開く
    await page.goto(`/tenants/${tenant.id}`);
    await page.getByRole('button', { name: /店の切り替えと運営全体/ }).click();
    await page.getByTestId('nav-operations').click();
    await expect(page).toHaveURL(/\/operations$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('運営ダッシュボード');

    // 要対応に、この店の返金が要る支払い（押すと店のホームへ）
    const issue = page.getByTestId('ops-urgent').getByRole('link', { name: `「${tenant.name}」に返金が必要な支払いが 1 件あります` });
    await expect(issue).toBeVisible();
    await expect(page.getByTestId('ops-no-issues')).toHaveCount(0);

    // 数・売上の動き（30 本の棒）・機械の調子（まだ見ていないものは正直に）
    await expect(page.getByTestId('ops-stats')).toBeVisible();
    await expect(page.getByTestId('daily-bars').locator('rect')).toHaveCount(30);
    await expect(page.getByTestId('health-schedule')).toHaveAttribute('data-status', 'ok');
    await expect(page.getByTestId('health-certificates')).toContainText('未確認');

    // 店の一覧: この店は公開中・今月 1 件（¥1,500）・困りごと 1 件
    const row = page.locator(`[data-test="ops-shop"][data-tenant="${tenant.id}"]`);
    await expect(row).toContainText('公開中');
    await expect(row).toContainText('¥1,500');
    await expect(row.getByTestId('ops-shop-issues')).toHaveText('1 件');

    await issue.click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}$`));
    await expect(page.getByTestId('stray-notice')).toBeVisible();
});

test('困りごとが無いときは「困っていることはありません」の 1 行だけ', { tag: '@nightly' }, async ({ page, operator }) => {
    void operator;
    // 同じ DB のほかの場面の店の困りごとが混ざらないよう、応答を差し替えて画面の出し方だけを確かめる
    const days = Array.from({ length: 30 }, (_, i) => ({ day: `2026-10-${String(i + 1).padStart(2, '0')}`, orders: i % 3, sales: (i % 3) * 1200 }));
    await page.route('**/api/operations', (route) => route.fulfill({
        json: {
            currency: 'JPY',
            shops: { total: 1, open: 1, preparing: 0 },
            month: { orders: 30, sales: 36000, test_orders: 0 },
            daily: days,
            version: { revision: null, short: null, date: null },
            health: {
                schedule: { status: 'ok', at: '2026-10-30T12:00:00+09:00' },
                queue: { status: 'ok', waiting: 0, failed: 0 },
                mail: { status: 'ok', stuck: 0 },
                chain: { status: 'ok', broken: 0, unchecked: 0, checked_at: '2026-10-30T03:40:00+09:00' },
                certificates: { status: 'unknown' },
                disk: { status: 'unknown' },
                updates: { status: 'unknown' },
            },
            issues: [],
            tenants: [{ id: 'calm', name: '静かな商店', status: 'open', month: { orders: 30, sales: 36000 }, issues: 0, collected_at: '2026-10-30T12:00:00+09:00' }],
            tenants_total: 1,
        },
    }));
    await page.goto('/operations');

    await expect(page.getByTestId('ops-no-issues')).toHaveText('要対応の問題はありません。');
    await expect(page.getByTestId('ops-urgent')).toHaveCount(0);
    await expect(page.getByTestId('ops-soon')).toHaveCount(0);
    await expect(page.getByTestId('ops-month-sales')).toHaveText('¥36,000');
    await expect(page.locator('[data-test="ops-shop"][data-tenant="calm"]').getByTestId('ops-shop-issues')).toHaveText('—');
});

// 壊れたら知らせる仕組み（ADR 0043）: 店のホームの上のオーナーへの赤い帯と、運営の様子のエラーの節。
// エラーと要対応は全部の店にまたがる数で、同じ DB のほかの場面が起こしたものも混ざるので、応答を差し替えて画面の出し方だけを確かめる（数え方は PHP のテスト）
// APP_KEY の控え: 本番で、押すと「記録できませんでした」になった（2026-11-19）。答える経路は直前の本人確認が要り、
// 画面がそのやり直しを通していなかった。サーバーのテストは経路の決まりを確かめていたが、画面を通す場面が無かった。
// ここでは答える送信だけ本物のサーバーへ通す（一覧の応答は差し替える。同じ DB のほかの場面と、ホストの報告の有無に頼らない）
test('APP_KEY の控えの知らせは、本人確認をはさんで「控えを取りました」と答えると消える', async ({ page, operator }) => {
    void operator;
    let answered = false;
    const body = () => ({
        currency: 'JPY',
        shops: { total: 0, open: 0, preparing: 0 },
        month: { orders: 0, sales: 0, test_orders: 0 },
        daily: Array.from({ length: 30 }, (_, i) => ({ day: `2026-10-${String(i + 1).padStart(2, '0')}`, orders: 0, sales: 0 })),
        version: { revision: null, short: null, date: null },
        health: {
            schedule: { status: 'ok', at: '2026-10-30T12:00:00+09:00' },
            queue: { status: 'ok', waiting: 0, failed: 0 },
            mail: { status: 'ok', stuck: 0 },
            chain: { status: 'ok', broken: 0, unchecked: 0, checked_at: '2026-10-30T03:40:00+09:00' },
            certificates: { status: 'unknown' },
            disk: { status: 'unknown' },
            updates: { status: 'unknown' },
        },
        issues: answered ? [] : [{ key: 'app_key_backup_unconfirmed', level: 'soon', count: 1, tenant: null }],
        tenants: [],
        tenants_total: 0,
    });
    await page.route('**/api/operations', (route) => route.fulfill({ json: body() }));
    page.on('response', (response) => {
        if (response.url().endsWith('/api/operations/app-key-backup') && response.status() === 200) {
            answered = true;
        }
    });
    await page.goto('/operations');

    const soon = page.getByTestId('ops-soon');
    await expect(soon).toContainText('復元のキットをまだ作っていません');

    // 押す → 確かめの一言 → 本人確認（サーバーが 423 を返す）→ やり直して記録される
    await soon.getByTestId('ops-app-key-backup').click();
    await expect(soon).toContainText('サーバーの外に保管しましたか');
    await soon.getByTestId('confirm-ops-app-key-backup').click();
    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();

    await expect(page.getByTestId('ops-app-key-backup-result')).toHaveText('APP_KEY のバックアップを取ったことを記録しました。');
    await expect(page.getByTestId('ops-soon')).toHaveCount(0);
    expect(answered).toBe(true);
});

test('オーナーには店のホームの上に動きの問題の帯が出て、運営の様子のエラーの節へ移れる', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant();
    await page.route('**/api/operations/alerts', (route) => route.fulfill({ json: { urgent: 2 } }));
    await page.route('**/api/operations', (route) => route.fulfill({
        json: {
            currency: 'JPY',
            shops: { total: 1, open: 1, preparing: 0 },
            month: { orders: 0, sales: 0, test_orders: 0 },
            daily: Array.from({ length: 30 }, (_, i) => ({ day: `2026-10-${String(i + 1).padStart(2, '0')}`, orders: 0, sales: 0 })),
            version: { revision: null, short: null, date: null },
            health: {
                schedule: { status: 'ok', at: '2026-10-30T12:00:00+09:00' },
                queue: { status: 'ok', waiting: 0, failed: 0 },
                mail: { status: 'ok', stuck: 0 },
                chain: { status: 'ok', broken: 0, unchecked: 0, checked_at: '2026-10-30T03:40:00+09:00' },
                certificates: { status: 'unknown' },
                disk: { status: 'unknown' },
                updates: { status: 'unknown' },
            },
            issues: [{ key: 'incidents', level: 'urgent', count: 1, tenant: null }],
            tenants: [{ id: tenant.id, name: tenant.name, status: 'open', month: { orders: 0, sales: 0 }, issues: 0, collected_at: '2026-10-30T12:00:00+09:00' }],
            tenants_total: 1,
            errors: [
                { id: 'a'.repeat(64), kind: 'http_error', exception: 'Illuminate\\Database\\QueryException', location: 'app/Storefront/Orders/SendOrderMail.php:69', tenant: { id: tenant.id, name: tenant.name }, count: 12, first_at: '2026-10-30T10:00:00+09:00', last_at: '2026-10-30T11:58:00+09:00' },
                { id: 'b'.repeat(64), kind: 'command_failed', exception: 'Exception', location: 'artisan operations:collect', tenant: null, count: 1, first_at: '2026-10-29T03:40:00+09:00', last_at: '2026-10-29T03:40:00+09:00' },
            ],
            errors_total: 2,
        },
    }));

    await page.goto(`/tenants/${tenant.id}`);
    const banner = page.getByTestId('ops-alert-banner');
    await expect(banner).toContainText('システムに問題があります（要対応 2 件）');

    await banner.getByTestId('ops-alert-link').click();
    await expect(page).toHaveURL(/\/operations$/);
    await expect(page.getByTestId('ops-urgent')).toContainText('この 24 時間に起きたエラーが 1 件あります');

    // エラーの節: 最後に起きた順に、何が（短い名前と種類）・どこで・店・回数
    const rows = page.getByTestId('ops-errors').getByTestId('ops-error');
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(0)).toContainText('QueryException');
    await expect(rows.nth(0)).toContainText('画面や API のエラー');
    await expect(rows.nth(0)).toContainText('app/Storefront/Orders/SendOrderMail.php:69');
    await expect(rows.nth(0)).toContainText(tenant.name);
    await expect(rows.nth(0)).toContainText('12 回');
    await expect(rows.nth(1)).toContainText('定期の処理の失敗');
    await expect(rows.nth(1)).toContainText('artisan operations:collect');
    await expect(page.getByTestId('ops-errors-empty')).toHaveCount(0);
});

test('スタッフには動きの問題の帯を出さない', { tag: '@nightly' }, async ({ page, staff, makeTenant }) => {
    const tenant = makeTenant();
    let asked = false;
    await page.route('**/api/operations/alerts', (route) => {
        asked = true;

        return route.fulfill({ json: { urgent: 3 } });
    });
    await staff(tenant, 'money');
    await page.goto(`/tenants/${tenant.id}`);
    await expect(page.getByTestId('tenant-name')).toBeVisible();
    await expect(page.getByTestId('home-stats').or(page.getByTestId('setup-step-listing')).first()).toBeVisible();
    await expect(page.getByTestId('ops-alert-banner')).toHaveCount(0);
    // スタッフの画面は要対応の件数を聞きにも行かない（聞いてもサーバーが 403 で断る）
    expect(asked).toBe(false);
});

test('スタッフには運営の様子を出さず、開いてもサーバーが断る',async ({ page, staff, makeTenant }) => {
    const tenant = makeTenant();
    await staff(tenant, 'money');
    await page.goto(`/tenants/${tenant.id}`);
    await page.getByRole('button', { name: /店の切り替えと運営全体/ }).click();
    await expect(page.getByTestId('shop-switcher-panel')).toBeVisible();
    await expect(page.getByTestId('nav-operations')).toHaveCount(0);

    await page.goto('/operations');
    await expect(page.getByTestId('error-state')).toContainText('この画面を見る権限がありません');
    await expect(page.getByTestId('ops-stats')).toHaveCount(0);
});

test('狭い画面（390px）でも横にはみ出さない', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ stray: true });
    runFixture('collect', tenant.id);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/operations');
    await expect(page.locator(`[data-test="ops-shop"][data-tenant="${tenant.id}"]`)).toBeVisible();
    await expect(page.getByTestId('daily-bars')).toBeVisible();

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);

    if (process.env.E2E_SCREENSHOTS) {
        await page.screenshot({ path: 'test-results/screens/operations-390.png', fullPage: true });
    }
});

test('写し: 運営の様子', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    test.skip(!process.env.E2E_SCREENSHOTS, '写しは E2E_SCREENSHOTS=1 のときだけ');
    void operator;
    const tenant = makeTenant({ stray: true });
    runFixture('collect', tenant.id);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto('/operations');
    await expect(page.getByTestId('ops-urgent')).toBeVisible();
    await page.screenshot({ path: 'test-results/screens/operations.png', fullPage: true });
});
