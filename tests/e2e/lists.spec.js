import { expect, test } from './support/fixtures.js';

// 一覧の型: 上に絞り込みの箱（言葉・状態・並べ替え・件数）、条件は URL に持つ、
// まだ 1 件も無ければ次にやることのボタン、絞り込んで 0 件なら「条件を外す」、狭い画面では札の並び

async function expectNoOverflow(page) {
    const widths = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, view: window.innerWidth }));
    expect(widths.scroll, `横幅 ${widths.view}px からはみ出している`).toBeLessThanOrEqual(widths.view);
}

test('注文の一覧は状態と言葉で絞り込め、条件は URL に残り、0 件なら条件を外せる', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    // 発送済みの注文（商品は「取引用の商品」）と、入金待ちの注文（「入金待ちの品 1」）
    const tenant = makeTenant({ transaction: true, awaiting: 1 });
    await page.goto(`/tenants/${tenant.id}/transactions`);
    const rows = page.getByTestId('transaction-row');
    await expect(rows).toHaveCount(2);
    await expect(page.getByTestId('list-total')).toHaveText('2 件');
    await expect(page.getByTestId('clear-filters')).toHaveCount(0);
    // 状態の札は丸の色だけでなく文字で
    await expect(rows.getByText('入金待ち', { exact: true })).toBeVisible();
    await expect(rows.getByText('発送済み', { exact: true })).toBeVisible();

    // 状態で絞る（選択に件数）。URL に残り、読み込み直しても同じ
    const status = page.getByTestId('status-filter');
    await expect(status.locator('option[value="awaiting_payment"]')).toHaveText('入金待ち（1）');
    await status.selectOption('awaiting_payment');
    await expect(page).toHaveURL(/status=awaiting_payment/);
    await expect(rows).toHaveCount(1);
    await expect(rows).toContainText('入金待ち');
    await expect(page.getByTestId('list-total')).toHaveText('1 件（絞り込み中）');
    await page.reload();
    await expect(status).toHaveValue('awaiting_payment');
    await expect(rows).toHaveCount(1);

    // 言葉（商品名）も重ねると 0 件 → 「条件に合うものがありません」と条件を外すボタン
    await page.getByTestId('transaction-search').fill('取引用');
    await page.getByTestId('transaction-search').press('Enter');
    await expect(page).toHaveURL(/q=/);
    await expect(page.getByTestId('no-match')).toContainText('条件に合うものがありません');
    await expect(rows).toHaveCount(0);
    await page.getByTestId('empty-clear-filters').click();
    await expect(page).not.toHaveURL(/status=|q=/);
    await expect(rows).toHaveCount(2);
    await expect(status).toHaveValue('');
    await expect(page.getByTestId('transaction-search')).toHaveValue('');

    // 言葉だけで探す → 戻ると絞り込む前に戻り、検索欄の文字も URL に合わせて消える
    await page.getByTestId('transaction-search').fill('取引用');
    await page.getByTestId('transaction-search').press('Enter');
    await expect(rows).toHaveCount(1);
    await expect(rows).toContainText('発送済み');
    await page.goBack();
    await expect(page).not.toHaveURL(/q=/);
    await expect(rows).toHaveCount(2);
    await expect(page.getByTestId('transaction-search')).toHaveValue('');

    // 並べ替えの選択も URL に（見出しと同じ並べ替え）
    await page.getByTestId('sort-select').selectOption('total_amount');
    await expect(page).toHaveURL(/sort=total_amount/);
    await expect(rows.first()).toContainText('入金待ち');
    await expect(page.locator('th[aria-sort="ascending"]')).toContainText('合計');

    // 箱の「条件を外す」でも外せる（並べ替えは残す）
    await status.selectOption('shipped');
    await expect(rows).toHaveCount(1);
    await page.getByTestId('clear-filters').click();
    await expect(rows).toHaveCount(2);
    await expect(page).toHaveURL(/sort=total_amount/);
});

test('一覧が空のときは、絵と次にやることのボタンを出す（注文はショップを開く、商品は登録する）', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant();

    await page.goto(`/tenants/${tenant.id}/transactions`);
    await expect(page.getByTestId('orders-empty')).toContainText('まだ注文はありません');
    await expect(page.getByTestId('orders-empty').getByTestId('empty-figure')).toBeVisible();
    await expect(page.getByTestId('list-filters')).toHaveCount(0);
    const open = page.getByTestId('empty-open-storefront');
    await expect(open).toHaveAttribute('href', new RegExp(`//${tenant.id}\\.localhost`));
    await expect(open).toHaveAttribute('target', '_blank');

    await page.goto(`/tenants/${tenant.id}/listings`);
    await expect(page.getByTestId('listings-empty')).toContainText('まだ商品がありません');
    // まだ 1 つも無いときは、登録のボタンは真ん中の 1 つだけ（見出しの右上には出さない）
    await expect(page.getByTestId('new-listing')).toHaveCount(0);
    await page.getByTestId('empty-new-listing').click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/listings/new$`));
});

test('商品の一覧も状態・言葉・並べ替えを URL に持ち、0 件なら条件を外せる', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 2, drafts: 1 });
    await page.goto(`/tenants/${tenant.id}/listings?status=draft`);
    const rows = page.getByTestId('listing-row');
    await expect(rows).toHaveCount(1);
    // 商品があれば、見出しの右上に登録のボタンが出る
    await expect(page.getByTestId('new-listing')).toBeVisible();
    await expect(page.getByTestId('status-filter')).toHaveValue('draft');
    await expect(rows.getByText('下書き', { exact: true })).toBeVisible();

    await page.getByTestId('listing-search').fill('見つからない名前');
    await page.getByTestId('listing-search').press('Enter');
    await expect(page.getByTestId('no-match')).toBeVisible();
    // 絞り込んで 0 件（商品はある）: 右上の登録のボタンは残る
    await expect(page.getByTestId('new-listing')).toBeVisible();
    await page.getByTestId('empty-clear-filters').click();
    await expect(rows).toHaveCount(3);

    await page.getByTestId('sort-select').selectOption('-unit_amount');
    await expect(page).toHaveURL(/sort=-unit_amount/);
    await expect(rows.first()).toContainText('商品 2');
});

test('スマホの幅（390px）では、一覧は表から札の並びになり、はみ出さない', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 2, transaction: true, awaiting: 1 });
    await page.setViewportSize({ width: 390, height: 844 });

    for (const [path, row, label] of [['/transactions', 'transaction-row', '合計'], ['/listings', 'listing-row', '在庫']]) {
        await page.goto(`/tenants/${tenant.id}${path}`);
        const first = page.getByTestId(row).first();
        await expect(first).toBeVisible();
        // 見出しの行は隠れ、札の中に欄の名前が出る
        await expect(page.locator('thead').first()).toBeHidden();
        const name = await first.locator(`td[data-label="${label}"]`).evaluate((cell) => getComputedStyle(cell, '::before').content);
        expect(name).toBe(`"${label}"`);
        // 札は表の幅に収まり、横に動かす必要が無い
        const box = await first.boundingBox();
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(390);
        await expect(page.getByTestId('list-filters')).toBeVisible();
        await expectNoOverflow(page);
        const scroller = await page.locator('table').first().evaluate((table) => table.parentElement.scrollWidth <= table.parentElement.clientWidth);
        expect(scroller, '表の中で横に動かさずに収まる').toBe(true);
    }

    // 狭い画面では見出しで並べ替えられないので、箱の並べ替えの選択で
    await page.getByTestId('sort-select').selectOption('-stock');
    await expect(page).toHaveURL(/sort=-stock/);
});
