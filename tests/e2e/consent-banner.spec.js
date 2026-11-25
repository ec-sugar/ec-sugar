import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// 解析・広告のタグの同意の帯（ADR 0034 の 4 の置き換え）。見本のタグの App（tag-sample。宛先は実在しない .test）で確かめる。
// 外へは実際に出ないよう、ページの外への要求をすべて止めて（中身の無い応えを返して）数えるだけにする。
// - 既定（帯を出さない店）: 帯は出ず、開いた時点でタグが動き、外への要求は App が宣言した宛先だけ。個人情報の画面では動かない
// - 帯を出す店: 同意の前は外へ何も送らない。「同意する」を押すとタグが動く
// CSP と Trusted Types の違反は、すべての場面の見張り（support/fixtures.js）が落とす

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;
const local = /^http:\/\/([a-z0-9-]+\.)?localhost(:\d+)?\//;

async function watchExternal(page) {
    const external = [];
    await page.context().route((url) => !local.test(url.href) && url.protocol !== 'data:', (route) => {
        external.push(route.request().url());

        return route.fulfill({ status: 204, body: '' });
    });

    return external;
}

test('同意の帯を出さない店（既定）では、帯を出さずに最初からタグを読み込み、宣言の宛先だけへ送る', async ({ page, makeTenant }) => {
    const tenant = makeTenant({ listings: 1, tags: true });
    const external = await watchExternal(page);

    await page.goto(storefront(tenant));
    await expect(page.getByTestId('consent-banner')).toHaveCount(0);
    await expect(page.getByTestId('consent-link')).toHaveCount(0);
    await expect(page.getByTestId('disclosure-link')).toHaveCount(1);
    // 見本のタグが動いた印と、ページの表示の送信（宣言した connect-src の宛先だけ）
    await expect(page.locator('html')).toHaveAttribute('data-tag-sample', 'general');
    await expect.poll(() => external.some((url) => url.startsWith('https://collect.example.test/collect'))).toBe(true);
    expect(external.every((url) => url.startsWith('https://collect.example.test/') || url.startsWith('https://tags.example.test/'))).toBe(true);

    // 個人情報の画面（ご注文の確認）では動かない
    const before = external.length;
    await page.goto(storefront(tenant, '/orders'));
    await expect(page).toHaveURL(/\/orders$/);
    await expect(page.locator('html')).not.toHaveAttribute('data-tag-sample', /.*/);
    expect(external.length).toBe(before);

    // 公表のページは、帯を出さない店の文
    await page.goto(storefront(tenant, '/legal/external'));
    await expect(page.getByTestId('disclosure-page')).toContainText('ショップのページを表示したときに行います');
});

test('同意の帯を出す店では、同意の前は外へ何も送らず、同意するとタグを読み込む', async ({ page, makeTenant }) => {
    const tenant = makeTenant({ listings: 1, tags: true, consentBanner: true });
    const external = await watchExternal(page);

    await page.goto(storefront(tenant));
    await expect(page.getByTestId('consent-banner')).toBeVisible();
    await expect(page.locator('html')).not.toHaveAttribute('data-tag-sample', /.*/);
    // 少し待っても外へは何も出ていない
    await page.waitForTimeout(300);
    expect(external).toEqual([]);

    await page.getByTestId('consent-accept').click();
    await expect(page.getByTestId('consent-banner')).toBeHidden();
    await expect(page.locator('html')).toHaveAttribute('data-tag-sample', 'general');
    await expect.poll(() => external.some((url) => url.startsWith('https://collect.example.test/collect'))).toBe(true);
    expect(external.every((url) => url.startsWith('https://collect.example.test/') || url.startsWith('https://tags.example.test/'))).toBe(true);
});
