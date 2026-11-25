import { expect, test } from './support/fixtures.js';

// 取引の閲覧。個人情報は「表示する」まで画面に出ない

test('取引の一覧から詳細へ。個人情報は「表示する」まで出ない', async ({ page, operator, makeTenant }) => {
    void operator;
    const { id: tenant, transaction } = makeTenant({ transaction: true });

    await page.goto(`/tenants/${tenant}`);
    await page.getByRole('navigation', { name: '店のメニュー' }).getByRole('link', { name: '注文' }).click();
    await expect(page.getByTestId('transaction-row')).toHaveCount(1);
    await expect(page.getByTestId('transaction-row')).toContainText('顧客あり');
    await expect(page.locator('main')).not.toContainText(transaction.customer);

    await page.getByTestId('transaction-row').getByRole('link').click();
    await expect(page).toHaveURL(new RegExp(`/transactions/${transaction.id}$`));
    await expect(page.getByRole('list', { name: '注文の進み具合' }).locator('[aria-current="step"]')).toHaveText('配送');
    await expect(page.getByTestId('line-row')).toHaveCount(1);
    await expect(page.getByTestId('journal-row').first()).toBeVisible();
    await expect(page.getByTestId('event-row').first()).toContainText('transaction.opened');
    await expect(page.locator('main')).not.toContainText(transaction.customer);
    await expect(page.locator('main')).not.toContainText(transaction.tracking);

    await page.getByTestId('show-personal').click();
    await expect(page.getByTestId('personal-customer')).toContainText(transaction.customer);
    await expect(page.locator('main')).toContainText(transaction.tracking);
    await page.getByTestId('hide-personal').click();
    await expect(page.locator('main')).not.toContainText(transaction.customer);

    // 記録の確かめは「設定 › 記録」（で概要から移した）
    await page.getByTestId('nav-settings').click();
    await page.getByTestId('settings-records').click();
    await page.getByTestId('verify-events').click();
    await expect(page.getByTestId('verify-result')).toContainText('改竄は見つかりませんでした');
});
