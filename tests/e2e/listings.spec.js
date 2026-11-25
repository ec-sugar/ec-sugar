import { expect, test } from './support/fixtures.js';

// 商品と在庫。商品の登録と編集は 1 枚の長いフォーム。1 枚のフォームならではの場面は listing-form.spec.js

test('商品を登録・編集し、在庫を入庫・調整する（同時編集の衝突、在庫は負にならない）', async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 3 });
    await page.goto(`/tenants/${tenant.id}`);
    await page.getByTestId('open-listings').click();
    await expect(page.getByTestId('listing-row')).toHaveCount(tenant.listings);

    // 登録（品番は畳んだ「詳しい設定」の中）。下書きで保存しても画面は移らず、URL だけが編集の形になる
    await page.getByTestId('new-listing').click();
    await page.getByTestId('listing-title').fill('E2E の商品');
    await page.getByTestId('listing-amount').fill('1500');
    await page.getByTestId('listing-details-toggle').click();
    await page.getByTestId('listing-sku').fill('E2E-1');
    await page.getByTestId('save-draft').click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/listings/[0-9a-z]{26}$`));
    await expect(page.getByTestId('save-message')).toContainText('下書きで保存しました');
    await expect(page.getByText('バージョン 1')).toBeVisible();
    await expect(page.getByTestId('listing-status')).toHaveValue('draft');
    await expect(page.getByTestId('listing-sku')).toHaveValue('E2E-1');
    const listingPath = new URL(page.url()).pathname.replace('/tenants/', '/api/tenants/');

    // 編集
    await page.getByTestId('listing-title').fill('E2E の商品（改）');
    await page.getByTestId('save-listing').click();
    await expect(page.getByTestId('save-message')).toContainText('保存しました');
    await expect(page.getByText('バージョン 2')).toBeVisible();

    // 別の運営が先に保存した（同じバージョン 2 を読んだ別の画面からの保存）
    const other = await page.evaluate(async (path) => (await fetch(path, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ version: 2, title: '別の運営が付けた名前' }),
    })).status, listingPath);
    expect(other).toBe(200);

    // こちらの保存は断られ、上書きしない。読み直すと相手の変更が見える
    await page.getByTestId('listing-amount').fill('1800');
    await page.getByTestId('save-listing').click();
    await expect(page.getByTestId('save-outcome')).toContainText('別の操作で先に更新されています');
    await expect(page.getByTestId('save-listing')).toBeDisabled();
    await page.getByTestId('reload-listing').click();
    await expect(page.getByTestId('listing-title')).toHaveValue('別の運営が付けた名前');
    await expect(page.getByTestId('listing-amount')).toHaveValue('1500');
    await expect(page.getByText('バージョン 3')).toBeVisible();

    // 在庫: 入庫 5 → 調整 -10 は断られる → 調整 -2 で 3
    await page.getByTestId('op-receive').check();
    await page.getByTestId('stock-amount').fill('5');
    await page.getByTestId('apply-stock').click();
    await expect(page.getByTestId('stock-quantity').first()).toHaveText('5');
    await expect(page.getByTestId('stock-message')).toContainText('入庫しました');
    // 在庫の操作は商品のバージョンを進めず、保存していない変更にもならない
    await expect(page.getByText('バージョン 3')).toBeVisible();
    await expect(page.getByTestId('unsaved')).toHaveCount(0);

    await page.getByTestId('op-adjust').check();
    await page.getByTestId('stock-amount').fill('-10');
    await page.getByTestId('stock-reason').selectOption('loss');
    await page.getByTestId('apply-stock').click();
    await expect(page.getByRole('alert').filter({ hasText: '在庫が足りません' })).toBeVisible();
    await expect(page.getByTestId('stock-message')).toHaveCount(0);
    await expect(page.getByTestId('stock-quantity').first()).toHaveText('5');

    await page.getByTestId('stock-amount').fill('-2');
    await page.getByTestId('apply-stock').click();
    await expect(page.getByTestId('stock-quantity').first()).toHaveText('3');

    // 一覧で探せる
    await page.getByRole('navigation', { name: '現在地' }).getByRole('link', { name: '商品', exact: true }).click();
    await page.getByTestId('listing-search').fill('別の運営');
    await page.getByTestId('listing-search').press('Enter');
    await expect(page.getByTestId('listing-row')).toHaveCount(1);
    await expect(page.getByTestId('listing-row')).toContainText('3');
});

test('一覧は状態で絞り込み、見出しで並べ替え、「/」で検索へ。メニューは畳める', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    // 公開中 3（「商品 1」だけ在庫 3）と下書き 1
    const tenant = makeTenant({ listings: 3, drafts: 1, stock: 3 });
    await page.goto(`/tenants/${tenant.id}/listings`);

    // 状態の選択に件数（一覧の型）
    const status = page.getByTestId('status-filter');
    await expect(status.locator('option[value=""]')).toHaveText('すべて（4）');
    await status.selectOption('draft');
    await expect(page).toHaveURL(/status=draft/);
    await expect(page.getByTestId('listing-row')).toHaveCount(1);
    await expect(page.getByTestId('list-total')).toHaveText('1 件（絞り込み中）');
    await status.selectOption('');
    await expect(page.getByTestId('listing-row')).toHaveCount(4);

    // 在庫の多い順と少ない順
    await page.getByTestId('sort-stock').click();
    await expect(page).toHaveURL(/sort=-stock/);
    await expect(page.getByTestId('listing-row').first()).toContainText('商品 1');
    await expect(page.locator('th[aria-sort="descending"]')).toContainText('在庫');
    await page.getByTestId('sort-stock').click();
    await expect(page.getByTestId('listing-row').last()).toContainText('商品 1');

    // 「/」で検索へ（入力欄の外で押したとき）
    await page.getByRole('heading', { name: '商品', exact: true, level: 1 }).click();
    await page.keyboard.press('/');
    await expect(page.getByTestId('listing-search')).toBeFocused();

    // メニューを畳むと文字が隠れ、アイコンだけになる。開き直せる
    await page.getByRole('button', { name: 'メニューを畳む' }).click();
    await expect(page.getByRole('button', { name: 'メニューを開く' })).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByRole('navigation', { name: '店のメニュー' }).getByRole('link', { name: '商品', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'メニューを開く' }).click();
    await expect(page.getByRole('button', { name: 'メニューを畳む' })).toBeVisible();
});

test('検索の答えを、後から届いた古い一覧の答えが上書きしない', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 3, drafts: 1 });
    // 最初の（絞り込まない）一覧の答えだけを遅らせる
    await page.route(`**/api/tenants/${tenant.id}/listings?*`, async (route) => {
        if (!new URL(route.request().url()).searchParams.has('q')) {
            await new Promise((resolve) => setTimeout(resolve, 1500));
        }

        await route.continue();
    });

    await page.goto(`/tenants/${tenant.id}/listings`);
    await page.getByTestId('listing-search').fill('下書き');
    await page.getByTestId('listing-search').press('Enter');
    await expect(page.getByTestId('listing-row')).toHaveCount(1);
    // 遅れた答えが届いた後も、検索の答えのまま
    await page.waitForTimeout(2000);
    await expect(page.getByTestId('listing-row')).toHaveCount(1);
    await expect(page.getByTestId('listing-row')).toContainText('下書きの商品');
});

test('サンプルの入った店では、商品の一覧の上に帯が出て、オーナーはそこからまとめて消せる（消すと帯が消え、一覧を読み直す）', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ samples: true });
    await page.goto(`/tenants/${tenant.id}/listings`);

    const banner = page.getByTestId('samples-banner');
    await expect(banner).toContainText('サンプルの商品が 8 点あります。ご自分の商品を登録したら、まとめて削除できます。');
    await expect(page.getByTestId('listing-row')).toHaveCount(8);

    // ホームと同じ確かめ: 1 回目で問い、2 回目で送る。直前の再確認を求められるので、パスキーで確かめて送り直す
    await banner.getByTestId('remove-samples').click();
    await expect(banner).toContainText('元に戻せません。');
    await banner.getByTestId('confirm-remove-samples').click();
    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();

    await expect(page.getByTestId('samples-removed')).toContainText('サンプルを');
    await expect(page.getByTestId('samples-banner')).toHaveCount(0);
    await expect(page.getByTestId('listing-row')).toHaveCount(0);
    await expect(page.getByTestId('listings-empty')).toBeVisible();
});

test('オーナーでないメンバーには、サンプルの帯の文だけを出し、消すボタンは出さない', async ({ page, staff, makeTenant }) => {
    const tenant = makeTenant({ samples: true });
    await staff(tenant, 'operate');
    await page.goto(`/tenants/${tenant.id}/listings`);

    const banner = page.getByTestId('samples-banner');
    await expect(banner).toContainText('サンプルの商品が 8 点あります。');
    await expect(banner.getByTestId('samples-owner-only')).toHaveText('削除できるのはオーナーだけです。');
    await expect(page.getByTestId('remove-samples')).toHaveCount(0);
});
