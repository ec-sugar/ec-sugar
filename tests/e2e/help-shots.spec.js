import { expect, test } from './support/fixtures.js';

// 説明のページ（resources/admin/help/）に載せる、こちらの画面の写しを撮る。HELP_SHOTS=1 のときだけ動く。
// 写しは resources/help/ に置き、管理画面のビルドに入る。Stripe などほかの会社の画面は撮らない（説明は文で）。
// 場面ごとに新しい店を作り、見本の値を入れてから撮る。お金や外のサービスの状態（Stripe・返金・発送前の注文）は、API の答えを差し替えて出す
// 撮り直し: HELP_SHOTS=1 npm run e2e -- help-shots.spec.js
test.skip(!process.env.HELP_SHOTS, '説明のページの写しを撮るときだけ（HELP_SHOTS=1）');

// 写しは 1100 × 900 の画面で撮る（ページの編集の画面だけ広く）
test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1100, height: 900 });
});

async function save(locator, name) {
    await locator.scrollIntoViewIfNeeded();
    await locator.screenshot({ path: `resources/help/${name}.png`, animations: 'disabled' });
}

// 欄の上から、多くても height だけ
async function saveTop(locator, name, height) {
    await locator.scrollIntoViewIfNeeded();
    const box = await locator.boundingBox();
    await locator.page().screenshot({ path: `resources/help/${name}.png`, clip: { x: box.x, y: box.y, width: box.width, height: Math.min(height, box.height) }, animations: 'disabled' });
}

// 見出し（h2）で白いカードを探す
function panel(page, title) {
    return page.locator('section, form').filter({ has: page.getByRole('heading', { level: 2, name: title, exact: true }) }).first();
}

// 中身の欄（main）の上から height だけ
async function top(page, name, height) {
    await page.evaluate(() => window.scrollTo(0, 0));
    const box = await page.locator('main').boundingBox();
    await page.screenshot({ path: `resources/help/${name}.png`, clip: { x: box.x, y: box.y, width: box.width, height: Math.min(height, box.height) }, animations: 'disabled' });
}

// 色を塗った PNG（写真の見本）
async function png(page, name, color, width = 600, height = 600) {
    const base64 = await page.evaluate(({ fill, w, h }) => {
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const context = canvas.getContext('2d');
        context.fillStyle = fill;
        context.fillRect(0, 0, w, h);

        return canvas.toDataURL('image/png').split(',')[1];
    }, { fill: color, w: width, h: height });

    return { name, mimeType: 'image/png', buffer: Buffer.from(base64, 'base64') };
}

const connect = (connected, status) => ({ available: true, connected, status, account_hint: connected ? 'kZLr' : null, mode: connected ? 'test' : null });

async function shootStripe(page, tenant, state, name) {
    await page.route(`**/api/tenants/${tenant.id}/payment-settings`, (route) => route.fulfill({
        json: { stripe: { configured: false, mode: null, key_hint: null, updated_at: null }, connect: state, dashboard: { apikeys: 'https://dashboard.stripe.com/test/apikeys', webhooks: 'https://dashboard.stripe.com/test/webhooks', payment_methods: 'https://dashboard.stripe.com/test/settings/payment_methods' }, methods: { konbini: false, paypay: false, konbini_days: 3, paypay_available: true }, direct: { bank_transfer: false, bank_days: 7, bank_account: null, bank_account_set: false, cod: false, cod_fees: [{ below: 10000, fee: 330 }, { below: 30000, fee: 440 }, { below: 100000, fee: 660 }, { below: null, fee: 1100 }], cod_limit: 300000, account_types: { ordinary: '普通', checking: '当座', savings: '貯蓄' } }, webhook: { url: 'https://shop.example.jp/webhooks/stripe', events: ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired', 'payment_intent.succeeded', 'payment_intent.payment_failed', 'refund.created', 'refund.updated', 'refund.failed'] } },
    }));
    await page.goto(`/tenants/${tenant.id}/settings/payments`);
    const stripe = page.getByTestId('stripe-panel');
    await expect(stripe.getByRole('heading', { name: 'お支払い（Stripe）' })).toBeVisible();
    await expect(stripe.getByTestId(state.available ? 'stripe-connect' : 'stripe-key-steps')).toBeVisible();
    await save(stripe, name);
    await page.unroute(`**/api/tenants/${tenant.id}/payment-settings`);
}

test('説明のページの写し: お支払い（Stripe）', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ showcase: true });

    await shootStripe(page, tenant, connect(false, null), 'stripe-connect-start');
    await shootStripe(page, tenant, connect(true, 'onboarding'), 'stripe-connect-onboarding');
    await shootStripe(page, tenant, connect(true, 'active'), 'stripe-connect-active');
    await shootStripe(page, tenant, { available: false, connected: false, status: null, account_hint: null, mode: null }, 'stripe-keys');
});

test('説明のページの写し: はじめての設定', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ preparing: true, showcase: true });

    // ホームの開設の手順（「ようこそ」は後で見る）
    await page.goto(`/tenants/${tenant.id}`);
    await page.getByTestId('welcome-later').click();
    await save(page.getByTestId('setup'), 'setup-steps');

    // 特商法の表記（よく使う項目を並べ、頭の 3 つに見本を入れる）
    await page.goto(`/tenants/${tenant.id}/settings/legal`);
    await page.getByTestId('legal-template').click();
    const values = page.getByTestId('legal-value');
    await values.nth(0).fill('こもれび雑貨店（山田 花子）');
    await values.nth(1).fill('山田 花子');
    await values.nth(2).fill('請求があれば遅滞なく開示します');
    await page.evaluate(() => window.scrollTo(0, 0));
    await saveTop(panel(page, '特定商取引法に基づく表記'), 'legal', 560);

    // 送料
    await page.goto(`/tenants/${tenant.id}/settings/shipping`);
    await page.getByTestId('shipping-fee').fill('600');
    await page.getByTestId('free-shipping-over').fill('5000');
    await save(panel(page, '送料'), 'shipping');
});

test('説明のページの写し: 商品の登録', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ showcase: true });

    // 基本（新しい商品）
    await page.goto(`/tenants/${tenant.id}/listings/new`);
    await page.getByTestId('listing-title').fill('帆布のトートバッグ');
    await page.getByTestId('listing-amount').fill('5400');
    await save(page.locator('#listing-basic'), 'listing-basic');

    // 種類（色 × サイズ）とオプションを入れて公開する
    await page.getByTestId('file-input').first().setInputFiles([await png(page, 'front.png', '#c9b79c')]);
    await page.getByTestId('add-axis').click();
    await page.getByTestId('axis-name').fill('色');
    for (const value of ['生成り', '黒']) {
        await page.getByTestId('axis-values-input').fill(value);
        await page.getByTestId('axis-values-input').press('Enter');
    }
    await page.getByTestId('add-addon').click();
    await page.getByTestId('addon-kind').selectOption('text');
    await page.getByTestId('addon-name').fill('名入れ');
    await page.getByTestId('addon-amount').fill('500');
    await page.getByTestId('add-addon').click();
    await page.getByTestId('addon-kind').nth(1).selectOption('choice');
    await page.getByTestId('addon-name').nth(1).fill('ギフト包装');
    await page.getByTestId('addon-choice-label').first().fill('なし');
    await page.getByTestId('add-addon-choice').click();
    await page.getByTestId('addon-choice-label').nth(1).fill('あり');
    await page.getByTestId('addon-choice-amount').nth(1).fill('300');
    await page.getByTestId('publish-listing').click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/listings/[0-9a-z]{26}$`));
    await expect(page.getByTestId('variant-row')).toHaveCount(2);
    // 済んだ知らせを消し、欄を画面の上へ寄せてから撮る（下に浮かせた保存の帯と重ねない）
    await page.reload();
    await expect(page.getByTestId('variant-row')).toHaveCount(2);
    for (const [id, name] of [['listing-variants', 'listing-variants'], ['listing-addons', 'listing-addons'], ['listing-stock', 'listing-stock']]) {
        await page.locator(`#${id}`).evaluate((element) => element.scrollIntoView({ block: 'start' }));
        await page.locator(`#${id}`).screenshot({ path: `resources/help/${name}.png`, animations: 'disabled' });
    }
    await expect(page.getByTestId('open-preview')).toBeVisible();
    await top(page, 'listing-preview', 110);

    // カテゴリ
    await page.goto(`/tenants/${tenant.id}/categories`);
    for (const [name, slug, parent] of [['バッグ', 'bags', ''], ['トート', 'tote', 'バッグ'], ['キッチン', 'kitchen', '']]) {
        await page.getByTestId('category-name').fill(name);
        await page.getByTestId('category-slug').fill(slug);
        await page.getByTestId('category-parent').selectOption({ label: parent || 'なし（いちばん上の階層）' });
        await page.getByTestId('add-category').click();
        await expect(page.getByTestId('category-row').filter({ hasText: name }).first()).toBeVisible();
    }
    await top(page, 'categories', 640);
});

test('説明のページの写し: 注文と発送・返金', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ transaction: true, awaiting: 2, showcase: true });

    await page.goto(`/tenants/${tenant.id}/transactions`);
    await expect(page.getByTestId('transaction-row').first()).toBeVisible();
    await top(page, 'orders-list', 520);

    // 発送の前の注文（入金済み）の形に差し替えて、注文の操作の欄を撮る
    const detailUrl = `**/api/tenants/${tenant.id}/transactions/${tenant.transaction.id}`;
    await page.route(detailUrl, async (route) => {
        const response = await route.fetch();
        const json = await response.json();
        const order = json.transaction;
        order.order_status = 'paid';
        order.actions = { ship: true, complete: false, cancel: true };
        order.fulfillments = order.fulfillments.map((f) => ({ ...f, status: 'pending', carrier: null, shipped_at: null, completed_at: null }));
        await route.fulfill({ response, json });
    });
    await page.goto(`/tenants/${tenant.id}/transactions/${tenant.transaction.id}`);
    await page.getByTestId('ship-carrier').selectOption('yamato');
    await page.getByTestId('ship-tracking').fill('1234-5678-9012');
    await save(page.getByTestId('order-actions'), 'order-ship');
    await page.unroute(detailUrl);

    // 額が違って止めた返金の形に差し替えて、片付けの欄を撮る
    await page.route(detailUrl, async (route) => {
        const response = await route.fetch();
        const json = await response.json();
        const order = json.transaction;
        order.order_status = 'paid';
        order.actions = {};
        order.refunds = [{ id: '01k8z3x5y7a9b1c3d5e7f9g1h3', purpose: 'cancel', amount: order.total_amount, status: 'held', failure_reason: 'amount_mismatch', resolution: null, resolvable: true, created_at: order.created_at, settled_at: null }];
        await route.fulfill({ response, json });
    });
    await page.goto(`/tenants/${tenant.id}/transactions/${tenant.transaction.id}`);
    await save(page.getByTestId('refunds'), 'refund-resolve');
    await page.unroute(detailUrl);
});

test('説明のページの写し: お客さんと会員', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ customers: true, showcase: true });

    await page.goto(`/tenants/${tenant.id}/customers`);
    await expect(page.getByTestId('customer-row')).toHaveCount(4);
    await top(page, 'customers-list', 560);

    // 1 人の画面の「同じお客さんかもしれない記録」（名前と電話が同じでメールの違う 2 つ）
    await page.goto(`/tenants/${tenant.id}/customers/${tenant.customers.hanako.id}`);
    await expect(page.getByTestId('similar-row')).toHaveCount(2);
    await save(page.getByTestId('customer-similar'), 'customer-similar');
});

test('説明のページの写し: テーマとページの編集', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 3, showcase: true });

    await page.goto(`/tenants/${tenant.id}/theme`);
    await expect(page.getByTestId('current-theme')).toBeVisible();
    await top(page, 'theme', 760);

    // ページの編集の画面（広い画面で、全体を撮る）
    await page.setViewportSize({ width: 1400, height: 860 });
    await page.getByTestId('open-page-editor').click();
    await expect(page.getByRole('heading', { name: 'トップの編集' })).toBeVisible();
    const preview = page.frameLocator('[data-test="page-preview"]');
    await expect(preview.locator('[data-section]').first()).toBeVisible();
    await page.getByTestId('section-row').first().click();
    await page.screenshot({ path: 'resources/help/page-editor.png', animations: 'disabled' });

    // 区画を足す欄
    await page.getByTestId('add-section').click();
    const picker = page.getByTestId('add-section-menu');
    await expect(picker).toBeVisible();
    // 種類が多いので、上の方だけ（区画の並びの欄ごと）
    await page.getByTestId('add-section').scrollIntoViewIfNeeded();
    const button = await page.getByTestId('add-section').boundingBox();
    const menu = await picker.boundingBox();
    await page.screenshot({ path: 'resources/help/add-section.png', clip: { x: menu.x - 8, y: button.y - 8, width: menu.width + 16, height: Math.min(menu.y + menu.height - button.y + 16, 640) }, animations: 'disabled' });

    // ショップの写真
    await page.setViewportSize({ width: 1100, height: 900 });
    await page.goto(`/tenants/${tenant.id}/photos`);
    await page.getByTestId('file-input').setInputFiles([await png(page, 'shop-1.png', '#d8cbb3', 800, 600), await png(page, 'shop-2.png', '#9fb3a0', 800, 600), await png(page, 'shop-3.png', '#b7a1a8', 800, 600)]);
    await expect(page.getByTestId('media-alt')).toHaveCount(3);
    await page.getByTestId('media-alt').first().fill('店の入り口');
    await top(page, 'photos', 720);

    // 自由なページ
    await page.goto(`/tenants/${tenant.id}/pages`);
    await page.getByTestId('page-title').fill('私たちについて');
    await page.getByTestId('page-slug').fill('about');
    await page.getByTestId('add-page').click();
    await expect(page.locator('[data-test="page-row"][data-page-slug="about"]')).toBeVisible();
    await top(page, 'shop-pages', 760);
});

test('説明のページの写し: App と外部送信', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ showcase: true });

    await page.goto(`/tenants/${tenant.id}/plugins`);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('App');
    await top(page, 'apps', 640);

    await page.goto(`/tenants/${tenant.id}/plugins/ga4`);
    await expect(panel(page, '設定')).toBeVisible();
    await top(page, 'app-settings', 720);

    await page.goto(`/tenants/${tenant.id}/settings/consent`);
    await save(panel(page, '同意バナー'), 'consent');
});

test('説明のページの写し: 公開・メンバー・パスキー', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ preparing: true, showcase: true });

    await page.goto(`/tenants/${tenant.id}/settings/basic`);
    // 公開の状態と、準備中に入るパスワードの 2 つの欄
    await page.getByTestId('publish-password').fill('komorebi-2026');
    const status = await panel(page, '公開の状態').boundingBox();
    const password = await panel(page, '準備中に入るパスワード').boundingBox();
    await page.screenshot({ path: 'resources/help/publish.png', clip: { x: status.x, y: status.y, width: status.width, height: password.y + password.height - status.y }, animations: 'disabled', fullPage: true });

    await page.goto('/members');
    await expect(panel(page, 'メンバーと権限')).toBeVisible();
    await top(page, 'members', 720);

    await page.goto('/passkeys');
    await save(panel(page, '回復用のコード'), 'recovery-codes');
});
