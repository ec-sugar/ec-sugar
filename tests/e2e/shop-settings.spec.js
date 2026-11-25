import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// 店の設定（で目次と 1 項目 1 画面に分けた）とテーマの中身（ADR 0028）。管理画面でテーマと店頭の文言を変え、店頭に出る。
// 中身はページの編集の画面で入れる（デザインのテーマの画面は札で選ぶだけ）

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

// ブラウザの中で JPEG を作る（写真の上げ下ろしのため）
async function jpeg(page) {
    const base64 = await page.evaluate(() => {
        const canvas = document.createElement('canvas');
        canvas.width = 1200;
        canvas.height = 600;
        const context = canvas.getContext('2d');
        context.fillStyle = '#6b8f71';
        context.fillRect(0, 0, 1200, 600);

        return canvas.toDataURL('image/jpeg', 0.8).split(',')[1];
    });

    return Buffer.from(base64, 'base64');
}

// 区画の中身はページの編集の画面だけで入れる。デザインのテーマの画面から「編集する」で入る（枠を許す殻を読むため、文書ごと読み直して入る）
async function openEditor(page, tenant) {
    await page.goto(`/tenants/${tenant.id}/theme`);
    await page.getByTestId('open-page-editor').click();
    await expect(page.getByRole('heading', { name: 'トップの編集' })).toBeVisible();
}

async function publishPage(page) {
    await page.getByTestId('publish-page').click();
    await page.getByTestId('confirm-publish-page').click();
    await expect(page.getByRole('status').filter({ hasText: '公開しました' })).toBeVisible();
}

test('テーマを替え、ページの編集の画面で文言を入れて公開すると店頭に出る（同時の編集は断る）', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1 });
    await page.goto(`/tenants/${tenant.id}`);
    await page.getByRole('navigation', { name: '店のメニュー' }).getByRole('link', { name: 'デザイン' }).click();
    await expect(page.getByRole('heading', { name: 'デザイン', level: 1 })).toBeVisible();

    // テーマの選び替えはデザインのテーマの画面の札から
    const kinari = page.getByTestId('theme-kinari');
    await kinari.getByTestId('choose-theme').click();
    await kinari.getByTestId('confirm-choose-theme').click();
    await expect(page.getByRole('status')).toContainText('ショップのテーマを「公式テーマ 1（kinari）」にしました');
    await expect(page.getByTestId('current-theme').getByTestId('theme-label')).toHaveText('公式テーマ 1（kinari）');

    // 文言（お知らせの帯は 1 つ目、ヒーローは 2 つ目の区画）。少し待つと自動で下書きに保存される
    await page.getByTestId('open-page-editor').click();
    await expect(page.getByRole('heading', { name: 'トップの編集' })).toBeVisible();
    const preview = page.frameLocator('[data-test="page-preview"]');
    await page.getByTestId('field-0-text').fill('税込 11,000 円以上で送料無料');
    await expect(preview.getByTestId('announcement')).toHaveText('税込 11,000 円以上で送料無料');
    await page.getByTestId('section-row').nth(1).getByTestId('section-select').click();
    await page.getByTestId('field-1-heading').fill('毎日に、ちょうどいい定番を。');
    await expect(preview.getByTestId('hero-heading')).toHaveText('毎日に、ちょうどいい定番を。');
    await publishPage(page);

    // 別の画面が先に保存していたら、こちらの保存は断られる
    const other = await page.evaluate(async (path) => {
        const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
        const headers = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token };
        const current = await (await fetch(path, { headers })).json();
        const sections = current.sections.map((s) => (s.type === 'announcement' ? { ...s, values: { text: '別の運営のお知らせ' } } : s));

        return (await fetch(`${path}/draft`, { method: 'PUT', headers, body: JSON.stringify({ version_id: current.version.id, revision: current.version.revision, sections }) })).status;
    }, `/api/tenants/${tenant.id}/themes/kinari/pages/home`);
    expect(other).toBe(200);
    await page.getByTestId('section-row').nth(0).getByTestId('section-select').click();
    await page.getByTestId('field-0-text').fill('こちらの変更');
    await expect(page.getByRole('alert')).toContainText('別の操作で先に更新されています');
    await page.getByTestId('reload-page').click();
    await expect(page.getByTestId('field-0-text')).toHaveValue('別の運営のお知らせ');

    // 店頭には公開した内容（別の画面の変更はまだ下書き）
    await page.goto(storefront(tenant));
    await expect(page.getByTestId('shop-logo')).toBeVisible();
    await expect(page.getByTestId('announcement')).toHaveText('税込 11,000 円以上で送料無料');
    await expect(page.getByTestId('hero-heading')).toHaveText('毎日に、ちょうどいい定番を。');
});

test('ヒーローの写真を置くと店頭のトップに出て、外すと消える', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, theme: 'kinari' });
    await openEditor(page, tenant);
    await page.getByTestId('section-row').nth(1).getByTestId('section-select').click();

    // 写真の欄から新しく上げると、ショップの写真に入り、そのまま選ばれる
    const picker = page.getByTestId('field-1-image');
    await picker.getByTestId('photo-open').click();
    await picker.getByTestId('file-input').setInputFiles({ name: 'hero.jpg', mimeType: 'image/jpeg', buffer: await jpeg(page) });
    const preview = picker.getByTestId('media-tile').locator('img');
    expect(await preview.evaluate((img) => img.decode().then(() => img.naturalWidth))).toBe(1000);
    await expect(page.frameLocator('[data-test="page-preview"]').getByTestId('hero-image')).toHaveCount(1);
    await publishPage(page);

    const shop = await page.context().newPage();
    await shop.goto(storefront(tenant));
    const hero = shop.getByTestId('hero-image');
    expect(await hero.evaluate((img) => img.decode().then(() => img.naturalWidth))).toBeGreaterThan(0);

    await picker.getByTestId('photo-clear').click();
    await expect(page.frameLocator('[data-test="page-preview"]').getByTestId('hero-image')).toHaveCount(0);
    await publishPage(page);
    await shop.reload();
    await expect(shop.getByTestId('hero-heading')).toBeVisible();
    await expect(shop.getByTestId('hero-image')).toHaveCount(0);
    await shop.close();
});

test('写真の画面で上げた写真をページの編集の画面で選ぶと、使っている間は消せず、外すと消せる（ADR 0028）', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, theme: 'kinari' });
    await page.goto(`/tenants/${tenant.id}`);
    await page.getByRole('link', { name: '写真' }).click();
    await expect(page.getByRole('heading', { name: '写真' })).toBeVisible();

    await page.getByTestId('file-input').setInputFiles({ name: 'shop.jpg', mimeType: 'image/jpeg', buffer: await jpeg(page) });
    await expect(page.getByRole('status')).toContainText('ショップの写真を 1 枚追加しました');
    const tile = page.getByTestId('media-tile');
    await expect(tile).toHaveCount(1);
    await tile.getByTestId('media-alt').fill('店の入口');
    await tile.getByTestId('media-alt').blur();
    await expect(page.getByRole('status')).toContainText('代替テキストを保存しました');

    // ページの編集の画面でヒーローに選んで公開すると、ショップのトップに出る
    await openEditor(page, tenant);
    await page.getByTestId('section-row').nth(1).getByTestId('section-select').click();
    const picker = page.getByTestId('field-1-image');
    await picker.getByTestId('photo-open').click();
    await picker.getByTestId('photo-choose').click();
    await expect(picker.getByTestId('media-tile')).toHaveCount(1);
    await expect(page.frameLocator('[data-test="page-preview"]').getByTestId('hero-image')).toHaveCount(1);
    await publishPage(page);
    await page.goto(storefront(tenant));
    await expect(page.getByTestId('hero-image')).toHaveCount(1);

    // 下書きでも使っている間は、写真の画面で消せない
    await page.goto(`/tenants/${tenant.id}/photos`);
    await expect(tile.getByTestId('media-badge')).toHaveText('テーマ「kinari」の「標準」に使用中');
    await expect(tile.getByTestId('remove-media')).toHaveCount(0);

    // 外して公開すると、写真は残り、消せるようになる
    await openEditor(page, tenant);
    await page.getByTestId('section-row').nth(1).getByTestId('section-select').click();
    await picker.getByTestId('photo-clear').click();
    await expect(page.frameLocator('[data-test="page-preview"]').getByTestId('hero-image')).toHaveCount(0);
    await publishPage(page);
    await page.goto(`/tenants/${tenant.id}/photos`);
    await expect(tile.getByTestId('media-badge')).toHaveCount(0);
    await tile.getByTestId('remove-media').click();
    await tile.getByTestId('confirm-remove-media').click();
    await expect(page.getByRole('status')).toContainText('写真を削除しました');
    await expect(page.getByTestId('media-tile')).toHaveCount(0);
});

test('特商法の表記をひな形から書いて並べ替えると、店頭にその順で出る。ほかの設定の画面で保存しても書きかけは消えない', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, theme: 'kinari' });
    // 前の店の設定の URL は、設定の目次になった
    await page.goto(`/tenants/${tenant.id}/settings`);
    await expect(page.getByRole('heading', { name: '設定', level: 1 })).toBeVisible();
    await page.getByTestId('settings-legal').click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/settings/legal$`));

    // ひな形で項目の名前が並ぶ（内容は店ごとに書く）
    await page.getByTestId('legal-template').click();
    await expect(page.getByTestId('legal-row')).toHaveCount(11);
    await expect(page.getByTestId('legal-label').first()).toHaveValue('販売業者');
    // 書きかけのまま目次から配送と送料の画面へ移って送料を保存しても、表記の入力は残る
    await page.getByTestId('legal-value').first().fill('山田商店');
    await expect(page.getByTestId('settings-unsaved')).toBeVisible();
    await page.getByRole('navigation', { name: '現在地' }).getByRole('link', { name: '設定' }).click();
    await page.getByTestId('settings-shipping').click();
    await page.getByTestId('shipping-fee').fill('550');
    await page.getByTestId('save-shipping').click();
    await expect(page.getByRole('status')).toContainText('送料を保存しました');
    await page.getByRole('navigation', { name: '現在地' }).getByRole('link', { name: '設定' }).click();
    await page.getByTestId('settings-legal').click();
    await expect(page.getByTestId('legal-row')).toHaveCount(11);
    await expect(page.getByTestId('legal-value').first()).toHaveValue('山田商店');
    await expect(page.getByTestId('settings-unsaved')).toBeVisible();

    // 2 つだけ残して書き、2 つ目を上へ
    while (await page.getByTestId('legal-row').count() > 2) {
        await page.getByRole('button', { name: `${await page.getByTestId('legal-row').count()} つ目を削除` }).click();
    }
    await page.getByTestId('legal-value').nth(1).fill('山田 花子');
    await page.getByRole('button', { name: '2 つ目を上へ' }).click();
    await expect(page.getByTestId('legal-label').first()).toHaveValue('運営統括責任者');
    await page.getByTestId('save-legal').click();
    await expect(page.getByRole('status')).toContainText('特定商取引法に基づく表記を保存しました');
    await expect(page.getByTestId('settings-unsaved')).toHaveCount(0);

    // 送料はサーバーに残っている（読み込み直すと、画面の写しではなくサーバーの値が出る）
    await page.goto(`/tenants/${tenant.id}/settings/shipping`);
    await expect(page.getByTestId('shipping-fee')).toHaveValue('550');

    await page.goto(storefront(tenant, '/legal/commerce'));
    const terms = page.locator('dt');
    await expect(terms).toHaveText(['運営統括責任者', '販売業者']);
    await expect(page.getByText('山田商店')).toBeVisible();
});

test('保存していない書きかけは、ログアウトすると捨てる（次にログインした人に見せない）', async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant();
    // ログインし直した後に店の一覧へ戻る形にする（店が 1 つだとホームがその店へ直接入る）。ほかの場面が作った店に頼らない（場面ごとに消すので）
    makeTenant({ listings: 0 });
    await page.goto(`/tenants/${tenant.id}/settings/legal`);
    await page.getByTestId('legal-template').click();
    await page.getByTestId('legal-value').first().fill('書きかけの販売業者');
    await expect(page.getByTestId('settings-unsaved')).toBeVisible();

    // 読み込み直さずに（画面の中で）ログアウトし、ログインし直して同じ画面へ戻る
    await page.getByRole('button', { name: 'ログアウト' }).click();
    await expect(page).toHaveURL(/\/login$/);
    await page.getByTestId('login').click();
    await expect(page).toHaveURL(/\/tenants$/);
    await page.getByRole('link', { name: tenant.name }).click();
    await page.getByTestId('nav-settings').click();
    await page.getByTestId('settings-legal').click();
    await expect(page.getByText('まだありません。')).toBeVisible();
    await expect(page.getByTestId('legal-row')).toHaveCount(0);
    await expect(page.getByTestId('settings-unsaved')).toHaveCount(0);
});

test('設定の目次から注文の案内の画面へ移って保存でき、読み込み直しても残る', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant();
    await page.goto(`/tenants/${tenant.id}/settings`);
    await page.getByTestId('settings-order-terms').click();
    await expect(page.getByRole('heading', { name: '注文の案内', level: 1 })).toBeVisible();
    await page.getByTestId('terms-delivery').fill('ご注文から 3〜5 日でお届けします');
    await page.getByTestId('terms-returns').fill('商品到着後 30 日以内、未使用のものに限ります');
    await page.getByTestId('save-terms').click();
    await expect(page.getByRole('status')).toContainText('注文の案内を保存しました');
    await page.reload();
    await expect(page.getByTestId('terms-delivery')).toHaveValue('ご注文から 3〜5 日でお届けします');
    await expect(page.getByTestId('terms-returns')).toHaveValue('商品到着後 30 日以内、未使用のものに限ります');

    // 入力の誤りは欄の下に出る（字数の上限を越えた文はサーバーが断る）
    await page.getByTestId('terms-delivery').evaluate((el) => el.removeAttribute('maxlength'));
    await page.getByTestId('terms-delivery').fill('あ'.repeat(201));
    await page.getByTestId('save-terms').click();
    await expect(page.getByRole('alert').first()).toBeVisible();
    await expect(page.getByRole('status')).toHaveCount(0);
});

test('同意の帯は既定で出さず、設定の目次の「同意の帯」で出すにすると、タグを使う店のショップに帯が出る', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, tags: true });
    await page.goto(storefront(tenant));
    await expect(page.locator('html')).toHaveAttribute('data-tag-sample', 'general');
    await expect(page.getByTestId('consent-banner')).toHaveCount(0);

    await page.goto(`/tenants/${tenant.id}/settings`);
    await page.getByTestId('settings-consent').click();
    await expect(page.getByRole('heading', { name: '同意バナー', level: 1 })).toBeVisible();
    await expect(page.getByTestId('consent-banner-off')).toBeChecked();
    await page.getByTestId('consent-banner-on').check();
    await page.getByTestId('save-consent-banner').click();
    await expect(page.getByRole('status')).toContainText('同意バナーの設定を保存しました');
    await page.reload();
    await expect(page.getByTestId('consent-banner-on')).toBeChecked();

    await page.goto(storefront(tenant));
    await expect(page.getByTestId('consent-banner')).toBeVisible();
    await expect(page.locator('html')).not.toHaveAttribute('data-tag-sample', /.*/);
});

test('Stripe の鍵はパスキーで確かめてから置け、画面には末尾 4 文字だけ出る', async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1 });
    await page.goto(`/tenants/${tenant.id}/settings`);
    await page.getByTestId('settings-payments').click();
    await expect(page.getByRole('heading', { name: '支払い', level: 1 })).toBeVisible();
    await expect(page.getByTestId('stripe-status')).toContainText('まだ設定していません');
    await expect(page.getByTestId('stripe-webhook')).toContainText(`${tenant.id}.localhost:${port}/webhooks/stripe`);

    // 手順が 3 つ。Stripe の画面へのリンクは新しいタブで、テスト環境の画面へ
    await expect(page.getByTestId('stripe-key-steps').locator('> li')).toHaveCount(3);
    await expect(page.getByRole('link', { name: 'API キー', exact: true })).toHaveAttribute('href', 'https://dashboard.stripe.com/test/apikeys');
    await expect(page.getByRole('link', { name: 'API キー', exact: true })).toHaveAttribute('target', '_blank');

    // 空のまま保存すると、ブラウザに黙って止めさせず、どの欄が空かを出す（サーバーへは送らない）
    await page.getByTestId('stripe-webhook-secret').fill('');
    await page.getByTestId('save-stripe').click();
    await expect(page.getByRole('alert').filter({ hasText: '空の欄があります' })).toBeVisible();
    await expect(page.getByText('秘密鍵が空です')).toBeVisible();
    await expect(page.getByText('Webhook の署名の鍵が空です')).toBeVisible();
    await expect(page.getByRole('dialog', { name: '本人確認' })).toBeHidden();

    // テストの値。秘密情報の検知に掛からないよう、鍵の形はその場で組み立てる
    const body = 'FakeForTestsOnly000';
    await page.getByTestId('stripe-secret-key').fill(['sk', 'test', `${body}4242`].join('_'));
    await page.getByTestId('stripe-webhook-secret').fill(['whsec', `${body}0123`].join('_'));
    await expect(page.getByTestId('stripe-secret-key')).toHaveAttribute('type', 'password');
    await page.getByTestId('save-stripe').click();

    // サーバーが再確認を求める。パスキーで確かめると、保存をやり直す。その間、押したボタンは回って押せない
    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await expect(dialog).toBeVisible();
    await expect(page.getByTestId('save-stripe')).toHaveAttribute('aria-busy', 'true');
    await expect(page.getByTestId('save-stripe').getByTestId('button-spinner')).toBeVisible();
    await expect(page.getByTestId('save-stripe')).toBeDisabled();
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();
    await expect(page.getByRole('status')).toContainText('Stripe の鍵を保存しました');
    await expect(page.getByTestId('save-stripe')).not.toHaveAttribute('aria-busy');
    await expect(page.getByTestId('stripe-status')).toContainText('テスト用の鍵');
    await expect(page.getByTestId('stripe-status')).toContainText('4242');
    // 鍵は入力欄にも残さない。読み込み直しても、鍵そのものはどこにも出ない
    await expect(page.getByTestId('stripe-secret-key')).toHaveValue('');
    // 保存済みと分かるよう、欄には伏せ字（本物の鍵ではなく test / live と末尾 4 文字だけ）
    await expect(page.getByTestId('stripe-secret-key')).toHaveAttribute('placeholder', 'sk_test_••••••••••••••••4242');
    await expect(page.getByTestId('stripe-webhook-secret')).toHaveAttribute('placeholder', 'whsec_••••••••••••••••');
    await page.reload();
    await expect(page.getByTestId('stripe-status')).toContainText('4242');
    expect(await page.content()).not.toContain(body);

    // 外す（確認は済んでいるので、もう聞かれない）
    await page.getByTestId('remove-stripe').click();
    await page.getByTestId('confirm-remove-stripe').click();
    await expect(page.getByRole('status')).toContainText('Stripe の鍵を削除しました');
    await expect(page.getByTestId('stripe-status')).toContainText('まだ設定していません');
});

test('支払いの画面から、写しつきの始め方の説明へ移れる', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant();
    await page.goto(`/tenants/${tenant.id}/settings/payments`);
    await page.getByTestId('help-payments-link').click();
    await expect(page).toHaveURL(new RegExp(`/help/payments\\?shop=${tenant.id}$`));
    await expect(page.getByRole('heading', { name: 'お支払い（Stripe）の始め方', level: 1 })).toBeVisible();
    await expect(page.getByTestId('help-section-connect').getByRole('listitem')).toHaveCount(4);

    // 写しは自分のオリジンから読まれて表示される（CSP の違反は自動で見張っている）
    const images = page.locator('main img');
    await expect(images).toHaveCount(4);
    for (const image of await images.all()) {
        await image.scrollIntoViewIfNeeded();
        await expect.poll(() => image.evaluate((img) => img.complete && img.naturalWidth > 0)).toBe(true);
        expect(new URL(await image.evaluate((img) => img.currentSrc)).origin).toBe(new URL(page.url()).origin);
    }

    // 直接開いても出る（管理画面の殻の経路）
    await page.reload();
    await expect(page.getByRole('heading', { name: 'お支払い（Stripe）の始め方', level: 1 })).toBeVisible();
});

test('Stripe の登録の画面から戻ると、確認が済むまで自動で確かめ直す', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant();
    // 銀行振込・代金引換の欄（ADR 0074）も同じ答えから描く
    const base = { stripe: { configured: false, mode: null, key_hint: null, updated_at: null }, webhook: { url: null, events: [] }, direct: { bank_transfer: false, bank_days: 7, bank_account: null, bank_account_set: false, cod: false, cod_fees: [{ below: 10000, fee: 330 }, { below: 30000, fee: 440 }, { below: 100000, fee: 660 }, { below: null, fee: 1100 }], cod_limit: 300000, account_types: { ordinary: '普通', checking: '当座', savings: '貯蓄' } } };
    const state = (status) => ({ ...base, connect: { available: true, connected: true, status, account_hint: 'kZLr', mode: 'test' } });
    let asked = 0;
    await page.route(`**/api/tenants/${tenant.id}/payment-settings`, (route) => route.fulfill({ json: state('onboarding') }));
    await page.route(`**/api/tenants/${tenant.id}/payment-settings/stripe-connect/refresh`, (route) => {
        asked++;
        route.fulfill({ json: state(asked < 3 ? 'onboarding' : 'active') });
    });

    // 戻り先の URL はサーバーが前の店の設定の URL で作る。目次ではなく支払いの画面へ送る
    await page.goto(`/tenants/${tenant.id}/settings?stripe=return`);
    await expect(page.getByTestId('stripe-connect-checking')).toBeVisible();
    await expect(page.getByRole('heading', { name: '支払い', level: 1 })).toBeVisible();
    // 戻り先の印は URL から消す（読み込み直しで二度確かめない）
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/settings/payments$`));
    await expect(page.getByRole('status').filter({ hasText: 'Stripe へ接続しました' })).toBeVisible({ timeout: 20_000 });
    await expect(page.getByTestId('stripe-connect-status')).toContainText('お支払いを受けられます');
    expect(asked).toBe(3);
});
