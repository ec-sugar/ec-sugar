import { expect, test } from './support/fixtures.js';

// 商品カテゴリ。管理画面でカテゴリの木を作って直し、商品に付ける

test('カテゴリの木を作って直し、商品に付ける', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1 });
    await page.goto(`/tenants/${tenant.id}`);
    // カテゴリは「商品」の中のタブ
    await page.getByRole('navigation', { name: '店のメニュー' }).getByRole('link', { name: '商品', exact: true }).click();
    await page.getByTestId('tab-page-categories').click();
    await expect(page.getByRole('heading', { name: 'カテゴリ', exact: true })).toBeVisible();

    const add = async (name, slug, parent = '') => {
        await page.getByTestId('category-name').fill(name);
        await page.getByTestId('category-slug').fill(slug);
        await page.getByTestId('category-parent').selectOption({ label: parent || 'なし（いちばん上の階層）' });
        await page.getByTestId('add-category').click();
        await expect(page.getByRole('status')).toContainText('カテゴリを追加しました');
    };
    await add('トップス', 'tops');
    await add('アウター', 'outer');
    await add('ニット', 'knit', 'トップス');
    const rows = page.getByTestId('category-row');
    await expect(rows).toHaveCount(3);
    await expect(rows.nth(1)).toContainText('ニット');

    // 重なる slug は断られ、理由が出る
    await page.getByTestId('category-name').fill('別のトップス');
    await page.getByTestId('category-slug').fill('tops');
    await page.getByTestId('add-category').click();
    await expect(page.getByText('この slug はすでに使われています。')).toBeVisible();

    // ニットをアウターの下へ動かし、名前を変える
    await rows.nth(1).getByTestId('category-edit').click();
    await page.getByTestId('edit-name').fill('ニット・セーター');
    await page.getByTestId('edit-parent').selectOption({ label: 'アウター' });
    await page.getByTestId('save-category').click();
    await expect(page.getByRole('status')).toContainText('保存しました');
    await expect(rows.nth(2)).toContainText('ニット・セーター');

    // 並べ替え: アウターを上へ
    await rows.nth(1).getByTestId('category-up').click();
    await expect(page.getByRole('status')).toContainText('並べ替えました');
    await expect(rows.first()).toContainText('アウター');

    // 子のあるアウターは消せない。トップスは消せる
    await expect(rows.first().getByTestId('category-delete')).toBeDisabled();
    await rows.nth(2).getByTestId('category-delete').click();
    await rows.nth(2).getByTestId('category-confirm-delete').click();
    await expect(page.getByRole('status')).toContainText('カテゴリを削除しました');
    await expect(rows).toHaveCount(2);

    // 商品に付ける
    await page.getByTestId('tab-page-listings').click();
    await page.getByTestId('listing-row').first().getByRole('link').first().click();
    // カテゴリは商品のフォームの畳んだ「詳しい設定」の中。保存は下の「保存する」でまとめて
    await page.getByTestId('listing-details-toggle').click();
    await page.getByRole('checkbox', { name: 'アウター', exact: true }).check();
    await page.getByRole('checkbox', { name: 'ニット・セーター', exact: true }).check();
    await page.getByTestId('primary-category').selectOption({ label: 'ニット・セーター' });
    await page.getByTestId('save-listing').click();
    await expect(page.getByTestId('save-message')).toContainText('保存しました');

    await page.reload();
    await page.getByTestId('listing-details-toggle').click();
    await expect(page.getByRole('checkbox', { name: 'アウター', exact: true })).toBeChecked();
    await expect(page.getByTestId('primary-category')).toHaveValue(await page.getByRole('checkbox', { name: 'ニット・セーター', exact: true }).getAttribute('value'));
});

// 管理画面の API を、ログイン中の画面から呼ぶ（下ごしらえ）
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

// テーマの区画で使っているカテゴリは消せず、使っている所が出る
test('区画で使っているカテゴリを消そうとすると、使っている所が出て断られる', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ theme: 'default' });
    await page.goto(`/tenants/${tenant.id}/categories`);
    await expect(page.getByRole('heading', { name: 'カテゴリ', exact: true })).toBeVisible();

    // 下ごしらえ: カテゴリを作り、トップの「カテゴリ」の区画で選んで下書きに保存する（ページの編集の画面と同じ API）
    const created = await call(page, 'POST', `/api/tenants/${tenant.id}/categories`, { name: 'ニット', slug: 'knit' });
    expect(created.status).toBe(201);
    const knit = created.body.categories.find((c) => c.slug === 'knit').id;
    const saved = await call(page, 'PUT', `/api/tenants/${tenant.id}/themes/default/pages/home/draft`, {
        version_id: null, revision: 0, sections: [{ type: 'categories', values: { list: [knit] } }],
    });
    expect(saved.status).toBe(200);

    await page.reload();
    const row = page.getByTestId('category-row').filter({ hasText: 'ニット' });
    await row.getByTestId('category-delete').click();
    await row.getByTestId('category-confirm-delete').click();
    await expect(page.getByText('このカテゴリは『トップ』の『カテゴリ』で使われています。ページの編集でセクションからこのカテゴリを外してから削除してください。')).toBeVisible();
    await expect(page.getByTestId('category-row')).toHaveCount(1);
});
