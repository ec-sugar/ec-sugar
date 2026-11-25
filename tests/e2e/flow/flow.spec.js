import { existsSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { shopUrl } from './config.js';
import { runFlow } from './runner.js';
import { CARDS, intentOf, konbiniAvailable, payOnCheckout, refundedOn, stripe } from './stripe.js';
import {
    addToCart, adminPage, checkCount, customerPage, flowState, journalSummary, loginAsMember, mailSubjects, note, openOrder, order, orderNumberOnComplete,
    passkeyIfAsked, placeOrder, recordResult, resetChecks, same, stock, stripeInLedger, toConfirm, until,
} from './support.js';

// 通しの確かめ（docs/development/flow-check.md）。本物の Stripe（テストモード）で、ショップで買う → Stripe の画面 → 通知で確定 →
// 管理画面で発送・返金 → 台帳・在庫・受渡と Stripe の側の値を照らす。値の違いは止めずに数え（NG の行）、場面の終わりにまとめて落とす。
// 場面ごとに別の商品を使い（在庫の動きが混ざらない）、前の場面の結果に頼らない。札 @flow（playwright.config.js の CI の流れからは外す）

const shop = flowState();
const S = shop.id;
let admin;

test.beforeAll(async ({ browser }) => {
    if (shop.missing) {
        throw new Error('試しの店がありません（npm run e2e:flow で流してください。下ごしらえが店を作ります）');
    }
    admin = await adminPage(browser, S);
});

test.beforeEach(() => resetChecks());

test.afterEach(async ({}, testInfo) => {
    // 落ちた時は管理画面の写しも残す（お客さんの画面の写しは Playwright が撮る）
    if (testInfo.status !== testInfo.expectedStatus && admin) {
        await admin.screenshot({ path: testInfo.outputPath('admin.png'), fullPage: true }).catch(() => {});
    }
    recordResult(testInfo);
    expect(checkCount(), '照らし合わせの違い（上の NG の行）').toBe(0);
});

const qty = (title) => stock(S)[title];

/** 完了の画面で「お支払いが済みました」まで待つ（通知が届くまで、開き直す） */
async function waitPaidOnShop(page) {
    for (let i = 0; i < 30; i++) {
        if (await page.getByTestId('payment-paid').isVisible()) {
            return true;
        }
        await page.waitForTimeout(3_000);
        await page.reload();
    }

    return page.getByTestId('payment-paid').isVisible();
}

/** カードで 1 点買って払い、注文の番号を返す */
async function buyWithCard(browser, listing, { email, card = CARDS.ok } = {}) {
    const page = await customerPage(browser);
    await addToCart(page, S, listing);
    await toConfirm(page, S, { email });
    await placeOrder(page);
    await payOnCheckout(page, { email, card });
    await page.waitForURL(/\/checkout\/complete/, { timeout: 60_000 });
    const id = await orderNumberOnComplete(page);
    same('ショップの完了の画面に「お支払い済み」', await waitPaidOnShop(page), true);
    await page.context().close();

    return id;
}

/** 通知で払われたと記録されるまで待ち、Stripe の支払いと台帳を照らす */
async function expectPaid(id, total) {
    const state = await until(() => order(S, id), (s) => s.attempts.some((a) => a.status === 'paid'));
    same('支払いの記録が paid', state.attempts.map((a) => a.status), ['paid']);
    same('注文の合計', state.total, total);
    same('台帳の受け取り（支払い − 返金）', state.ledger.net_paid, total);
    const intent = await intentOf(state.attempts[0].ref);
    same('Stripe の支払いの状態', intent?.status, 'succeeded');
    same('Stripe で受け取った額', intent?.amount_received, total);
    same('台帳の Stripe の分 ＝ Stripe で受け取った額', stripeInLedger(state), intent?.amount_received);
    note('台帳の仕訳', journalSummary(state));

    return { state, intent };
}

async function ship(id) {
    await openOrder(admin, S, id);
    await admin.getByTestId('ship-tracking').fill('1234-5678-9012');
    await admin.getByTestId('ship').click();
    await expect(admin.getByTestId('order-status')).toHaveText('発送済み');
}

async function complete(id) {
    await openOrder(admin, S, id);
    await admin.getByTestId('complete').click();
    await admin.getByTestId('confirm-complete').click();
    await expect(admin.getByTestId('order-status')).toHaveText('完了');
}

test('1. カードで買い、通知で確定し、在庫が減り、発送して完了にする @flow', async ({ browser }) => {
    const title = '通し card';
    const before = qty(title);
    const id = await buyWithCard(browser, shop.listings.card, { email: 'flow-card@example.com' });

    await expectPaid(id, 1000);
    same('払った後の在庫（押さえた数が 1 増える）', qty(title), { quantity: before.quantity, reserved: before.reserved + 1 });

    await openOrder(admin, S, id);
    same('管理画面の注文の状態', (await admin.getByTestId('order-status').textContent()).trim(), '入金済み');
    await ship(id);
    same('発送の後の受渡', order(S, id).fulfillments.map((f) => f.status), ['shipped']);
    await complete(id);
    const done = order(S, id);
    same('完了の後の受渡', done.fulfillments.map((f) => f.status), ['completed']);
    same('完了の後の注文', done.status, 'completed');
    same('完了の後の在庫（出庫で数が 1 減り、押さえた数は戻る）', qty(title), { quantity: before.quantity - 1, reserved: before.reserved });

    // お客さんの注文の確認の画面
    const page = await customerPage(browser);
    await page.goto(shopUrl(S, '/orders'));
    await page.getByTestId('lookup-order-number').fill(id.toUpperCase());
    await page.getByTestId('lookup-email').fill('flow-card@example.com');
    await page.getByTestId('lookup-submit').click();
    same('ショップの注文の確認の状態', (await page.getByTestId('order-status-label').textContent()).trim(), '完了');
    await page.context().close();
});

/**
 * 管理画面の「返品・返金」で返す。item で品を 1 つ返す（restock で在庫に戻す）、amount で金額だけを返す。
 * 確かめの画面で断られたら、その文を返す（返金はしない）。通ったら null
 */
async function refundOnAdmin(id, { amount = null, item = false, restock = false, reason = 'returned' }) {
    await openOrder(admin, S, id);
    if (item) {
        await admin.getByTestId('return-quantity').fill('1');
        if (restock) {
            await admin.getByTestId('return-restock').check();
        }
    }
    if (amount !== null) {
        await admin.getByTestId('return-amount').fill(String(amount));
    }
    await admin.getByTestId('return-reason').selectOption(reason);
    await admin.getByTestId('return-check').click();
    await expect(admin.getByTestId('return-preview').or(admin.getByTestId('return-preview-error'))).toBeVisible();

    if (await admin.getByTestId('return-preview-error').isVisible()) {
        return (await admin.getByTestId('return-preview-error').textContent()).trim();
    }

    note('確かめの画面', (await admin.getByTestId('return-preview').innerText()).replace(/\s+/g, ' ').slice(0, 160));
    await admin.getByTestId('return-submit').click();
    await admin.getByTestId('return-confirm').click();
    await passkeyIfAsked(admin);
    await expect(admin.getByTestId('returns').getByRole('status')).toBeVisible();

    return null;
}

test('2a. 発送した注文を一部返金し、残りも返すと、台帳の残りが 0 になる（金額だけの返金では在庫は戻らない） @flow', async ({ browser }) => {
    const title = '通し refund';
    const before = qty(title);
    const id = await buyWithCard(browser, shop.listings.refund, { email: 'flow-refund@example.com' });
    const { intent } = await expectPaid(id, 1200);
    await ship(id);
    await complete(id);

    // 一部の返金（金額だけ 300 円。お詫び）
    same('一部の返金を頼めた', await refundOnAdmin(id, { amount: 300, reason: 'goodwill' }), null);
    const partial = await until(() => order(S, id), (s) => s.refunds.some((r) => r.status === 'succeeded'));
    same('一部の返金の記録', partial.refunds.map((r) => `${r.amount}:${r.status}`), ['300:succeeded']);
    same('一部の返金の後の台帳の受け取り', partial.ledger.net_paid, 900);
    same('一部の返金の後の Stripe の返金', (await refundedOn(intent.id)).succeeded, 300);
    same('管理画面の返金の札', (await admin.getByTestId('refund-summary-state').textContent()).includes('一部返金'), true);

    // 残りを品の返品（在庫に戻す）で返そうとすると、品の額（1,200）が残り（900）を越えて断られる（今の作り。報告に書いた）
    note('一部返金の後に品を返品して在庫に戻そうとした時', await refundOnAdmin(id, { item: true, restock: true }) ?? '通った');

    // 残り 900 円を金額だけで返す
    if (order(S, id).ledger.net_paid > 0) {
        same('残りの返金を頼めた', await refundOnAdmin(id, { amount: 900, reason: 'goodwill' }), null);
    }
    const full = await until(() => order(S, id), (s) => s.refunds.filter((r) => r.status === 'succeeded').length >= 2);
    same('返金の記録', full.refunds.map((r) => `${r.amount}:${r.status}`), ['300:succeeded', '900:succeeded']);
    same('全部の返金の後の台帳の受け取り', full.ledger.net_paid, 0);
    same('全部の返金の後の台帳の Stripe の分', stripeInLedger(full), 0);
    same('全部の返金の後の Stripe の返金の合計 ＝ 受け取った額', (await refundedOn(intent.id)).succeeded, 1200);
    same('管理画面の返金の札', (await admin.getByTestId('refund-summary-state').textContent()).includes('全額返金'), true);
    same('金額だけの返金では在庫は戻らない（出庫の 1 は減ったまま）', qty(title), { quantity: before.quantity - 1, reserved: before.reserved });
    note('台帳の口座の残り', full.ledger.balances);
    note('台帳の仕訳', journalSummary(full));
    note('注文の状態', `${full.status}・${full.phase}`);
});

test('2c. 発送した注文の品を在庫に戻す返品で全額を返すと、台帳の残りが 0 で在庫が戻る @flow', async ({ browser }) => {
    const title = '通し refund';
    const before = qty(title);
    const id = await buyWithCard(browser, shop.listings.refund, { email: 'flow-return@example.com' });
    const { intent } = await expectPaid(id, 1200);
    await ship(id);
    await complete(id);

    same('品の返品を頼めた', await refundOnAdmin(id, { item: true, restock: true }), null);
    const full = await until(() => order(S, id), (s) => s.refunds.some((r) => r.status === 'succeeded'));
    same('返金の記録', full.refunds.map((r) => `${r.purpose}:${r.amount}:${r.status}`), ['return:1200:succeeded']);
    same('返品の後の台帳の受け取り', full.ledger.net_paid, 0);
    same('Stripe の返金の合計 ＝ 受け取った額', (await refundedOn(intent.id)).succeeded, 1200);
    same('返品で在庫に戻る（出庫で減った 1 が戻る）', qty(title), before);
    note('台帳の口座の残り', full.ledger.balances);
});

test('2b. 払われた注文を発送の前に取り消すと、全額を返金して在庫の押さえが戻る @flow', async ({ browser }) => {
    const title = '通し cancel';
    const before = qty(title);
    const id = await buyWithCard(browser, shop.listings.cancel, { email: 'flow-cancel@example.com' });
    const { intent } = await expectPaid(id, 1300);

    await openOrder(admin, S, id);
    await admin.getByTestId('cancel-order').click();
    await expect(admin.getByTestId('cancel-confirm-text')).toContainText('¥1,300 を Stripe でお客さんに返金し');
    await admin.getByTestId('confirm-cancel').click();
    await passkeyIfAsked(admin);
    await expect(admin.getByTestId('order-status')).toHaveText('注文取消し');

    const state = await until(() => order(S, id), (s) => s.refunds.some((r) => r.status === 'succeeded'));
    same('取消の返金', state.refunds.map((r) => `${r.purpose}:${r.amount}:${r.status}`), ['cancel:1300:succeeded']);
    same('取消の後の注文', state.status, 'cancelled');
    same('取消の後の台帳の受け取り', state.ledger.net_paid, 0);
    same('Stripe の返金の合計', (await refundedOn(intent.id)).succeeded, 1300);
    same('取消の後の在庫（押さえが戻る）', qty(title), before);
    note('台帳の口座の残り', state.ledger.balances);
});

test('3a. 3-D セキュアを求めるカードで、認証を済ませて買える @flow', async ({ browser }) => {
    const title = '通し secure';
    const before = qty(title);
    const id = await buyWithCard(browser, shop.listings.secure, { email: 'flow-3ds@example.com', card: CARDS.secure });
    await expectPaid(id, 1400);
    same('在庫の押さえ', qty(title), { quantity: before.quantity, reserved: before.reserved + 1 });
});

test('3b. 断られるカードでは注文が確定せず、支払いの画面が閉じて期限が過ぎると取り消して在庫が戻る @flow', async ({ browser }) => {
    const title = '通し decline';
    const before = qty(title);
    const page = await customerPage(browser);
    await addToCart(page, S, shop.listings.decline);
    await toConfirm(page, S, { email: 'flow-decline@example.com' });
    await placeOrder(page);
    const message = await payOnCheckout(page, { email: 'flow-decline@example.com', card: CARDS.decline });
    note('Stripe の画面の断りの文', message);
    same('Stripe の画面のまま（ショップへ戻らない）', new URL(page.url()).hostname, 'checkout.stripe.com');

    // お客さんが離れた: 注文は入金待ちのまま、在庫は押さえたまま
    const sessionId = new URL(page.url()).pathname.split('/').find((p) => p.startsWith('cs_'));
    await page.context().close();
    same('断られた後の在庫（押さえたまま）', qty(title), { quantity: before.quantity, reserved: before.reserved + 1 });

    // Stripe で支払いの画面を閉じる（期限切れと同じ）→ 通知 checkout.session.expired
    const session = await stripe.session(sessionId);
    const orderId = session.client_reference_id;
    note('注文', orderId);
    await stripe.expireSession(sessionId);
    const expired = await until(() => order(S, orderId), (s) => s.attempts.some((a) => a.status === 'expired'));
    same('支払いの画面の記録が expired（通知で）', expired.attempts.map((a) => a.status), ['expired']);
    same('まだ確定していない', expired.ledger.net_paid, 0);

    // 払われないまま期限の過ぎた注文の見回り（この注文だけ、時刻を進めて）
    const after = runFlow('expire', S, orderId, 120);
    same('見回りの結果', after.outcome, 'cancelled');
    same('見回りの後の注文', after.status, 'cancelled');
    same('見回りの後の在庫（押さえが戻る）', qty(title), before);
});

test('4a. 銀行振込: 入金待ち → 店が入金を確かめる → 発送 → 完了 @flow', async ({ browser }) => {
    const title = '通し bank';
    const before = qty(title);
    const page = await customerPage(browser);
    await addToCart(page, S, shop.listings.bank);
    await toConfirm(page, S, { email: 'flow-bank@example.com', payment: 'bank_transfer' });
    await placeOrder(page);
    const id = await orderNumberOnComplete(page);
    await expect(page.getByTestId('bank-transfer-due')).toBeVisible();
    same('振込の額', (await page.getByTestId('bank-transfer-amount').textContent()).trim(), '¥1,600');
    await page.context().close();

    const waiting = order(S, id);
    same('振込の支払いの記録', waiting.attempts.map((a) => `${a.kind}:${a.status}`), ['direct:awaiting']);
    same('入金の前の台帳の受け取り', waiting.ledger.net_paid, 0);
    same('入金の前の在庫（押さえたまま）', qty(title), { quantity: before.quantity, reserved: before.reserved + 1 });

    await openOrder(admin, S, id);
    await admin.getByRole('button', { name: '入金を確認した' }).click();
    await admin.getByTestId('confirm-confirm-payment').click();
    await passkeyIfAsked(admin);
    await expect(admin.getByTestId('order-status')).toHaveText('入金済み');
    const paid = order(S, id);
    same('入金の後の支払いの記録', paid.attempts.map((a) => a.status), ['paid']);
    same('入金の後の台帳の受け取り', paid.ledger.net_paid, 1600);
    same('台帳の Stripe の分（振込は通らない）', stripeInLedger(paid), 0);

    await ship(id);
    await complete(id);
    same('完了の後の在庫', qty(title), { quantity: before.quantity - 1, reserved: before.reserved });
});

test('4b. 代金引換: 払う前に発送 → 受取拒否で取り消すと在庫に戻る。もう 1 つは代金を受け取って完了 @flow', async ({ browser }) => {
    const title = '通し cod';
    const before = qty(title);

    const buyCod = async (email) => {
        const page = await customerPage(browser);
        await addToCart(page, S, shop.listings.cod);
        await toConfirm(page, S, { email, payment: 'cod' });
        same('確認の画面の代引きの手数料', (await page.getByTestId('confirm-cod-fee').textContent()).includes('¥330'), true);
        await placeOrder(page);
        const id = await orderNumberOnComplete(page);
        await page.context().close();

        return id;
    };

    // 受取拒否
    const refused = await buyCod('flow-cod1@example.com');
    same('代引きの合計（1,700 ＋ 手数料 330）', order(S, refused).total, 2030);
    await ship(refused);
    await admin.getByRole('button', { name: '受取拒否で取り消す' }).click();
    await admin.getByTestId('refuse-cod-restock').check();
    await admin.getByTestId('confirm-refuse-cod').click();
    await passkeyIfAsked(admin);
    await expect(admin.getByTestId('order-status')).toHaveText('注文取消し');
    const r = order(S, refused);
    same('受取拒否の後の注文', r.status, 'cancelled');
    same('受取拒否の後の台帳の受け取り', r.ledger.net_paid, 0);
    same('受取拒否の後の在庫（戻す）', qty(title), before);
    note('受取拒否の受渡', r.fulfillments);

    // 受け取り
    const accepted = await buyCod('flow-cod2@example.com');
    await ship(accepted);
    await admin.getByRole('button', { name: '代引きの入金を確認した' }).click();
    await admin.getByTestId('confirm-confirm-payment').click();
    await passkeyIfAsked(admin);
    await expect(admin.getByTestId('confirm-payment')).toHaveCount(0);
    const a = order(S, accepted);
    same('代引きの入金の後の台帳の受け取り', a.ledger.net_paid, 2030);
    await complete(accepted);
    same('完了の後の在庫', qty(title), { quantity: before.quantity - 1, reserved: before.reserved });
});

test('5. コンビニ払い（テストモードで払いを確かめる） @flow', async ({ browser }) => {
    test.skip(!(await konbiniAvailable()), 'この Stripe のアカウントではコンビニ払いが有効でない（ダッシュボードの支払い方法で有効にすると流れる）');

    const page = await customerPage(browser);
    await addToCart(page, S, shop.listings.konbini);
    await toConfirm(page, S, { email: 'flow-konbini@example.com' });
    await placeOrder(page);
    await page.waitForURL(/^https:\/\/checkout\.stripe\.com\//);
    // テストモードのコンビニ払い: メールに succeed_immediately を含めると、すぐ払われた扱いになる（Stripe の文書）
    await page.locator('[data-testid="konbini-accordion-item-button"]').click();
    await page.locator('input#email').fill('succeed_immediately@example.com');
    await page.locator('[data-testid="hosted-payment-submit-button"]').click();
    await page.waitForURL(/\/checkout\/complete/, { timeout: 60_000 });
    const id = await orderNumberOnComplete(page);
    await page.context().close();
    const state = await until(() => order(S, id), (s) => s.attempts.some((a) => a.status === 'paid'));
    same('コンビニ払いの記録', state.attempts.map((a) => `${a.method}:${a.status}`), ['konbini:paid']);
    same('台帳の受け取り', state.ledger.net_paid, 1800);
});

test('6. 定期便: 申し込み（カードを預ける）→ 2 回目を毎日の処理で請求 → カードを替えて請求の失敗 → 知らせ @flow', async ({ browser }) => {
    test.skip(!existsSync('plugins/plugin-subscription/composer.json'), '定期便の App が入っていない木（公開用の 1.0。ADR 0098）');
    const email = `flow-teiki-${Date.now()}@example.com`;
    const page = await customerPage(browser);
    await loginAsMember(page, S, email);
    runFlow('member', S, email);

    // 申し込み（毎月・10% 引き。1 回目はカードを預けて払う）
    await page.goto(shopUrl(S, `/p/subscription/${shop.listings.teiki}`));
    await page.getByTestId('subscription-next').click();
    await expect(page.getByTestId('subscription-confirm')).toBeVisible();
    note('申し込みの確認の画面の毎回の額', (await page.getByTestId('subscription-total').textContent()).trim());
    await page.getByTestId('subscription-place').click();
    await payOnCheckout(page, { email });
    await page.waitForURL((url) => url.hostname !== 'checkout.stripe.com', { timeout: 60_000 });

    const first = await until(() => runFlow('subscription', S, email), (s) => s.subscriptions[0]?.status === 'active');
    const sub = first.subscriptions[0];
    same('定期便の状態', sub.status, 'active');
    note('預けたカード', first.cards);
    same('預けたカード（末尾と状態）', first.cards.filter((c) => c.endsWith(':usable')), ['4242:usable']);
    const firstOrder = await expectPaid(sub.first_transaction, 1800);
    same('1 回目の支払いはカードを預ける形（setup_future_usage）', firstOrder.intent?.setup_future_usage, 'off_session');

    // 2 回目: 毎日の処理を、起点から 1 か月後の日に進めて流す（この店だけ）
    const anchor = new Date(`${sub.anchor_on}T12:00:00+09:00`);
    const day = (months) => {
        const d = new Date(anchor);
        d.setMonth(d.getMonth() + months);

        return d.toISOString().slice(0, 10);
    };
    note('2 回目の処理', runFlow('run-subscriptions', S, day(1)));
    const second = await until(() => runFlow('subscription', S, email), (s) => s.subscriptions[0].rounds.some((r) => r.status === 'paid'));
    // 回の番号は 1 回目の注文の次から数える（回 1 が 2 回目のお届け）
    const round2 = second.subscriptions[0].rounds[0];
    note('回', second.subscriptions[0].rounds);
    same('2 回目の回の状態（通知で paid）', round2?.status, 'paid');

    if (round2?.transaction) {
        const r2 = order(S, round2.transaction);
        const intent = round2.charge_ref ? await intentOf(round2.charge_ref) : null;
        same('2 回目の注文の合計（2,000 × 0.9）', r2.total, 1800);
        same('2 回目の台帳の受け取り', r2.ledger.net_paid, 1800);
        same('2 回目の Stripe の請求（お客さんのいない請求）', intent?.amount_received, 1800);
        same('台帳の Stripe の分 ＝ Stripe の請求', stripeInLedger(r2), intent?.amount_received);
    }

    // 請求の失敗: マイページでカードを、預けられるが請求が断られるカードに替える
    await page.goto(shopUrl(S, '/p/subscription/account'));
    await page.getByTestId('subscription-card-change').click();
    await payOnCheckout(page, { email, card: CARDS.chargeFails });
    await page.waitForURL((url) => url.hostname !== 'checkout.stripe.com', { timeout: 60_000 });
    await page.waitForTimeout(5_000);
    note('3 回目の処理', runFlow('run-subscriptions', S, day(2)));
    const third = await until(() => runFlow('subscription', S, email), (s) => s.subscriptions[0].rounds[1]?.status === 'failed', { timeout: 60_000 });
    const round3 = third.subscriptions[0].rounds[1];
    same('替えた後の預けたカード', third.cards.filter((c) => c.endsWith(':usable')), ['0341:usable']);
    note('回', third.subscriptions[0].rounds);
    same('3 回目の回の状態（断られて試し直し待ち）', round3?.status, 'failed');
    same('3 回目の断りの数', round3?.declines, 1);
    same('定期便は続いている（止めるのは 4 回目の断り）', third.subscriptions[0].status, 'active');
    note('定期便の記録', third.subscriptions[0].events);

    // 知らせのメール（手元の Mailpit で見られるときだけ）
    await page.waitForTimeout(5_000);
    const mails = await mailSubjects(email);
    if (mails === null) {
        note('知らせのメール', 'Mailpit に届かないので確かめない');
    } else {
        note('届いたメール', mails);
        same('請求の失敗の知らせが届いた', mails.some((s) => /お支払い|決済|カード/.test(s) && /できません|失敗|通りません/.test(s)), true);
    }
    await page.context().close();
});

test('7. クーポン・ポイント・ギフトカードを使った注文は、台帳と Stripe の額が合う @flow', async ({ browser }) => {
    // クーポン（300 円引き）
    {
        const page = await customerPage(browser);
        await addToCart(page, S, shop.listings.coupon);
        await page.goto(shopUrl(S, '/cart'));
        await page.getByTestId('coupon-input').fill(shop.coupon);
        await page.getByTestId('coupon-apply').click();
        await expect(page.getByTestId('coupon-code')).toHaveText(shop.coupon);
        await toConfirm(page, S, { email: 'flow-coupon@example.com' });
        await expect(page.getByTestId('confirm-total')).toHaveText('¥1,800');
        await placeOrder(page);
        await payOnCheckout(page, { email: 'flow-coupon@example.com' });
        await page.waitForURL(/\/checkout\/complete/, { timeout: 60_000 });
        const id = await orderNumberOnComplete(page);
        await page.context().close();
        const { state } = await expectPaid(id, 1800);
        same('クーポンの値引き', state.coupon, { amount: 300, status: state.coupon?.status });
    }

    // ポイント（500 ポイント使う。会員）
    {
        const email = `flow-points-${Date.now()}@example.com`;
        const page = await customerPage(browser);
        await loginAsMember(page, S, email);
        runFlow('member', S, email);
        await addToCart(page, S, shop.listings.points);
        await toConfirm(page, S, { email, points: 500 });
        note('ポイントを使った確認の画面の合計', (await page.getByTestId('confirm-total').textContent()).trim());
        await placeOrder(page);
        await payOnCheckout(page, { email });
        await page.waitForURL(/\/checkout\/complete/, { timeout: 60_000 });
        const id = await orderNumberOnComplete(page);
        await page.context().close();
        const state = await until(() => order(S, id), (s) => s.attempts.some((a) => a.status === 'paid'));
        const intent = await intentOf(state.attempts[0].ref);
        same('使ったポイント', state.points?.spent, 500);
        same('Stripe で受け取った額（2,200 − 500）', intent?.amount_received, 1700);
        same('台帳の Stripe の分 ＝ Stripe で受け取った額', stripeInLedger(state), intent?.amount_received);
        note('ポイントの注文の合計と台帳', { total: state.total, net_paid: state.ledger.net_paid, journals: journalSummary(state) });
    }

    // ギフトカード（額面 1,000 円）
    {
        const page = await customerPage(browser);
        await addToCart(page, S, shop.listings.giftcard);
        await toConfirm(page, S, { email: 'flow-giftcard@example.com', giftCard: shop.giftCard });
        note('ギフトカードを使った確認の画面の合計', (await page.getByTestId('confirm-total').textContent()).trim());
        await placeOrder(page);
        await payOnCheckout(page, { email: 'flow-giftcard@example.com' });
        await page.waitForURL(/\/checkout\/complete/, { timeout: 60_000 });
        const id = await orderNumberOnComplete(page);
        await page.context().close();
        const state = await until(() => order(S, id), (s) => s.attempts.some((a) => a.status === 'paid'));
        const intent = await intentOf(state.attempts[0].ref);
        same('ギフトカードで払った額', state.gift_card?.amount, 1000);
        same('Stripe で受け取った額（2,300 − 1,000）', intent?.amount_received, 1300);
        same('台帳の受け取り（ギフトカード ＋ Stripe）', state.ledger.net_paid, 2300);
        same('台帳の Stripe の分 ＝ Stripe で受け取った額', stripeInLedger(state), intent?.amount_received);
        note('台帳の仕訳', journalSummary(state));
    }
});
