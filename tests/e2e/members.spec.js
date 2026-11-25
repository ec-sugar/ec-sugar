import { expect, test } from './support/fixtures.js';

// 運営のメンバーと権限。オーナーがメンバーを足し（パスキーで確かめる）、店の権限を与える。ログインの履歴

test('オーナーがメンバーを足して店の権限を与え、ログインの履歴を見る', async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant();
    const email = `staff-${Date.now()}@example.com`;

    // 運営全体の画面は、店の切り替えの中から開く
    await page.getByRole('button', { name: /店の切り替えと運営全体/ }).click();
    await page.getByTestId('nav-members').click();
    await expect(page.getByRole('heading', { name: 'メンバーと権限', level: 1 })).toBeVisible();
    await page.getByTestId('member-name').fill('山田 スタッフ');
    await page.getByTestId('member-email').fill(email);
    await page.getByTestId('add-member').click();

    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await expect(dialog).toBeVisible();
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();
    await expect(page.getByTestId('enrollment-url')).toContainText('/enroll#');

    // 足したメンバーはスタッフで、店の権限は「なし」から
    const grants = page.getByTestId(`grants-${email}`);
    const row = grants.getByTestId('grant-row').filter({ hasText: tenant.id });
    await expect(row.getByTestId('grant-level')).toHaveValue('none');
    await row.getByTestId('grant-level').selectOption('read');
    await expect(page.getByRole('status')).toContainText('の権限を「閲覧」にしました');
    await page.reload();
    await expect(page.getByTestId(`grants-${email}`).getByTestId('grant-row').filter({ hasText: tenant.id }).getByTestId('grant-level')).toHaveValue('read');

    await page.getByRole('button', { name: /店の切り替えと運営全体/ }).click();
    await page.getByRole('link', { name: 'ログインの履歴' }).click();
    await expect(page.getByTestId('login-row').first()).toContainText('ログイン');
});
