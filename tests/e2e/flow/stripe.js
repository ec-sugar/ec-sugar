import { expect } from '@playwright/test';
import { stripeSecretKey } from './config.js';

// Stripe のテストモードの API を読む（台帳と照らす）と、Stripe Checkout の画面にテストカードを入れる。
// 鍵は .env から読み、表示しない。ここから書くのは支払いの画面を閉じることだけ（お金は動かさない）

// Stripe の公開のテストカード（https://docs.stripe.com/testing）
export const CARDS = {
    ok: '4242424242424242',
    // いつも 3-D セキュアを求める
    secure: '4000002760003184',
    // いつも断られる（generic_decline）
    decline: '4000000000000002',
    // 預けるのは通るが、請求は断られる（定期便の 2 回目からの失敗）
    chargeFails: '4000000000000341',
};

async function call(method, path, params) {
    const body = params ? new URLSearchParams(params).toString() : undefined;
    const response = await fetch(`https://api.stripe.com/v1/${path}${method === 'GET' && body ? `?${body}` : ''}`, {
        method,
        headers: { Authorization: `Bearer ${stripeSecretKey()}`, ...(method !== 'GET' ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) },
        body: method !== 'GET' ? body : undefined,
    });
    const json = await response.json();

    if (!response.ok) {
        throw new Error(`Stripe ${method} ${path}: ${json.error?.message ?? response.status}`);
    }

    return json;
}

export const stripe = {
    session: (id) => call('GET', `checkout/sessions/${id}`, { 'expand[]': 'payment_intent' }),
    intent: (id) => call('GET', `payment_intents/${id}`),
    refunds: (intent) => call('GET', 'refunds', { payment_intent: intent, limit: '20' }),
    expireSession: (id) => call('POST', `checkout/sessions/${id}/expire`),
    methodConfigurations: () => call('GET', 'payment_method_configurations', { limit: '10' }),
};

// 支払いの記録の中の Stripe の支払い（pi_）を、支払いの画面（cs_）か請求（pi_）から引く
export async function intentOf(ref) {
    if (ref.startsWith('pi_')) {
        return stripe.intent(ref);
    }

    const session = await stripe.session(ref);

    return session.payment_intent && typeof session.payment_intent === 'object' ? session.payment_intent : null;
}

// その支払いの返金の合計（成功したものだけ）と、状態ごとの数
export async function refundedOn(intentId) {
    const list = (await stripe.refunds(intentId)).data;

    return {
        succeeded: list.filter((r) => r.status === 'succeeded').reduce((sum, r) => sum + r.amount, 0),
        statuses: list.map((r) => `${r.amount}:${r.status}`),
    };
}

// コンビニ払いがこのアカウントで使えるか（ダッシュボードで有効か）
export async function konbiniAvailable() {
    const configs = (await stripe.methodConfigurations()).data;

    return configs.some((c) => c.is_default && c.konbini?.available && c.konbini?.display_preference?.value === 'on');
}

/**
 * Stripe Checkout の画面でカードを入れて払う。3-D セキュアのカードは、テストの認証の画面で「完了」を押す（fail3ds で失敗を押す）。
 * 断られるカードは、画面に出た断りの文を返す（払えたら null）
 */
export async function payOnCheckout(page, { card = CARDS.ok, email = 'flow@example.com', name = 'TSUSHI HANAKO', fail3ds = false } = {}) {
    await page.waitForURL(/^https:\/\/checkout\.stripe\.com\//, { timeout: 60_000 });

    // メールの欄（店はメールを渡さないので、お客さんが入れる。ADR 0019）
    const emailInput = page.locator('input#email');
    await emailInput.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => {});
    if (await emailInput.isVisible() && await emailInput.isEditable()) {
        await emailInput.fill(email);
    }

    // 手段が 2 つ以上あると、手段ごとの開き閉じ（アコーディオン）になる。カードを開く
    const cardButton = page.locator('[data-testid="card-accordion-item-button"]');
    if (await cardButton.isVisible().catch(() => false)) {
        await cardButton.click();
    }

    await page.locator('input#cardNumber').fill(card);
    await page.locator('input#cardExpiry').fill('12 / 34');
    await page.locator('input#cardCvc').fill('123');
    const holder = page.locator('input#billingName');
    if (await holder.isVisible().catch(() => false)) {
        await holder.fill(name);
    }

    // Link に保存しない（電話番号を求められて止まらないように）
    const saveWithLink = page.locator('input#enableStripePass');
    if (await saveWithLink.isVisible().catch(() => false) && await saveWithLink.isChecked()) {
        await saveWithLink.uncheck();
    }

    await page.locator('[data-testid="hosted-payment-submit-button"]').click();

    if (card === CARDS.secure) {
        await completeThreeDSecure(page, fail3ds);
    }

    if (card === CARDS.decline || fail3ds) {
        const error = page.locator('[role="alert"], .FieldError, [data-testid="card-errors"]').filter({ hasText: /./ }).first();
        await expect(error).toBeVisible({ timeout: 30_000 });

        return (await error.textContent())?.trim() ?? '';
    }

    return null;
}

// テストモードの 3-D セキュアの画面（入れ子の iframe の中の「Complete」「Fail」）
async function completeThreeDSecure(page, fail) {
    // 入れ子の深さは Stripe の作りで変わるので、すべての iframe からボタンを探す
    const id = fail ? '#test-source-fail-3ds' : '#test-source-authorize-3ds';
    const label = fail ? /^\s*fail\s*$/i : /^\s*complete\s*$/i;

    for (let i = 0; i < 60; i++) {
        for (const frame of page.frames()) {
            const byId = frame.locator(id);
            const byText = frame.getByRole('button', { name: label });

            for (const candidate of [byId, byText]) {
                if (await candidate.first().isVisible().catch(() => false)) {
                    // 出たばかりの画面では押しても効かないことがある。ボタンが消えるまで押し直す
                    await page.waitForTimeout(1_000);
                    await candidate.first().click().catch(() => {});

                    // 消えた（画面ごと閉じた時は iframe が外れる）なら済み
                    const gone = await candidate.first().waitFor({ state: 'hidden', timeout: 5_000 }).then(() => true, (e) => /detached|closed|Target/i.test(String(e)));

                    if (gone) {
                        return;
                    }
                }
            }
        }
        await page.waitForTimeout(500);
    }

    throw new Error('3-D セキュアのテストの画面が出ませんでした');
}
