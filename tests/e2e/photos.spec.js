import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// 商品の写真。管理画面で追加・並べ替え・削除し、店頭に出る。写真はブラウザの canvas で作る

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

// 色と大きさを指定した PNG を、ページの中の canvas で作る
async function photo(page, name, color, width = 600, height = 750) {
    const base64 = await page.evaluate(({ color, width, height }) => {
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');
        context.fillStyle = color;
        context.fillRect(0, 0, width, height);

        return canvas.toDataURL('image/png').split(',')[1];
    }, { color, width, height });

    return { name, mimeType: 'image/png', buffer: Buffer.from(base64, 'base64') };
}

test('写真を追加・並べ替え・削除し、1 枚目が店頭に出る（画像でないファイルは断る）', async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1 });
    await page.goto(`/tenants/${tenant.id}`);
    await page.getByTestId('open-listings').click();
    await page.getByTestId('listing-row').first().getByRole('link').first().click();
    await expect(page.getByTestId('listing-title')).toHaveValue('商品 1');

    // 2 枚を一度に。画像でないファイルは理由を添えて断る
    const notImage = { name: 'note.png', mimeType: 'image/png', buffer: Buffer.from('<svg onload="alert(1)"/>') };
    await page.getByTestId('file-input').setInputFiles([await photo(page, 'red.png', '#c0392b'), notImage, await photo(page, 'blue.png', '#2c3e9b', 1200, 900)]);
    await expect(page.getByRole('status')).toContainText('写真を 2 枚追加しました');
    await expect(page.getByTestId('photo-errors')).toContainText('note.png');
    const tiles = page.getByTestId('photo-tile');
    await expect(tiles).toHaveCount(2);
    await expect(tiles.first()).toContainText('1 枚目（一覧に表示）');

    // 写真は管理画面の経路から読み込まれる（壊れていない）
    const loaded = await tiles.first().locator('img').evaluate((img) => img.decode().then(() => img.naturalWidth));
    expect(loaded).toBe(600);

    // 代わりの文字（入力欄を離れると保存）
    await tiles.nth(1).getByTestId('photo-alt').fill('青い背景の写真');
    await tiles.nth(1).getByTestId('photo-alt').blur();
    await expect(page.getByRole('status')).toContainText('代替テキストを保存しました');

    // 2 枚目を前へ → 青が 1 枚目になる
    await tiles.nth(1).getByTestId('photo-earlier').click();
    await expect(page.getByRole('status')).toContainText('並べ替えました');
    await expect(tiles.first().locator('img')).toHaveAttribute('alt', '青い背景の写真');

    // 店頭のトップは 1 枚目の写真を出す（代わりの文字つき）
    await page.goto(storefront(tenant));
    const image = page.getByTestId('product-card').first().locator('img');
    await expect(image).toHaveAttribute('alt', '青い背景の写真');
    expect(await image.evaluate((img) => img.decode().then(() => img.naturalWidth))).toBeGreaterThan(0);

    // 削除はその場で確かめてから
    await page.goBack();
    await tiles.first().getByTestId('remove-photo').click();
    await tiles.first().getByTestId('confirm-remove-photo').click();
    await expect(page.getByRole('status')).toContainText('写真を削除しました');
    await expect(tiles).toHaveCount(1);
});

test('同時に 12 枚送っても、1 つの商品の写真は 10 枚まで', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1 });
    await page.goto(`/tenants/${tenant.id}`);
    await page.getByTestId('open-listings').click();
    await page.getByTestId('listing-row').first().getByRole('link').first().click();
    await expect(page.getByTestId('listing-title')).toHaveValue('商品 1');
    const imagesPath = `${new URL(page.url()).pathname.replace('/tenants/', '/api/tenants/')}/images`;

    // 枚数の上限はロックの中で数えるので、並べて送っても超えない
    const statuses = await page.evaluate(async (path) => {
        const canvas = document.createElement('canvas');
        canvas.width = 40;
        canvas.height = 40;
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
        const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '');

        return Promise.all(Array.from({ length: 12 }, () => {
            const body = new FormData();
            body.append('image', blob, 'p.png');

            return fetch(path, { method: 'POST', body, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf } }).then((r) => r.status);
        }));
    }, imagesPath);

    expect(statuses.filter((s) => s === 201)).toHaveLength(10);
    expect(statuses.filter((s) => s === 422)).toHaveLength(2);

    await page.reload();
    await expect(page.getByTestId('photo-tile')).toHaveCount(10);
    await expect(page.getByTestId('file-input')).toBeDisabled();
});
