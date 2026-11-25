import { appendFileSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect } from '@playwright/test';
import { adminUrl, mailpitUrl, shopUrl, stripeSecretKey } from './config.js';
import { runFlow } from './runner.js';

// 通しの確かめの部品: 試しの店・照らし合わせ（違いを数えて読める形で出す）・お客さんの操作・管理画面の操作

export const outDir = join(process.cwd(), 'test-results', 'flow');

/** 下ごしらえ（global-setup）が作った店 */
export function flowState() {
    // 場面の一覧だけを出す時（--list）は下ごしらえが走らないので、空の店で読み込ませる（場面の前の beforeAll が止める）
    if (!process.env.FLOW_STATE) {
        return { id: '', listings: {}, missing: true };
    }

    return JSON.parse(process.env.FLOW_STATE);
}

// ---- 照らし合わせ ----

let ngCount = 0;
let lines = [];

export function resetChecks() {
    ngCount = 0;
    lines = [];
}

export function checkCount() {
    return ngCount;
}

/** 1 つの値を照らす。違っても止めずに数え（expect.soft）、最後に場面ごとに出す */
export function same(label, actual, expected) {
    const ok = JSON.stringify(actual) === JSON.stringify(expected);
    const line = ok ? `  OK  ${label}: ${fmt(actual)}` : `  NG  ${label}: 期待 ${fmt(expected)} ／ 実際 ${fmt(actual)}`;
    lines.push(line);
    console.log(line);

    if (!ok) {
        ngCount++;
    }

    expect.soft(actual, label).toEqual(expected);
}

/** 照らさずに値だけを残す（読み手が流れを追えるように） */
export function note(label, value) {
    const line = `  ..  ${label}: ${fmt(value)}`;
    lines.push(line);
    console.log(line);
}

function fmt(value) {
    return typeof value === 'string' ? value : JSON.stringify(value);
}

/** 場面の結果を test-results/flow/summary.json と、場面ごとの読み物（<場面>.txt）に残す */
export function recordResult(testInfo, extra = {}) {
    const file = join(outDir, 'summary.json');
    const rows = JSON.parse(readFileSync(file, 'utf8'));
    const skipNote = testInfo.annotations.find((a) => a.type === 'skip')?.description;
    rows.push({ title: testInfo.title.replace(/\s*@flow\s*/, ''), status: testInfo.status, ng: ngCount, note: extra.note ?? skipNote ?? null });
    writeFileSync(file, JSON.stringify(rows, null, 2));
    appendFileSync(join(outDir, 'checks.txt'), `\n## ${testInfo.title}（${testInfo.status}）\n${lines.join('\n')}\n`);
}

// ---- 店の値 ----

export const order = (shop, id) => runFlow('order', shop, id.toLowerCase());
export const stock = (shop) => runFlow('stock', shop);

/** 条件が満たされるまで店の値を読み直す（通知を待つ）。満たされなければ最後の値を返す（照らし合わせで NG になる） */
export async function until(read, done, { timeout = 90_000, every = 2_000 } = {}) {
    const start = Date.now();
    let value = read();

    while (!done(value) && Date.now() - start < timeout) {
        await new Promise((r) => setTimeout(r, every));
        value = read();
    }

    return value;
}

/** 台帳のうち、Stripe を通った分（決済代行の口座の動きのある支払い・返金の仕訳の、お客さんの口座の合計） */
export function stripeInLedger(state) {
    return state.ledger.journals
        .filter((j) => ['payment', 'refund'].includes(j.type) && Object.hasOwn(j.postings, 'provider'))
        .reduce((sum, j) => sum + j.payer, 0);
}

/** 台帳の仕訳の種類と額（読み物用） */
export function journalSummary(state) {
    return state.ledger.journals.map((j) => `${j.type}:${j.payer}`).join(' ');
}

// ---- メール（手元の Mailpit。届かなければ null） ----

export async function mailSubjects(to) {
    try {
        const response = await fetch(`${mailpitUrl}/api/v1/search?query=${encodeURIComponent(`to:"${to}"`)}&limit=50`);

        if (!response.ok) {
            return null;
        }

        return (await response.json()).messages.map((m) => m.Subject);
    } catch {
        return null;
    }
}

// ---- お客さん ----

export async function customerPage(browser) {
    const context = await browser.newContext({ locale: 'ja-JP' });
    const page = await context.newPage();
    // 郵便番号の答えは使わない（外の API に頼らない。住所は手で入れる）
    await page.route('**/postal/*', (route) => route.fulfill({ json: { addresses: [] } }));

    return page;
}

/** 商品の画面からカートに入れる */
export async function addToCart(page, shop, listing) {
    await page.goto(shopUrl(shop, `/products/${listing}`));
    await page.getByTestId('add-to-cart').click();
    await expect(page.getByTestId('cart-count').first()).toHaveText('1');
}

/** 注文の入力（ご依頼主と同じお届け先）→ 最終確認の画面。payment は stripe・bank_transfer・cod */
export async function toConfirm(page, shop, { email, payment = null, points = null, giftCard = null } = {}) {
    await page.goto(shopUrl(shop, '/checkout'));
    await page.getByTestId('buyer-email').fill(email);
    await page.getByTestId('buyer-name').fill('通し 花子');
    await page.getByTestId('buyer-phone').fill('09012345678');
    await page.getByTestId('buyer-postal').fill('1500001');
    await page.getByTestId('buyer-prefecture').selectOption('東京都');
    await page.getByTestId('buyer-city').fill('渋谷区');
    await page.getByTestId('buyer-line1').fill('神宮前 1-2-3');

    if (payment !== null) {
        await page.getByTestId(`payment-${payment}`).check();
    }

    if (points !== null) {
        await page.getByTestId('points-some').check();
        await page.getByTestId('points-amount').fill(String(points));
    }

    if (giftCard !== null) {
        await page.getByTestId('gift-card-code').fill(giftCard);
    }

    await page.getByTestId('to-confirm').click();
    await expect(page).toHaveURL(shopUrl(shop, '/checkout/confirm'));
}

/** 確定して、注文の番号（小文字）を返す。Stripe の画面へ移るときは移った後に返す */
export async function placeOrder(page) {
    await page.getByTestId('place-order').click();
    await page.waitForURL((url) => url.hostname === 'checkout.stripe.com' || url.pathname.endsWith('/checkout/complete'), { timeout: 60_000 });
}

export async function orderNumberOnComplete(page) {
    await expect(page.getByTestId('order-number')).toBeVisible();

    return (await page.getByTestId('order-number').textContent()).trim().toLowerCase();
}

/** 会員としてログインする（コードはメールを読まずに出し直す。tests/e2e/fixtures.php の member-code と同じ） */
export async function loginAsMember(page, shop, email) {
    await page.goto(shopUrl(shop, '/account/login'));
    await page.getByTestId('account-email').fill(email);
    await page.getByTestId('account-send').click();
    await expect(page).toHaveURL(shopUrl(shop, '/account/login/code'));
    const { code } = runFlow('member-code', shop, email);
    await page.getByTestId('account-code-input').fill(code);
    await page.getByTestId('account-verify').click();
    await expect(page).toHaveURL(shopUrl(shop, '/account'));
}

// ---- 管理画面 ----

const AUTHENTICATOR = { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true };

/**
 * 試しの店のスタッフ（お金の段階）として、仮想の認証器でパスキーを登録してログインした管理画面。
 * 店に Stripe の鍵が無ければ、支払いの設定の画面で .env のテストの鍵と通知の署名の鍵を置く
 */
export async function adminPage(browser, shop) {
    const context = await browser.newContext({ baseURL: adminUrl, locale: 'ja-JP' });
    const page = await context.newPage();
    const cdp = await context.newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    await cdp.send('WebAuthn.addVirtualAuthenticator', { options: AUTHENTICATOR });

    const { url } = runFlow('enroll', shop);
    const link = new URL(url);
    await page.goto(`${adminUrl}${link.pathname}${link.search}${link.hash}`);
    await page.getByTestId('wizard-start').click();
    await page.getByTestId('wizard-next').click();
    await page.getByTestId('passkey-name').fill('通しの確かめ');
    await page.getByTestId('enroll').click();
    await page.getByTestId('wizard-add-passkey').click();
    await expect(page).toHaveURL(/\/passkeys$/);

    await page.goto(`/tenants/${shop}/settings/payments`);
    await expect(page.getByTestId('stripe-keys')).toBeVisible();
    // Stripe へ接続が使える環境では、鍵を貼る形は畳んである
    // 「Stripe へ接続」が使える環境では、鍵を貼る形は畳んである（接続の状態を読み終えてから畳む形に変わるので、出るまで開き直す）
    const keys = page.getByTestId('stripe-keys');
    await expect(async () => {
        if (await keys.evaluate((el) => el.tagName === 'DETAILS' && !el.open)) {
            await keys.locator('summary').click();
        }
        await expect(page.getByTestId('stripe-status')).toBeVisible({ timeout: 1_000 });
    }).toPass({ timeout: 30_000 });

    if (await page.getByTestId('stripe-status').filter({ hasText: 'テスト用の鍵' }).count() === 0) {
        await page.getByTestId('stripe-secret-key').fill(stripeSecretKey());
        await page.getByTestId('stripe-webhook-secret').fill(process.env.FLOW_WEBHOOK_SIGNING ?? '');
        await page.getByTestId('save-stripe').click();
        await passkeyIfAsked(page);
        await expect(page.getByTestId('stripe-status')).toContainText('テスト用の鍵');
    }

    return page;
}

/** お金の操作の前の本人確認（直前に確かめていれば出ない） */
export async function passkeyIfAsked(page) {
    const dialog = page.getByRole('dialog', { name: '本人確認' });

    try {
        await dialog.waitFor({ state: 'visible', timeout: 5_000 });
    } catch {
        return;
    }

    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();
    await expect(dialog).toBeHidden();
}

export async function openOrder(page, shop, id) {
    await page.goto(`/tenants/${shop}/transactions/${id.toLowerCase()}`);
    await expect(page.getByTestId('order-status')).toBeVisible();
}
