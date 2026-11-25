import { expect, test } from './support/fixtures.js';

// 商品の登録を 1 枚の長いフォームで。上から 基本 → 写真 → 説明 → 種類 → オプション → 在庫 → 詳しい設定、下に 1 つの保存。
// 保存は変えた所の API を順に呼び、途中で失敗したら止めて「保存できたもの・できなかったもの・まだのもの」を知らせる（resources/admin/listingSave.js）。
// E2E_SCREENSHOTS=1 のときは写しも撮る（test-results/screens/listing-form-*.png）

async function shoot(page, name) {
    if (process.env.E2E_SCREENSHOTS) {
        await page.screenshot({ path: `test-results/screens/${name}.png`, fullPage: true });
    }
}

// 色を塗った PNG を、ページの中の canvas で作る
async function png(page, name, color) {
    const base64 = await page.evaluate((fill) => {
        const canvas = document.createElement('canvas');
        canvas.width = 400;
        canvas.height = 500;
        const context = canvas.getContext('2d');
        context.fillStyle = fill;
        context.fillRect(0, 0, 400, 500);

        return canvas.toDataURL('image/png').split(',')[1];
    }, color);

    return { name, mimeType: 'image/png', buffer: Buffer.from(base64, 'base64') };
}

// 管理画面の API を、ログイン中の画面から呼ぶ（下ごしらえ・別の運営の操作）
async function call(page, method, path, body) {
    return page.evaluate(async ({ method, path, body }) => {
        const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '');
        const response = await fetch(path, {
            method,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
            body: body ? JSON.stringify(body) : undefined,
        });

        return { status: response.status, body: await response.json().catch(() => null) };
    }, { method, path, body });
}

async function openFirstListing(page, tenant) {
    await page.goto(`/tenants/${tenant.id}/listings`);
    await page.getByTestId('listing-row').first().getByRole('link').first().click();
    await expect(page.getByTestId('listing-title')).toHaveValue('商品 1');
}

test('新しい商品を 1 枚で入れて公開する（写真・種類・オプション・カテゴリまで一度に）', async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1 });
    await page.goto(`/tenants/${tenant.id}/listings`);
    expect((await call(page, 'POST', `/api/tenants/${tenant.id}/categories`, { name: 'バッグ', slug: 'bags' })).status).toBe(201);

    await page.goto(`/tenants/${tenant.id}/listings/new`);
    // 目次（広い画面）と節の並び
    await expect(page.getByTestId('form-outline')).toBeVisible();
    await expect(page.getByTestId('form-outline').getByRole('button')).toHaveText(['基本', '写真', '説明', '種類（色・サイズ）', 'オプション', '在庫', '検索と共有', '詳しい設定']);

    // 基本（必須だけ）
    await page.getByTestId('listing-title').fill('帆布のトート');
    await page.getByTestId('listing-amount').fill('5400');
    await expect(page.getByTestId('unsaved')).toBeVisible();
    await expect(page.getByTestId('outline-listing-basic').getByTestId('outline-changed')).toHaveCount(1);

    // 写真は覚えておき、作った後に送る
    await page.getByTestId('file-input').setInputFiles([await png(page, 'front.png', '#6d8266')]);
    await expect(page.getByTestId('queued-photo')).toHaveCount(1);

    // 種類: 色 × 2
    await page.getByTestId('add-axis').click();
    await page.getByTestId('axis-name').fill('色');
    await page.getByTestId('axis-values-input').fill('生成り');
    await page.getByTestId('axis-values-input').press('Enter');
    await page.getByTestId('axis-values-input').fill('黒');
    await page.getByTestId('axis-values-input').press('Enter');
    await expect(page.getByTestId('combination-count')).toContainText('組み合わせ 2 件');
    // 色を分ける商品の最初の在庫と品番は、種類ができてから
    await expect(page.getByTestId('stock-per-variant')).toBeVisible();

    // オプション: ギフト包装（付ける・¥300）
    await page.getByTestId('add-addon').click();
    await page.getByTestId('addon-kind').selectOption('toggle');
    await page.getByTestId('addon-name').fill('ギフト包装');
    await page.getByTestId('addon-amount').fill('300');

    // 詳しい設定（畳んである）: カテゴリ。目次から開ける
    await expect(page.getByRole('checkbox', { name: 'バッグ', exact: true })).toHaveCount(0);
    await page.getByTestId('outline-listing-details').click();
    await expect(page.getByTestId('sku-by-variant')).toBeVisible();
    await page.getByRole('checkbox', { name: 'バッグ', exact: true }).check();
    await shoot(page, 'listing-form-new');

    // 公開する: 作る → 写真 → 種類 → オプション → カテゴリ → 公開。画面は移らず、URL が編集の形に
    await page.getByTestId('publish-listing').click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/listings/[0-9a-z]{26}$`));
    await expect(page.getByTestId('save-message')).toContainText('公開しました');
    await expect(page.getByTestId('unsaved')).toHaveCount(0);
    await expect(page.getByTestId('listing-status')).toHaveValue('active');
    await expect(page.getByText('バージョン 4')).toBeVisible();
    await expect(page.getByTestId('photo-tile')).toHaveCount(1);
    await expect(page.getByTestId('variant-row')).toHaveCount(2);
    await expect(page.getByTestId('addon')).toHaveCount(1);
    await expect(page.getByTestId('open-in-shop')).toBeVisible();

    // 読み直しても同じ
    await page.reload();
    await expect(page.getByTestId('listing-title')).toHaveValue('帆布のトート');
    await expect(page.getByTestId('variant-row')).toHaveCount(2);
    await expect(page.getByTestId('addon-name')).toHaveValue('ギフト包装');
    await page.getByTestId('listing-details-toggle').click();
    await expect(page.getByRole('checkbox', { name: 'バッグ', exact: true })).toBeChecked();

    // 編集: 種類の行（黒だけ ¥5,800・品番）と商品名を 1 回の「保存する」で
    const black = page.getByTestId('variant-row').filter({ hasText: '黒' });
    await black.getByTestId('variant-amount').fill('5800');
    await black.getByTestId('variant-sku').fill('TOTE-BK');
    await page.getByTestId('listing-title').fill('帆布のトートバッグ');
    await page.getByTestId('save-listing').click();
    await expect(page.getByTestId('save-message')).toContainText('保存しました');
    await expect(page.getByText('バージョン 6')).toBeVisible();
    await page.reload();
    await expect(page.getByTestId('variant-row').filter({ hasText: '黒' }).getByTestId('variant-sku')).toHaveValue('TOTE-BK');
    await expect(page.getByTestId('listing-title')).toHaveValue('帆布のトートバッグ');

    // 種類の行の「入庫・調整」から、在庫の節でその種類を入庫する（押すとすぐ反映。バージョンは進まない）
    await black.getByTestId('variant-stock-open').click();
    await expect(page.getByTestId('stock-variant')).toHaveValue(await page.getByTestId('stock-variant').locator('option', { hasText: '黒' }).getAttribute('value'));
    await page.getByTestId('stock-amount').fill('4');
    await page.getByTestId('apply-stock').click();
    await expect(page.getByTestId('stock-message')).toContainText('「黒」を入庫しました');
    await expect(black.getByTestId('variant-stock')).toContainText('4 個');
    await expect(page.getByText('バージョン 6')).toBeVisible();
});

test('保存していない変更があると、離れる前に知らせる', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1 });
    await openFirstListing(page, tenant);
    const menu = page.getByRole('navigation', { name: '店のメニュー' }).getByRole('link', { name: '商品', exact: true });

    // 変えていなければ、そのまま移れる
    await page.getByTestId('listing-title').fill('商品 1');
    await expect(page.getByTestId('unsaved')).toHaveCount(0);

    // 変えたら、印が出て、移る前に確かめる。「やめる」と画面に残る
    await page.getByTestId('listing-summary').fill('書きかけ');
    await expect(page.getByTestId('unsaved')).toBeVisible();
    await expect(page.getByTestId('outline-listing-description').getByTestId('outline-changed')).toHaveCount(1);
    await expect(page.getByTestId('preview-unsaved')).toBeVisible();
    let asked = null;
    page.once('dialog', (dialog) => {
        asked = dialog.message();
        dialog.dismiss();
    });
    await menu.click();
    await expect.poll(() => asked).toContain('保存していない変更があります');
    await expect(page).toHaveURL(/\/listings\/[0-9a-z]{26}$/);
    await expect(page.getByTestId('listing-summary')).toHaveValue('書きかけ');

    // 「OK」なら移る（保存はしない）
    page.once('dialog', (dialog) => dialog.accept());
    await menu.click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/listings$`));
    await page.getByTestId('listing-row').first().getByRole('link').first().click();
    await expect(page.getByTestId('listing-summary')).toHaveValue('');

    // 保存した後は知らせない
    await page.getByTestId('listing-summary').fill('保存した説明');
    await page.getByTestId('save-listing').click();
    await expect(page.getByTestId('save-message')).toContainText('保存しました');
    let again = false;
    page.once('dialog', (dialog) => {
        again = true;
        dialog.dismiss();
    });
    await menu.click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/listings$`));
    expect(again).toBe(false);
});

test('途中で失敗したら、保存できたもの・できなかったもの・まだのものを知らせ、もう一度で残りだけを送る', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1 });
    await page.goto(`/tenants/${tenant.id}/listings`);
    expect((await call(page, 'POST', `/api/tenants/${tenant.id}/categories`, { name: '雑貨', slug: 'goods' })).status).toBe(201);
    await openFirstListing(page, tenant);

    // 基本（商品名）・オプション（名前が空＝サーバーが断る）・カテゴリを変えて、1 回の「保存する」
    await page.getByTestId('listing-title').fill('商品 1（改）');
    await page.getByTestId('add-addon').click();
    await page.getByTestId('addon-kind').selectOption('text');
    await page.getByTestId('listing-details-toggle').click();
    await page.getByRole('checkbox', { name: '雑貨', exact: true }).check();
    await page.getByTestId('save-listing').click();

    const outcome = page.getByTestId('save-outcome');
    await expect(outcome).toContainText('途中で止まりました');
    await expect(page.getByTestId('save-done')).toContainText('基本の情報と説明');
    await expect(page.getByTestId('save-failed')).toContainText('オプション');
    await expect(page.getByTestId('save-pending')).toContainText('カテゴリ');
    // 基本の情報は保存済み（バージョンが進み、基本の印は消える）。オプションの欄に理由、カテゴリはまだ変更あり
    await expect(page.getByText('バージョン 2')).toBeVisible();
    await expect(page.getByTestId('addon').getByRole('alert').first()).toBeVisible();
    await expect(page.getByTestId('outline-listing-basic').getByTestId('outline-changed')).toHaveCount(0);
    await expect(page.getByTestId('outline-listing-addons').getByTestId('outline-changed')).toHaveCount(1);
    await expect(page.getByTestId('outline-listing-details').getByTestId('outline-changed')).toHaveCount(1);
    await expect(page.getByTestId('unsaved')).toBeVisible();
    await shoot(page, 'listing-form-failed');

    // 直してもう一度: 残り（オプション・カテゴリ）だけを送る
    await page.getByTestId('save-failed').getByTestId('go-failed').click();
    await page.getByTestId('addon-name').fill('名入れ');
    await page.getByTestId('save-listing').click();
    await expect(page.getByTestId('save-message')).toContainText('保存しました');
    await expect(outcome).toHaveCount(0);
    await expect(page.getByText('バージョン 3')).toBeVisible();
    await page.reload();
    await expect(page.getByTestId('listing-title')).toHaveValue('商品 1（改）');
    await expect(page.getByTestId('addon-name')).toHaveValue('名入れ');
    await page.getByTestId('listing-details-toggle').click();
    await expect(page.getByRole('checkbox', { name: '雑貨', exact: true })).toBeChecked();
});

test('新しい商品の公開は、ほかがすべて保存できてから（途中で止まったら下書きのまま）', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1 });
    await page.goto(`/tenants/${tenant.id}/listings/new`);
    await page.getByTestId('listing-title').fill('名入れのマグ');
    await page.getByTestId('listing-amount').fill('2000');
    // 選択肢の名前が空のオプション（サーバーが断る）
    await page.getByTestId('add-addon').click();
    await page.getByTestId('publish-listing').click();

    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/listings/[0-9a-z]{26}$`));
    await expect(page.getByTestId('save-done')).toContainText('基本の情報と説明');
    await expect(page.getByTestId('save-failed')).toContainText('オプション');
    await expect(page.getByTestId('save-pending')).toContainText('公開');
    await expect(page.getByTestId('save-not-published')).toBeVisible();
    await expect(page.getByTestId('listing-status')).toHaveValue('draft');

    // 直して状態を「公開中」にして保存すると、最後に公開する
    await page.getByTestId('addon-name').fill('名入れ');
    await page.getByTestId('addon-choice-label').fill('なし');
    await page.getByTestId('listing-status').selectOption('active');
    await page.getByTestId('save-listing').click();
    await expect(page.getByTestId('save-message')).toContainText('保存して公開しました');
    await expect(page.getByTestId('listing-status')).toHaveValue('active');
    await expect(page.getByTestId('save-outcome')).toHaveCount(0);
});

test('同時の編集の衝突: 種類の保存の途中で先に保存されていたら、上書きせずに読み直しを促す', async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1 });
    await openFirstListing(page, tenant);
    const listingPath = new URL(page.url()).pathname.replace('/tenants/', '/api/tenants/');

    // 別の運営が先にオプションを保存した（同じバージョン 1 を読んだ画面から）
    expect((await call(page, 'PUT', `${listingPath}/addons`, { version: 1, addons: [{ kind: 'toggle', name: '別の運営の包装', required: false, amount: 100, choices: null, max_length: null }] })).status).toBe(200);

    // 在庫の入庫は押すとすぐ反映するが、返った商品のバージョンを画面に取り込まない（取り込むと、相手の変更に気づかず上書きする）
    await page.getByTestId('stock-amount').fill('1');
    await page.getByTestId('apply-stock').click();
    await expect(page.getByTestId('stock-message')).toContainText('入庫しました');
    await expect(page.getByText('バージョン 1')).toBeVisible();

    // こちらは種類を足して保存 → 断られ、上書きしない
    await page.getByTestId('add-axis').click();
    await page.getByTestId('axis-name').fill('サイズ');
    await page.getByTestId('axis-values-input').fill('S');
    await page.getByTestId('axis-values-input').press('Enter');
    await page.getByTestId('save-listing').click();
    await expect(page.getByTestId('save-outcome')).toContainText('別の操作で先に更新されています');
    await expect(page.getByTestId('save-listing')).toBeDisabled();
    await page.getByTestId('reload-listing').click();
    await expect(page.getByTestId('addon-name')).toHaveValue('別の運営の包装');
    await expect(page.getByTestId('axis')).toHaveCount(0);
    await expect(page.getByTestId('unsaved')).toHaveCount(0);
});

test('種類が消える変更は、保存の前に確かめる', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, stock: 3 });
    await openFirstListing(page, tenant);

    // 初めて軸を足す: 今の在庫は最初の組み合わせ（赤）へ渡す（既定）
    await page.getByTestId('add-axis').click();
    await page.getByTestId('axis-name').fill('色');
    for (const value of ['赤', '青']) {
        await page.getByTestId('axis-values-input').fill(value);
        await page.getByTestId('axis-values-input').press('Enter');
    }
    await expect(page.getByTestId('carry-to')).toHaveValue('赤');
    await page.getByTestId('save-listing').click();
    await expect(page.getByTestId('save-message')).toContainText('保存しました');
    await expect(page.getByTestId('variant-row')).toHaveCount(2);
    await expect(page.getByTestId('variant-row').filter({ hasText: '赤' }).getByTestId('variant-stock')).toContainText('3 個');

    // 青を外すと、保存の前に「1 件の種類が削除されます」と確かめる。「やめる」なら何も送らない
    await page.getByTestId('axis-values').getByRole('button', { name: '「青」を削除' }).click();
    await page.getByTestId('save-listing').click();
    await expect(page.getByText('1 件の種類が削除されます')).toBeVisible();
    await page.getByRole('button', { name: 'やめる' }).click();
    await expect(page.getByTestId('variant-row')).toHaveCount(2);
    await page.getByTestId('save-listing').click();
    await page.getByTestId('confirm-save-listing').click();
    await expect(page.getByTestId('save-message')).toContainText('保存しました');
    await expect(page.getByTestId('variant-row')).toHaveCount(1);
});

test('390px の幅でも崩れない（目次は隠し、保存の帯は下に留まる）', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1 });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/tenants/${tenant.id}/listings/new`);
    await expect(page.getByTestId('listing-title')).toBeVisible();
    await expect(page.getByTestId('form-outline')).toBeHidden();
    await expect(page.getByTestId('publish-listing')).toBeInViewport();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    await shoot(page, 'listing-form-390-new');

    await openFirstListing(page, tenant);
    await expect(page.getByTestId('save-listing')).toBeInViewport();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    await shoot(page, 'listing-form-390-edit');
});
