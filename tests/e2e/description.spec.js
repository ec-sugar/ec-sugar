import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// 商品の説明のブロック・仕様の表・短い説明（ADR 0032）。管理画面のエディタで作り、ショップでテーマの見た目で出る。
// 店の人の文は HTML でなく文字のまま出る。E2E_SCREENSHOTS=1 のときは写しも撮る（test-results/screens/description-*.png）

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

async function png(page, color, width = 800, height = 600) {
    const base64 = await page.evaluate(({ color, width, height }) => {
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');
        context.fillStyle = color;
        context.fillRect(0, 0, width, height);

        return canvas.toDataURL('image/png').split(',')[1];
    }, { color, width, height });

    return { name: 'detail.png', mimeType: 'image/png', buffer: Buffer.from(base64, 'base64') };
}

async function shoot(page, name) {
    if (process.env.E2E_SCREENSHOTS) {
        await page.screenshot({ path: `test-results/screens/${name}.png`, fullPage: true });
    }
}

test('説明をブロックで作り、並べ替えて保存すると、ショップに文字のまま出る', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, stock: 3 });
    await page.goto(`/tenants/${tenant.id}/listings`);
    await page.getByTestId('listing-row').first().getByRole('link').first().click();
    await expect(page.getByTestId('listing-title')).toHaveValue('商品 1');
    const listingId = new URL(page.url()).pathname.split('/').pop();
    const editor = page.getByTestId('description-editor');
    // ブロックの並び（写真の選び方の中の li は数えない）
    const blocks = editor.locator('ol[aria-label="説明のブロック"] > li');

    await page.getByTestId('listing-summary').fill('ふんわり軽い <i>ニット</i>');

    // 見出し → 段落 → 箇条書き → 表 → 注意書き → 写真の順に足す
    await page.getByTestId('add-heading').click();
    await editor.getByTestId('block-heading').getByTestId('block-text').fill('素材について');
    await page.getByTestId('add-paragraph').click();
    await editor.getByTestId('block-paragraph').getByTestId('block-text').fill('<b>綿</b> 100%\n肌ざわりのよい糸');
    await page.getByTestId('add-list').click();
    await editor.getByTestId('block-lines').fill('軽い\n\n涼しい');
    await page.getByTestId('add-table').click();
    const cells = editor.getByTestId('table-cell');
    await cells.nth(0).fill('サイズ');
    await cells.nth(1).fill('着丈');
    await cells.nth(2).fill('M');
    await cells.nth(3).fill('70');
    await page.getByTestId('add-note').click();
    await editor.getByTestId('block-note').getByText('ご注意').click();
    await editor.getByTestId('block-note').getByTestId('block-text').fill('洗濯機は使えません');
    await page.getByTestId('add-image').click();
    const photo = editor.getByTestId('block-photo');
    await photo.getByTestId('photo-open').click();
    await photo.getByTestId('file-input').setInputFiles(await png(page, '#6d8266'));
    await expect(photo.locator('img')).toBeVisible();
    await editor.getByTestId('block-caption').fill('着た様子');

    // キーボードで並べ替え: 段落の中で Alt + ↑ → 段落が 1 番目に
    await editor.getByTestId('block-paragraph').getByTestId('block-text').press('Alt+ArrowUp');
    await expect(blocks.first()).toHaveAttribute('data-test', 'block-paragraph');
    // ボタンでも戻せる（焦点は同じブロックのボタンに残る）
    await editor.getByTestId('block-paragraph').getByRole('button', { name: /を下へ/ }).click();
    await expect(blocks.first()).toHaveAttribute('data-test', 'block-heading');

    // 仕様の表
    await page.getByTestId('add-spec').click();
    await page.getByTestId('spec-label').fill('原産国');
    await page.getByTestId('spec-value').fill('日本');
    await shoot(page, 'description-editor');

    await page.getByTestId('save-listing').click();
    await expect(page.getByTestId('save-message')).toContainText('保存しました');
    await expect(page.getByText('バージョン 2')).toBeVisible();

    // 読み直しても同じ並び（箇条書きの空の行は外れる）
    await page.reload();
    await expect(blocks).toHaveCount(6);
    await expect(editor.getByTestId('block-lines')).toHaveValue('軽い\n涼しい');

    // 空の段落は、そのブロックの所に理由が出る（下の帯にも、保存できなかった節として）
    await page.getByTestId('add-paragraph').click();
    await page.getByTestId('save-listing').click();
    await expect(blocks.last()).toContainText('段落の文が空です');
    await expect(page.getByTestId('save-failed')).toContainText('基本の情報と説明');
    await blocks.last().getByRole('button', { name: /を削除/ }).click();
    // 消すと保存していない変更は無くなる（離れる前の確かめも出ない）
    await expect(page.getByTestId('unsaved')).toHaveCount(0);

    // ショップ: テーマの見た目で、文は文字のまま
    await page.goto(storefront(tenant, `/products/${listingId}`));
    const description = page.getByTestId('product-description');
    await expect(description.locator('h3')).toHaveText('素材について');
    await expect(description.locator('[data-block="paragraph"]')).toContainText('<b>綿</b> 100%');
    await expect(description.locator('b')).toHaveCount(0);
    await expect(description.locator('li')).toHaveText(['軽い', '涼しい']);
    await expect(description.locator('th')).toHaveText(['サイズ', '着丈']);
    await expect(description.locator('[data-tone="warning"]')).toContainText('洗濯機は使えません');
    const image = description.locator('figure img');
    expect(await image.evaluate((img) => img.decode().then(() => img.naturalWidth))).toBeGreaterThan(0);
    await expect(description.locator('figcaption')).toHaveText('着た様子');
    await expect(page.getByTestId('product-specs').locator('dt')).toHaveText('原産国');
    await expect(page.getByTestId('product-summary')).toHaveText('ふんわり軽い <i>ニット</i>');
    await shoot(page, 'description-storefront');

    // 一覧のカードにも短い説明
    await page.goto(storefront(tenant, '/products'));
    await expect(page.getByTestId('product-card-summary').first()).toHaveText('ふんわり軽い <i>ニット</i>');

    // 写真の画面: 説明に使っている写真は「使用中」で、消せない
    await page.goto(`/tenants/${tenant.id}/photos`);
    await expect(page.getByText('商品の説明（1 件）に使用中')).toBeVisible();
});
