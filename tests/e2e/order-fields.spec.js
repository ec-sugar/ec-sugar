import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// 注文の項目（ADR 0066）。設定で欄を足して並べ替えると、ショップの注文の入力に「ご注文について」として出て、
// 必須の欄は答えないと進めず、答えは最終確認の画面に出る

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

test('注文の項目を足して並べ替えると、注文の入力に出て、必須の欄は答えないと進めず、答えが最終確認に出る', async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, stock: 5, theme: 'kinari' });
    await page.goto(`/tenants/${tenant.id}/settings/order-fields`);
    await expect(page.getByRole('heading', { name: '注文の項目', level: 1 })).toBeVisible();
    await expect(page.getByTestId('order-fields-empty')).toBeVisible();

    // 選ぶ（必須）
    await page.getByTestId('order-field-name-input').fill('ご用途');
    await page.getByTestId('order-field-type').selectOption('select');
    for (const option of ['お祝い', '内祝い']) {
        await page.getByTestId('order-field-options-input').fill(option);
        await page.getByTestId('order-field-options-input').press('Enter');
    }
    await page.getByTestId('order-field-required').check();
    await page.getByTestId('save-order-field').click();
    await expect(page.getByRole('status').filter({ hasText: '「ご用途」を追加しました。' })).toBeVisible();

    // 複数行の文（任意）
    await page.getByTestId('order-field-name-input').fill('ご要望');
    await page.getByTestId('order-field-type').selectOption('textarea');
    await page.getByTestId('save-order-field').click();
    await expect(page.getByRole('status').filter({ hasText: '「ご要望」を追加しました。' })).toBeVisible();
    await expect(page.getByTestId('order-field-name')).toHaveText(['ご用途', 'ご要望']);

    // 並べ替え（ご要望を上へ）
    await page.getByTestId('order-field-row').nth(1).getByTestId('order-field-up').click();
    await expect(page.getByTestId('order-field-name')).toHaveText(['ご要望', 'ご用途']);

    // ショップの注文の入力
    await page.route('**/postal/*', (route) => route.fulfill({ json: { addresses: [] } }));
    await page.goto(storefront(tenant, '/products'));
    await page.getByTestId('product-card').first().click();
    await page.getByTestId('add-to-cart').click();
    await expect(page.getByTestId('cart-count').first()).toHaveText('1');
    await page.goto(storefront(tenant, '/checkout'));
    const fields = page.getByTestId('order-fields');
    await expect(fields).toBeVisible();
    await expect(fields.getByTestId('order-field')).toHaveCount(2);
    await page.getByTestId('buyer-email').fill('hanako@example.com');
    await page.getByTestId('buyer-name').fill('山田 花子');
    await page.getByTestId('buyer-phone').fill('09012345678');
    await page.getByTestId('buyer-postal').fill('1500001');
    await page.getByTestId('buyer-prefecture').selectOption('東京都');
    await page.getByTestId('buyer-city').fill('渋谷区');
    await page.getByTestId('buyer-line1').fill('神宮前 1-2-3');
    await fields.getByLabel('ご要望').fill('午前中に届くと助かります');

    // 必須の選ぶ欄を選ばずに進むと、欄の下に理由が出て、書いた文は残る
    await page.getByTestId('to-confirm').click();
    await expect(page).toHaveURL(storefront(tenant, '/checkout'));
    await expect(fields.getByTestId('order-field-error')).toHaveText('「ご用途」を選んでください。');
    await expect(fields.getByLabel('ご要望')).toHaveValue('午前中に届くと助かります');

    await fields.getByLabel('ご用途').selectOption('内祝い');
    await page.getByTestId('to-confirm').click();
    await expect(page).toHaveURL(storefront(tenant, '/checkout/confirm'));
    await expect(page.getByTestId('order-fields-summary-row')).toHaveText([/ご要望\s*午前中に届くと助かります/, /ご用途\s*内祝い/]);
});
