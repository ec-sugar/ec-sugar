import { expect, test } from './support/fixtures.js';

// 他のサイトからの攻撃。PHP のテストの中では Laravel が CSRF の検査を止めるので、本物のサーバーとブラウザで確かめる

test('他のサイトから送られた変更の操作は断られ、リンクを踏んでもログアウトさせられない', async ({ page, operator }) => {
    void operator;
    const logout = new URL('/auth/logout', page.url()).href;
    const passkeys = new URL('/passkeys', page.url()).href;

    // 攻撃者のサイト: 開くと管理画面へ「ログアウト」を勝手に送るフォームと、管理画面へのリンク
    await page.route('http://attacker.test/**', (route) => route.fulfill({
        contentType: 'text/html',
        body: `<form id="f" method="POST" action="${logout}"></form><a id="link" href="${passkeys}">管理画面</a>`,
    }));

    // Laravel 13 は Sec-Fetch-Site が same-origin（管理画面そのもの）ならトークン無しで通し、それ以外はトークンを求める
    const refused = page.waitForResponse((response) => response.url() === logout);
    await page.goto('http://attacker.test/');
    await page.evaluate(() => document.getElementById('f').submit());
    expect((await refused).status()).toBe(419);
    await page.waitForURL(logout);

    // 断られた応答が新しいセッションの Cookie で本物を上書きしないので、ログインしたまま
    await page.goto('/tenants');
    await expect(page.getByTestId('operator-name')).toBeVisible();

    // 他のサイト（Slack やメールなど）のリンクから開いても、ログインしたまま
    await page.goto('http://attacker.test/');
    await page.locator('#link').click();
    await expect(page).toHaveURL(/\/passkeys$/);
    await expect(page.getByTestId('passkey-row')).toHaveCount(1);
});
