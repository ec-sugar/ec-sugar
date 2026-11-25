import { expect, test } from './support/fixtures.js';

// 入っているプラグインとテーマ。オーナーだけ・読むだけ。テーマは使っている店つき。入れ方は composer の案内だけ

test('オーナーが入っているテーマと使っている店、入れ方の案内を見る', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ theme: 'kinari' });

    // 運営全体の画面は、店の切り替えの中から開く
    await page.getByRole('button', { name: /店の切り替えと運営全体/ }).click();
    await page.getByTestId('nav-plugins').click();
    await expect(page.getByRole('heading', { name: 'App とテーマ', level: 1 })).toBeVisible();

    const rows = page.getByTestId('theme-row');
    await expect(rows).toHaveCount(4);
    const kinari = rows.filter({ hasText: 'ec-sugar/theme-kinari' });
    await expect(kinari).toContainText('0.1.0');
    await expect(kinari).toContainText('自社');
    await expect(kinari.getByTestId('package-state')).toHaveText('利用可能');
    await expect(kinari.getByTestId('theme-shops')).toContainText(tenant.name);
    await expect(rows.filter({ hasText: 'ec-sugar/theme-default' })).toContainText('既定のテーマ（角砂糖）');

    const faq = page.getByTestId('plugin-row').filter({ hasText: 'ec-sugar/plugin-faq' });
    await expect(faq.getByTestId('package-state')).toHaveText('利用可能');
    await expect(page.getByTestId('install-steps')).toContainText('composer require');
    // 入れる・上げるボタンは無い
    await expect(page.getByRole('button', { name: /インストール|アップロード|入れる/ })).toHaveCount(0);
});

test('店のメニューから、プラグインをパスキーで確かめて使うようにし、また止める', async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant();

    await page.goto(`/tenants/${tenant.id}`);
    await page.getByRole('navigation', { name: '店のメニュー' }).getByRole('link', { name: 'App', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'App', exact: true, level: 1 })).toBeVisible();

    const row = page.getByTestId('tenant-plugin-row').filter({ hasText: 'よくある質問' });
    await expect(row.getByTestId('tenant-plugin-state')).toHaveText('使わない');
    await row.getByTestId('tenant-plugin-toggle').click();
    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await expect(dialog).toBeVisible();
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();
    await expect(page.getByRole('status')).toContainText('「よくある質問」を使い始めました');
    await expect(row.getByTestId('tenant-plugin-state')).toHaveText('使う');

    // 確認したばかりなので、止めるときは窓が出ない
    await row.getByTestId('tenant-plugin-toggle').click();
    await expect(row.getByTestId('tenant-plugin-state')).toHaveText('使わない');
    await expect(dialog).toBeHidden();
});

test('一覧から GA4 の設定の画面へ移り、使う前に ID を保存すると知らせが出て、使うにすると消える', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant();

    await page.goto(`/tenants/${tenant.id}/plugins`);
    await expect(page.getByRole('heading', { name: 'App', exact: true, level: 1 })).toBeVisible();
    // 一覧には設定の欄を出さない（プラグインごとの画面で入れる）
    await expect(page.getByTestId('plugin-settings')).toHaveCount(0);

    const row = page.getByTestId('tenant-plugin-row').filter({ hasText: 'Google アナリティクス 4' });
    await row.getByRole('link', { name: 'Google アナリティクス 4', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/plugins/ga4$`));
    await expect(page.getByRole('heading', { name: 'Google アナリティクス 4', level: 1 })).toBeVisible();
    await expect(page.getByRole('navigation', { name: '現在地' }).getByRole('link', { name: 'App', exact: true })).toBeVisible();
    await expect(page.getByTestId('tenant-plugin-state')).toHaveText('使わない');

    // 使っていなくても ID は入れて保存できる。動かないことを知らせる
    await page.getByTestId('plugin-setting-measurement_id').fill('G-ABC123DEF4');
    await page.getByTestId('plugin-settings-save').click();
    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await expect(dialog).toBeVisible();
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();
    await expect(page.getByRole('status').filter({ hasText: '設定を保存しました' })).toContainText('「Google アナリティクス 4」の設定を保存しました');
    const notice = page.getByTestId('plugin-disabled-notice');
    await expect(notice).toContainText('この店では使っていません。「使う」にするまで動きません。');

    // 「使う」にすると知らせが消える（確認したばかりなので窓は出ない）
    await page.getByTestId('tenant-plugin-toggle').click();
    await expect(page.getByTestId('tenant-plugin-state')).toHaveText('使う');
    await expect(notice).toBeHidden();
    await expect(dialog).toBeHidden();

    // 読み込み直しても保存した ID が残る
    await page.reload();
    await expect(page.getByTestId('plugin-setting-measurement_id')).toHaveValue('G-ABC123DEF4');

    // 入っていないプラグインの名前は「見つかりません」
    await page.goto(`/tenants/${tenant.id}/plugins/no-such-plugin`);
    await expect(page.getByTestId('plugin-not-found')).toContainText('App が見つかりません');
    await page.getByRole('link', { name: 'App の一覧へ' }).click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/plugins$`));
});

// App の画面: 目的ごとのまとまりに札で並べる。言葉で探す・使っている App だけに絞る（条件は URL）、0 件なら条件を外せる、
// 札のどこを押しても App ごとの設定の画面へ。狭い画面（390px）では札を 1 列にしてはみ出さない

async function expectNoOverflow(page) {
    const widths = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, view: window.innerWidth }));
    expect(widths.scroll, `横幅 ${widths.view}px からはみ出している`).toBeLessThanOrEqual(widths.view);
}

test('App は目的ごとのまとまりに札で並び、言葉と「使っている App だけ」で絞れ、札から設定の画面へ移る', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    // 動画と地図の App を使っている店（ADR 0040）
    const tenant = makeTenant({ embeds: true });
    await page.goto(`/tenants/${tenant.id}/plugins`);

    // まとまりは核の順（解析 → 集客と広告 → ページの部品 → 売り方 → 商品と在庫 → 配送 → お客さん → 店の運営）。App の無い目的（お支払いなど）は出さない
    const groups = page.getByTestId('plugin-group');
    await expect(groups).toHaveCount(8);
    expect(await groups.evaluateAll((els) => els.map((el) => el.dataset.category))).toEqual(['analytics', 'marketing', 'page', 'selling', 'products', 'shipping', 'customers', 'operations']);
    await expect(groups.first().getByRole('heading', { level: 2 })).toHaveText('アクセス解析');
    const pageParts = page.locator('[data-test="plugin-group"][data-category="page"]');
    await expect(pageParts.getByRole('heading', { level: 2 })).toHaveText('ページの部品');
    await expect(pageParts.getByTestId('plugin-group-count')).toHaveText('4 個');
    const video = page.locator('[data-test="tenant-plugin-row"][data-plugin="video"]');
    await expect(video.getByTestId('tenant-plugin-state')).toHaveText('使う');
    await expect(page.locator('[data-test="tenant-plugin-row"][data-plugin="faq"]').getByTestId('tenant-plugin-state')).toHaveText('使わない');
    // 設定のある App は、そろっているかを出す（GA4 は ID がまだ。設定の無い動画には出さない）
    const ga4 = page.locator('[data-test="tenant-plugin-row"][data-plugin="ga4"]');
    await expect(ga4.getByTestId('tenant-plugin-settings-state')).toHaveText('未設定');
    await expect(video.getByTestId('tenant-plugin-settings-state')).toHaveCount(0);
    const all = await page.getByTestId('tenant-plugin-row').count();
    await expect(page.getByTestId('list-total')).toHaveText(`${all} 件`);

    // 言葉で探す（目的の名前「集客と広告」でも当たる）。条件は URL に残る
    await page.getByTestId('plugin-search').fill('広告');
    await page.getByTestId('plugin-search').press('Enter');
    await expect(page).toHaveURL(/q=/);
    await expect(groups).toHaveCount(1);
    await expect(groups.first()).toHaveAttribute('data-category', 'marketing');
    await expect(page.getByTestId('list-total')).toContainText('（絞り込み中）');
    await page.reload();
    await expect(groups).toHaveCount(1);
    await expect(page.getByTestId('plugin-search')).toHaveValue('広告');
    await page.getByTestId('clear-filters').click();
    await expect(groups).toHaveCount(8);

    // 使っている App だけ（動画と地図と、新しい店で最初から使うになっている商品の CSV）
    await page.getByTestId('plugin-state-filter').selectOption('enabled');
    await expect(page).toHaveURL(/status=enabled/);
    await expect(page.getByTestId('tenant-plugin-row')).toHaveCount(3);
    expect(await page.getByTestId('tenant-plugin-row').evaluateAll((els) => els.map((el) => el.dataset.plugin).sort())).toEqual(['google-map', 'product-csv', 'video']);
    await expect(groups).toHaveCount(2);
    await expect(page.getByTestId('list-total')).toHaveText('3 件（絞り込み中）');

    // 絞り込んで 0 件なら「条件を外す」
    await page.getByTestId('plugin-search').fill('見つからない言葉');
    await page.getByTestId('plugin-search').press('Enter');
    await expect(page.getByTestId('plugins-no-match')).toContainText('条件に合う App がありません');
    await page.getByTestId('plugins-clear-filters').click();
    await expect(page.getByTestId('tenant-plugin-row')).toHaveCount(all);
    await expect(page).not.toHaveURL(/status=|q=/);

    if (process.env.E2E_SCREENSHOTS) {
        await page.screenshot({ path: 'test-results/screens/apps-desktop.png', fullPage: true });
    }

    // 札の名前でない所（説明の文の上）を押しても、App ごとの設定の画面へ移る（名前のリンクの押せる所が札の全体に広がっている）
    const description = await ga4.getByTestId('plugin-card-description').boundingBox();
    await page.mouse.click(description.x + description.width / 2, description.y + description.height / 2);
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/plugins/ga4$`));
    await expect(page.getByRole('heading', { name: 'Google アナリティクス 4', level: 1 })).toBeVisible();
});

test('使っている App が無い店で「使っている App だけ」にすると、まだ無いことを出し、条件を外せる', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    // 新しい店は商品の CSV を最初から使うので、それも使わないにした店で
    const tenant = makeTenant({ noApps: true });
    await page.goto(`/tenants/${tenant.id}/plugins?status=enabled`);
    await expect(page.getByTestId('plugins-no-match')).toContainText('この店で使っている App はまだありません');
    await expect(page.getByTestId('list-total')).toHaveText('0 件（絞り込み中）');
    await page.getByTestId('plugins-clear-filters').click();
    await expect(page.getByTestId('plugin-group').first()).toBeVisible();
});

test('スマホの幅（390px）では、App の札は 1 列に並び、はみ出さない', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ embeds: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/tenants/${tenant.id}/plugins`);

    const cards = page.locator('[data-test="plugin-group"][data-category="marketing"] [data-test="tenant-plugin-row"]');
    await expect(cards.first()).toBeVisible();
    const [first, second] = [await cards.nth(0).boundingBox(), await cards.nth(1).boundingBox()];
    // 1 列（同じ左の位置で、2 枚目は 1 枚目の下）
    expect(Math.round(second.x)).toBe(Math.round(first.x));
    expect(second.y).toBeGreaterThan(first.y);
    expect(first.x).toBeGreaterThanOrEqual(0);
    expect(first.x + first.width).toBeLessThanOrEqual(390);
    await expect(page.getByTestId('list-filters')).toBeVisible();
    await expectNoOverflow(page);

    if (process.env.E2E_SCREENSHOTS) {
        await page.screenshot({ path: 'test-results/screens/apps-mobile.png', fullPage: true });
    }

    // 札の「使う」のボタンは札の上に乗っていて、押すと本人確認になる（設定の画面へは移らない）
    await page.locator('[data-test="tenant-plugin-row"][data-plugin="faq"]').getByTestId('tenant-plugin-toggle').click();
    await expect(page.getByRole('dialog', { name: '本人確認' })).toBeVisible();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/plugins$`));
});
