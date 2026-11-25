import { expect, runFixture, test } from './support/fixtures.js';
import { port, stripeUrl } from './env.js';

// 会員のログイン（ADR 0044 の 3）。ショップのヘッダーの入口 → メール → 6 桁のコード → マイページの注文の履歴 → ログアウト。
// ブラウザのテストのメールは外へ出ない（MAIL_MAILER=array）。DB にはコードのハッシュだけなので、送ったコードは読めない。
// 「コードを送る」を押した後に、下ごしらえ（fixtures.php member-code）で同じメールへ新しいコードを出し直して入れる（前のコードは止まる）。
// CSRF（PHP のテストでは Laravel が検査を止める）と、ログインでセッションを作り直すことも、ここで確かめる

const shop = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

// お客さんとして注文して払う（ゲストのまま）。返すのは注文番号
async function orderAndPay(page, tenant, email) {
    await page.route('**/postal/*', (route) => route.fulfill({ json: { addresses: [] } }));
    await page.goto(shop(tenant, '/products'));
    await page.getByTestId('product-card').first().click();
    await page.getByTestId('add-to-cart').click();
    await expect(page.getByTestId('cart-count').first()).toHaveText('1');
    await page.goto(shop(tenant, '/checkout'));
    await page.getByTestId('buyer-email').fill(email);
    await page.getByTestId('buyer-name').fill('山田 花子');
    await page.getByTestId('buyer-phone').fill('09012345678');
    await page.getByTestId('buyer-postal').fill('1500001');
    await page.getByTestId('buyer-prefecture').selectOption('東京都');
    await page.getByTestId('buyer-city').fill('渋谷区');
    await page.getByTestId('buyer-line1').fill('神宮前 1-2-3');
    await page.getByTestId('to-confirm').click();
    await page.getByTestId('place-order').click();
    await expect(page).toHaveURL(new RegExp(`^${stripeUrl}/pay/cs_test_`));
    await page.getByTestId('fake-stripe-done').click();
    await expect(page.getByTestId('payment-paid')).toBeVisible();

    return (await page.getByTestId('order-number').textContent()).trim();
}

// このショップのセッションの Cookie（会員の印・カート・CSRF の Cookie は除く）
async function sessionCookie(page, tenant) {
    const cookies = await page.context().cookies(shop(tenant));
    const found = cookies.filter((cookie) => /session$/i.test(cookie.name));
    expect(found, 'セッションの Cookie が 1 つ').toHaveLength(1);

    return found[0].value;
}

// 2 つの箱が重ならないか
const apart = (a, b) => a.x + a.width <= b.x || b.x + b.width <= a.x || a.y + a.height <= b.y || b.y + b.height <= a.y;

// 画面のフォームの CSRF の合い言葉（最初の 1 つ）
const csrfToken = (page) => page.locator('input[name="_token"]').first().inputValue();

test('ヘッダーの「ログイン」からメールのコードで入り、マイページで注文の履歴を見て、ログアウトする', async ({ page, makeTenant }) => {
    const tenant = makeTenant({ listings: 1, stock: 5, stripe: true });
    const email = `hanako-${Date.now()}@example.com`;
    const number = await orderAndPay(page, tenant, email);

    // ログインの前: ヘッダーの入口は「ログイン」
    await page.goto(shop(tenant));
    const entry = page.getByTestId('account-link');
    await expect(entry).toHaveText('ログイン');
    await expect(entry).toHaveAttribute('href', '/account/login');
    await entry.click();
    await expect(page).toHaveURL(shop(tenant, '/account/login'));
    await expect(page.getByTestId('account-login')).toBeVisible();
    await expect(page.getByTestId('account-link')).toHaveAttribute('aria-current', 'page');

    // CSRF: 合い言葉の無い POST は断る（コードを送らない）
    const forged = await page.request.post(shop(tenant, '/account/login'), { form: { email }, maxRedirects: 0 });
    expect(forged.status()).toBe(419);

    await page.getByTestId('account-email').fill(email);
    await page.getByTestId('account-send').click();
    await expect(page).toHaveURL(shop(tenant, '/account/login/code'));
    await expect(page.getByTestId('account-code')).toContainText(email);
    const { code } = runFixture('member-code', tenant.id, email);

    // 正しいコードでも、合い言葉が無ければ断る（コードは使われずに残る）
    const forgedCode = await page.request.post(shop(tenant, '/account/login/code'), { form: { code }, maxRedirects: 0 });
    expect(forgedCode.status()).toBe(419);

    // 違うコードは断り、同じ欄へ戻す
    await page.getByTestId('account-code-input').fill(code === '000000' ? '000001' : '000000');
    await page.getByTestId('account-verify').click();
    await expect(page.getByTestId('account-error')).toContainText('コードが違うか、期限が切れました');

    // ログインの前のセッションと CSRF の合い言葉を控える
    const sessionBefore = await sessionCookie(page, tenant);
    const tokenBefore = await csrfToken(page);

    await page.getByTestId('account-code-input').fill(code);
    await page.getByTestId('account-verify').click();
    await expect(page).toHaveURL(shop(tenant, '/account'));

    // マイページ: ゲストの時の注文が履歴に並ぶ（中身だけ。名前・届け先・電話は出さない）
    await expect(page.getByTestId('account-email')).toHaveText(email);
    await expect(page.getByTestId('account-order')).toHaveCount(1);
    await expect(page.getByTestId('account-order')).toContainText(number);
    await expect(page.getByTestId('account-order-total')).toHaveText('¥1,000');
    for (const pii of ['山田', '神宮前', '09012345678']) {
        await expect(page.getByTestId('account-page')).not.toContainText(pii);
    }

    // セッションを作り直した: Cookie が変わり、CSRF の合い言葉も新しくなり、前の合い言葉ではログアウトできない
    expect(await sessionCookie(page, tenant)).not.toBe(sessionBefore);
    expect(await csrfToken(page)).not.toBe(tokenBefore);
    const stale = await page.request.post(shop(tenant, '/account/logout'), { form: { _token: tokenBefore }, maxRedirects: 0 });
    expect(stale.status()).toBe(419);

    // ヘッダーの入口は「マイページ」。ふつうの画面にメールは出ない
    await page.goto(shop(tenant));
    await expect(page.getByTestId('account-link')).toHaveText('マイページ');
    await expect(page.getByTestId('account-link')).toHaveAttribute('href', '/account');
    expect(await page.content()).not.toContain(email);
    await page.getByTestId('account-link').click();
    await expect(page).toHaveURL(shop(tenant, '/account'));

    // ログアウトするとトップへ戻り、入口は「ログイン」。マイページを開くとログインの画面へ
    await page.getByTestId('account-logout').click();
    await expect(page).toHaveURL(new RegExp(`^${shop(tenant)}/?$`));
    await expect(page.getByTestId('account-link')).toHaveText('ログイン');
    await page.goto(shop(tenant, '/account'));
    await expect(page).toHaveURL(shop(tenant, '/account/login'));
    await expect(page.getByTestId('account-login')).toBeVisible();
});

// ADR 0088: ふつうの画面で動く JavaScript（App のタグと同じ立場）は、お客さんの Cookie を持っていても、
// マイページを fetch（どの mode でも）・ブラウザの控え・別の窓のどれでも読めず、fetch で届け先を足せない。画面として開くのは今まで通り
test('ふつうの画面の JavaScript は、マイページを fetch・控え・別の窓で読めず、fetch で書き込めない', async ({ page, makeTenant }) => {
    const tenant = makeTenant({ listings: 1, stock: 5 });
    const email = `guard-${Date.now()}@example.com`;

    await page.goto(shop(tenant, '/account/login'));
    await page.getByTestId('account-email').fill(email);
    await page.getByTestId('account-send').click();
    await expect(page).toHaveURL(shop(tenant, '/account/login/code'));
    const { code } = runFixture('member-code', tenant.id, email);
    await page.getByTestId('account-code-input').fill(code);
    await page.getByTestId('account-verify').click();
    await expect(page).toHaveURL(shop(tenant, '/account'));
    await expect(page.getByTestId('account-email')).toHaveText(email);

    // ふつうの画面（タグを差し込む画面）から、同じオリジンの JavaScript として読む
    await page.goto(shop(tenant));
    const read = await page.evaluate(async () => {
        const attempt = async (path, init) => {
            try {
                const response = await fetch(path, { credentials: 'same-origin', ...init });

                return { status: response.status, type: response.type, text: response.type === 'opaque' ? '' : await response.text() };
            } catch (error) {
                return { status: 0, type: 'error', text: String(error) };
            }
        };

        return {
            cors: await attempt('/account', {}),
            sameOrigin: await attempt('/account', { mode: 'same-origin' }),
            noCors: await attempt('/account', { mode: 'no-cors' }),
            forceCache: await attempt('/account', { cache: 'force-cache' }),
            onlyCached: await attempt('/account', { mode: 'same-origin', cache: 'only-if-cached' }),
            code: await attempt('/account/login/code', {}),
            write: await attempt('/account/addresses', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ 'address[name]': '攻撃 太郎', 'address[phone]': '09000000000', 'address[postal]': '1000001', 'address[prefecture]': '東京都', 'address[city]': '千代田区', 'address[line1]': '1-1' }),
            }),
        };
    });

    for (const [how, result] of Object.entries(read)) {
        expect(result.text, how).not.toContain(email);
        expect(result.status, how).not.toBe(200);
    }
    expect(read.cors.status).toBe(403);
    expect(read.sameOrigin.status).toBe(403);
    expect(read.write.status).toBe(403);

    // window.open で開いた窓は、開いた画面から切り離される（Cross-Origin-Opener-Policy）
    const popup = page.waitForEvent('popup');
    const opened = await page.evaluate(async () => {
        const w = window.open('/account', '_blank');
        await new Promise((resolve) => { setTimeout(resolve, 2000); });

        try {
            return { closed: w === null || w.closed, text: w?.document?.body?.innerText ?? '' };
        } catch (error) {
            return { closed: true, text: String(error) };
        }
    });
    expect(opened.text).not.toContain(email);
    expect(opened.closed).toBe(true);
    await (await popup).close();

    // 画面として開くのは今まで通りで、届け先は足されていない
    await page.goto(shop(tenant, '/account/addresses'));
    await expect(page.getByTestId('address-item')).toHaveCount(0);
    await page.goto(shop(tenant, '/account'));
    await expect(page.getByTestId('account-email')).toHaveText(email);
});

test('会員の入口は 4 つのテーマのスマホの幅でも見えて押せる', { tag: '@nightly' }, async ({ page, makeTenant }) => {
    await page.setViewportSize({ width: 375, height: 740 });

    for (const theme of ['default', 'kinari', 'fashion', 'botanica']) {
        const tenant = makeTenant({ theme });
        await page.goto(shop(tenant));
        const entry = page.getByTestId('account-link');
        await expect(entry, theme).toBeVisible();
        // 読み上げの名前は「ログイン」（絵柄だけのテーマは aria-label、角砂糖は狭い画面で見えない文字）
        await expect(entry, theme).toHaveAccessibleName('ログイン');
        // 画面の幅に収まり、カートの入口と重ならない
        const box = await entry.boundingBox();
        const cart = await page.getByTestId('cart-link').boundingBox();
        expect(box.x, theme).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width, theme).toBeLessThanOrEqual(375);
        // 押せる大きさ（テーマの文字の基準で違うので、隣のカートの入口と同じ高さ以上）
        expect(box.height, `${theme}: 押せる大きさ`).toBeGreaterThanOrEqual(cart.height - 1);
        expect(apart(box, cart), `${theme}: カートと重ならない`).toBe(true);
        // 店の名前（長い名前の店。角砂糖はロゴに印が無いので見ない）とも重ならない
        if (await page.getByTestId('shop-logo').count() > 0) {
            expect(apart(box, await page.getByTestId('shop-logo').boundingBox()), `${theme}: 店の名前と重ならない`).toBe(true);
        }
        expect(await page.evaluate(() => document.documentElement.scrollWidth), `${theme}: 横にはみ出さない`).toBeLessThanOrEqual(375);

        await entry.click();
        await expect(page, theme).toHaveURL(shop(tenant, '/account/login'));
    }
});
