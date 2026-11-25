import { existsSync } from 'node:fs';
import { expect, test } from './support/fixtures.js';
import { articleOf } from '../../resources/admin/help/index.js';
import { HELP_ITEM, HELP_PAGES, OPERATOR_ITEMS, SELF_ITEMS, SETTINGS_SECTIONS, TENANT_ITEMS } from '../../resources/admin/navigation.js';

// メニューの定義（resources/admin/navigation.js。案 C）から、メニュー・店の切り替えの中の運営全体・設定の目次・タブ・
// パンくず・見出し・タブの題が出ること、段階ごとにメニューと変えるボタンが出し分けられること、スマホの幅（390px）ではみ出さないこと。
// 出し分けは見た目だけで、守るのはサーバー（最後に API を直接たたいて確かめる）

const MAIN = TENANT_ITEMS.filter((item) => item.group === 'main');
const SETTINGS = TENANT_ITEMS.filter((item) => item.group === 'settings');
const SETTING_PAGES = SETTINGS_SECTIONS.flatMap((section) => section.items).filter((item) => item.route?.startsWith('settings-'));
// ヘルプの目次に出る説明のページ（入っていない App の説明のページは出さない。公開用の 1.0 には定期便などの App が無い。ADR 0098）
const SHOWN_HELP = HELP_PAGES.filter((help) => {
    const plugin = articleOf(help.key)?.plugin ?? null;

    return plugin === null || existsSync(`plugins/plugin-${plugin}/composer.json`);
});

function mainMenu(page) {
    return page.getByRole('navigation', { name: '店のメニュー' });
}

function lowerMenu(page) {
    return page.getByRole('navigation', { name: '設定とヘルプ' });
}

function switcher(page) {
    return page.getByRole('button', { name: /店の切り替えと運営全体/ });
}

async function expectCrumbs(page, labels) {
    await expect(page.getByRole('navigation', { name: '現在地' }).getByRole('listitem')).toHaveText(labels);
}

// パンくずの頭（見られる店が 2 つ以上なら「店の一覧 / 店の名前」、1 つなら店の名前だけ）。
// 同時に走るほかの場面が店を作るので、どちらの形でも確かめられるようにする
async function shopCrumbs(page, name) {
    return (await page.getByTestId('tenant-switcher').count()) > 0 ? ['店の一覧', name] : [name];
}

// 店の中の画面のパンくず（頭の後に rest）。頭は、場面の初めに 1 回決めず、確かめるたびにその時の画面から決める。
// 同時に走るほかの場面が店を作り・消すので、見られる店の数は場面の途中で変わり、画面を読み直す（page.goto）と
// 店の一覧を読み直して形が変わる。初めに決めた頭のままだと「店の一覧」の段が 1 つ多い・少ないで落ちる（夜の CI で起きた）。
// 店の一覧を読み終わる前と後で形が揺れるので、頭を決め直しながら待つ
async function expectShopCrumbs(page, name, rest = []) {
    await expect(async () => {
        const head = await shopCrumbs(page, name);
        await expect(page.getByRole('navigation', { name: '現在地' }).getByRole('listitem')).toHaveText([...head, ...rest], { timeout: 1_000 });
    }).toPass({ timeout: 10_000 });
}

// 横にはみ出していない（スマホの幅で、画面の全体が横に動かない）
async function expectNoOverflow(page) {
    const widths = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, view: window.innerWidth }));
    expect(widths.scroll, `横幅 ${widths.view}px からはみ出している`).toBeLessThanOrEqual(widths.view);
}

test('店のメニューはメニューの定義のとおりに並び、押すと見出し・パンくず・タブ・タブの題がそろう', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant();
    await page.goto(`/tenants/${tenant.id}`);
    await expect(page.getByTestId('tenant-name')).toHaveText(tenant.name);

    // 上は主なメニュー（ショップを開くリンクは別）、下の区切り線の下は「設定」とヘルプ、その下に自分のもの
    await expect(mainMenu(page).locator('a:not([data-test="open-storefront"])')).toHaveText(MAIN.map((item) => item.label));
    await expect(lowerMenu(page).getByRole('link')).toHaveText([...SETTINGS, HELP_ITEM].map((item) => item.label));
    await expect(page.getByRole('navigation', { name: '自分のもの' }).getByRole('link')).toHaveText(SELF_ITEMS.map((item) => item.label));
    await expect(page).toHaveTitle(`ホーム — ${tenant.name} — EC-SUGAR 管理`);
    await expectShopCrumbs(page, tenant.name);
    await expect(mainMenu(page).getByTestId('nav-home')).toHaveAttribute('aria-current', 'page');

    for (const item of MAIN.filter((i) => i.key !== 'home')) {
        await mainMenu(page).getByRole('link', { name: item.label, exact: true }).click();
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(item.label);
        await expect(mainMenu(page).getByRole('link', { name: item.label, exact: true })).toHaveAttribute('aria-current', 'page');
        await expectShopCrumbs(page, tenant.name, [item.label]);
        await expect(page).toHaveTitle(`${item.label} — ${tenant.name} — EC-SUGAR 管理`);

        // 中に画面を持つ項目（商品・デザイン）は、見出しの下のタブで移る。移ってもメニューの選択はその項目のまま
        if (item.tabs) {
            const tabs = page.getByRole('navigation', { name: 'この項目の中の画面' }).getByRole('link');
            await expect(tabs).toHaveText(item.tabs.map((tab) => tab.label));
            await expect(tabs.first()).toHaveAttribute('aria-current', 'page');
            const [, second] = item.tabs;
            await page.getByTestId(`tab-page-${second.key}`).click();
            await expect(page.getByRole('heading', { level: 1 })).toHaveText(second.label);
            await expect(page.getByTestId(`tab-page-${second.key}`)).toHaveAttribute('aria-current', 'page');
            await expect(mainMenu(page).getByRole('link', { name: item.label, exact: true })).toHaveAttribute('aria-current', 'page');
            await expectShopCrumbs(page, tenant.name, [item.label, second.label]);
        }
    }

    // 設定の目次: まとまりと項目は定義のとおり。画面の無い項目は「近日」の札で、押せない
    await lowerMenu(page).getByTestId('nav-settings').click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/settings$`));
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('設定');
    await expect(lowerMenu(page).getByTestId('nav-settings')).toHaveAttribute('aria-current', 'page');
    await expectShopCrumbs(page, tenant.name, ['設定']);
    await expect(page.getByRole('heading', { level: 2 })).toHaveText(SETTINGS_SECTIONS.map((section) => section.title));
    const soon = SETTINGS_SECTIONS.flatMap((section) => section.items).filter((item) => item.route === null);
    await expect(page.getByTestId('settings-soon')).toHaveCount(soon.length);
    for (const [index, item] of soon.entries()) {
        await expect(page.getByTestId('settings-soon').nth(index)).toContainText(item.label);
        await expect(page.getByTestId('settings-soon').nth(index)).toContainText('近日');
    }
    await expect(page.getByTestId('settings-soon').getByRole('link')).toHaveCount(0);

    // 目次から各画面へ。画面の横に目次が開いたまま（今の項目に印）、パンくずの「設定」で目次へ戻れる。メニューの「設定」は選択中のまま
    for (const item of SETTING_PAGES) {
        await page.getByTestId(`settings-${item.key}`).click();
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(item.label);
        await expectShopCrumbs(page, tenant.name, ['設定', item.label]);
        await expect(page).toHaveTitle(`${item.label} — ${tenant.name} — EC-SUGAR 管理`);
        await expect(lowerMenu(page).getByTestId('nav-settings')).toHaveAttribute('aria-current', 'page');
        const toc = page.getByRole('navigation', { name: '設定の目次' });
        await expect(toc.getByRole('link', { name: item.label, exact: true })).toHaveAttribute('aria-current', 'page');
        await expect(toc.getByTestId('toc-soon')).toHaveCount(soon.length);
        await page.getByRole('navigation', { name: '現在地' }).getByRole('link', { name: '設定' }).click();
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('設定');
    }

    // 横の目次から別の項目へ直接移れる
    await page.getByTestId('settings-basic').click();
    await page.getByTestId('toc-shipping').click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/settings/shipping$`));
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('配送と送料');

    // 使う App は店の App の画面へ
    await lowerMenu(page).getByTestId('nav-settings').click();
    await page.getByTestId('settings-apps').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('App');

    // 前の「ショップの公開」の URL は「基本」へ
    await page.goto(`/tenants/${tenant.id}/settings/publish`);
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/settings/basic$`));
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('基本');

    // ヘルプ: 説明のページの目次から各ページへ。パンくずは「ヘルプ / ページ」
    await lowerMenu(page).getByTestId('nav-help').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('ヘルプ');
    await expect(lowerMenu(page).getByTestId('nav-help')).toHaveAttribute('aria-current', 'page');
    for (const help of SHOWN_HELP) {
        await page.getByTestId(`help-${help.key}`).click();
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(help.label);
        await expectCrumbs(page, ['ヘルプ', help.label]);
        await expect(lowerMenu(page).getByTestId('nav-help')).toHaveAttribute('aria-current', 'page');
        await page.getByRole('navigation', { name: '現在地' }).getByRole('link', { name: 'ヘルプ' }).click();
    }

    // 運営全体は店の切り替えの中から（オーナーには全部。見られる店が 1 つなら店の一覧は出さない）
    await page.goto(`/tenants/${tenant.id}`);
    await switcher(page).click();
    // 店の一覧を読み終わる前と後で「店の一覧」の項目の有無が揺れるので、見られる店が 1 つかを決め直しながら待つ（上の expectShopCrumbs と同じ）
    await expect(async () => {
        const single = (await page.getByTestId('shop-name').count()) > 0;
        const operatorItems = OPERATOR_ITEMS.filter((item) => !(single && item.single === false));
        await expect(page.getByRole('navigation', { name: '運営全体' }).getByRole('link')).toHaveText(operatorItems.map((item) => item.label), { timeout: 1_000 });
    }).toPass({ timeout: 10_000 });
    await expect(mainMenu(page).getByTestId('nav-members')).toHaveCount(0);
    await page.getByTestId('nav-members').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('メンバーと権限');
    await expect(page.getByTestId('shop-switcher-panel')).toHaveCount(0);
    // 外を押すと閉じる
    await switcher(page).click();
    await expect(page.getByTestId('shop-switcher-panel')).toBeVisible();
    await page.getByRole('heading', { level: 1 }).click();
    await expect(page.getByTestId('shop-switcher-panel')).toHaveCount(0);

    // 詳細の画面のパンくずは、項目へのリンクの後に今の画面
    // （商品の無い店なので、登録のボタンは空の案内の真ん中だけ。右上には出ない）
    await page.goto(`/tenants/${tenant.id}/listings`);
    await page.getByTestId('empty-new-listing').click();
    await expectShopCrumbs(page, tenant.name, ['商品', '新しい商品']);
    await expect(page).toHaveTitle(`新しい商品 — ${tenant.name} — EC-SUGAR 管理`);
});

test('設定 › 基本で、ショップの名前と URL を見て、準備中に切り替えてパスワードを置ける', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant();
    await page.goto(`/tenants/${tenant.id}/settings`);
    await page.getByTestId('settings-basic').click();

    await expect(page.getByTestId('settings-shop-info')).toContainText(tenant.name);
    await expect(page.getByTestId('settings-shop-info')).toContainText(`${tenant.id}.localhost`);
    await expect(page.getByTestId('publish-status-open')).toBeChecked();

    // 準備中に戻すのはパスキーの確かめが要らない。保存していない変更の印が出て、保存で消える
    await page.getByTestId('publish-status-preparing').check();
    await expect(page.getByTestId('settings-unsaved')).toBeVisible();
    await page.getByTestId('save-publish').click();
    await expect(page.getByRole('status').filter({ hasText: '公開の状態を保存しました' })).toBeVisible();
    await expect(page.getByTestId('settings-unsaved')).toHaveCount(0);

    // 短すぎるパスワードはサーバーが断り、欄の下に理由が出る
    await page.getByTestId('publish-password').fill('short12');
    await page.getByTestId('publish-password').evaluate((input) => input.removeAttribute('minlength'));
    await page.getByTestId('save-publish-password').click();
    await expect(page.getByRole('alert').filter({ hasText: '入力を確認してください' })).toBeVisible();

    await page.getByTestId('publish-password').fill('preview-pass-2026');
    await page.getByTestId('save-publish-password').click();
    await expect(page.getByTestId('publish-password-state')).toHaveText('パスワードを設定しています。');

    await page.reload();
    await expect(page.getByTestId('publish-status-preparing')).toBeChecked();
    await expect(page.getByTestId('publish-password-state')).toHaveText('パスワードを設定しています。');
});

test('閲覧のスタッフには、読める画面はメニューに出し、変えるボタンとオーナーの項目は出さない（サーバーも断る）', async ({ page, staff, makeTenant }) => {
    const tenant = makeTenant({ listings: 1 });
    await staff(tenant, 'read');
    await page.goto(`/tenants/${tenant.id}`);
    await expect(page.getByTestId('tenant-name')).toHaveText(tenant.name);

    // 店の中の画面は閲覧の段階の項目が出る（売上はお金の段階からなので出ない。ADR 0024・0060）。運営全体はログインの履歴だけ（メンバーと権限・App とテーマ・店の一覧は出ない）
    await expect(mainMenu(page).locator('a:not([data-test="open-storefront"])')).toHaveText(MAIN.filter((item) => item.level === 'read').map((item) => item.label));
    await expect(mainMenu(page).getByTestId('nav-sales')).toHaveCount(0);
    await expect(lowerMenu(page).getByRole('link')).toHaveText([...SETTINGS, HELP_ITEM].map((item) => item.label));
    await switcher(page).click();
    await expect(page.getByRole('navigation', { name: '運営全体' }).getByRole('link')).toHaveText(['ログインの履歴']);
    await expect(page.getByTestId('nav-members')).toHaveCount(0);
    await expect(page.getByTestId('nav-plugins')).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(switcher(page)).toBeFocused();

    // 設定の各画面: 欄は読めるが押せず、保存のボタンは無い
    await page.goto(`/tenants/${tenant.id}/settings/basic`);
    await expect(page.getByTestId('settings-read-only')).toBeVisible();
    await expect(page.getByTestId('publish-status-open')).toBeDisabled();
    await expect(page.getByTestId('save-publish')).toHaveCount(0);
    await expect(page.getByTestId('publish-password')).toHaveCount(0);

    await page.goto(`/tenants/${tenant.id}/settings/shipping`);
    await expect(page.getByTestId('settings-read-only')).toBeVisible();
    await expect(page.getByTestId('shipping-fee')).toBeDisabled();
    await expect(page.getByTestId('save-shipping')).toHaveCount(0);

    await page.goto(`/tenants/${tenant.id}/settings/order-terms`);
    await expect(page.getByTestId('settings-read-only')).toBeVisible();
    await expect(page.getByTestId('terms-delivery')).toBeDisabled();
    await expect(page.getByTestId('save-terms')).toHaveCount(0);

    await page.goto(`/tenants/${tenant.id}/settings/consent`);
    await expect(page.getByTestId('settings-read-only')).toBeVisible();
    await expect(page.getByTestId('consent-banner-on')).toBeDisabled();
    await expect(page.getByTestId('save-consent-banner')).toHaveCount(0);

    await page.goto(`/tenants/${tenant.id}/settings/legal`);
    await expect(page.getByTestId('settings-read-only')).toBeVisible();
    await expect(page.getByTestId('legal-template')).toHaveCount(0);
    await expect(page.getByTestId('add-legal')).toHaveCount(0);
    await expect(page.getByTestId('save-legal')).toHaveCount(0);

    await page.goto(`/tenants/${tenant.id}/settings/payments`);
    await expect(page.getByTestId('stripe-no-permission')).toBeVisible();
    await expect(page.getByTestId('stripe-keys')).toHaveCount(0);

    // 記録の確かめは読むだけなので閲覧でもできる
    await page.goto(`/tenants/${tenant.id}/settings/records`);
    await page.getByTestId('verify-events').click();
    await expect(page.getByTestId('verify-result')).toContainText('改竄は見つかりませんでした');

    // デザイン（テーマの選択・ページ）・商品・カテゴリ・App も、変えるボタンは出さない
    await page.goto(`/tenants/${tenant.id}/theme`);
    await expect(page.getByTestId('themes-read-only')).toBeVisible();
    await expect(page.getByTestId('open-page-editor')).toHaveCount(0);
    await expect(page.getByTestId('choose-theme')).toHaveCount(0);
    await page.getByTestId('tab-page-pages').click();
    await expect(page.getByTestId('pages-read-only')).toBeVisible();
    await expect(page.getByTestId('add-page')).toHaveCount(0);
    await page.goto(`/tenants/${tenant.id}/listings`);
    await expect(page.getByTestId('listing-row')).toHaveCount(1);
    await expect(page.getByTestId('new-listing')).toHaveCount(0);
    await page.getByTestId('listing-row').getByRole('link').first().click();
    await expect(page.getByTestId('listing-read-only')).toBeVisible();
    await expect(page.getByTestId('save-listing')).toHaveCount(0);
    await expect(page.getByTestId('apply-stock')).toHaveCount(0);
    await expect(page.getByTestId('file-input')).toHaveCount(0);
    await page.goto(`/tenants/${tenant.id}/categories`);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('カテゴリ');
    await expect(page.getByTestId('add-category')).toHaveCount(0);
    await page.goto(`/tenants/${tenant.id}/plugins`);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('App');
    await expect(page.getByTestId('tenant-plugin-toggle')).toHaveCount(0);

    // 画面で隠すのは見た目だけ。API を直接たたいてもサーバーが断る（店の設定と、お金の段階の売上と、オーナーだけのメンバーの一覧）
    const statuses = await page.evaluate(async (tenantId) => {
        const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
        const headers = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token };
        const put = (path, body) => fetch(path, { method: 'PUT', headers, body: JSON.stringify(body) }).then((r) => r.status);

        return {
            shipping: await put(`/api/tenants/${tenantId}/shipping-methods`, { methods: [] }),
            publish: await put(`/api/tenants/${tenantId}/shop-settings/publish`, { status: 'preparing' }),
            sales: await fetch(`/api/tenants/${tenantId}/sales`, { headers }).then((r) => r.status),
            members: await fetch('/api/members', { headers }).then((r) => r.status),
        };
    }, tenant.id);
    expect(statuses).toEqual({ shipping: 403, publish: 403, sales: 403, members: 403 });
});

test('運用のスタッフは設定とテーマを変えられ、お支払いは変えられない', async ({ page, staff, makeTenant }) => {
    const tenant = makeTenant();
    await staff(tenant, 'operate');

    await page.goto(`/tenants/${tenant.id}/settings/basic`);
    await expect(page.getByTestId('save-publish')).toBeVisible();
    await expect(page.getByTestId('publish-password')).toBeVisible();
    await page.goto(`/tenants/${tenant.id}/settings/shipping`);
    await expect(page.getByTestId('save-shipping')).toBeVisible();
    await expect(page.getByTestId('settings-read-only')).toHaveCount(0);
    await page.goto(`/tenants/${tenant.id}/settings/legal`);
    await expect(page.getByTestId('legal-template')).toBeVisible();
    await page.goto(`/tenants/${tenant.id}/theme`);
    await expect(page.getByTestId('open-page-editor')).toBeVisible();
    await expect(page.getByTestId('choose-theme').first()).toBeVisible();
    await page.goto(`/tenants/${tenant.id}/pages`);
    await expect(page.getByTestId('add-page')).toBeVisible();

    await page.goto(`/tenants/${tenant.id}/settings/payments`);
    await expect(page.getByTestId('stripe-no-permission')).toBeVisible();
    await expect(page.getByTestId('stripe-keys')).toHaveCount(0);
    await switcher(page).click();
    await expect(page.getByTestId('nav-login-history')).toBeVisible();
    await expect(page.getByTestId('nav-members')).toHaveCount(0);
});

test('お金のスタッフはお支払いの鍵を置けるが、オーナーの項目は出ない', async ({ page, staff, makeTenant }) => {
    const tenant = makeTenant();
    await staff(tenant, 'money');

    await page.goto(`/tenants/${tenant.id}/settings/payments`);
    await expect(page.getByTestId('stripe-keys')).toBeVisible();
    await expect(page.getByTestId('stripe-no-permission')).toHaveCount(0);
    await switcher(page).click();
    await expect(page.getByTestId('nav-login-history')).toBeVisible();
    await expect(page.getByTestId('nav-members')).toHaveCount(0);
    await expect(page.getByTestId('nav-plugins')).toHaveCount(0);
});

test('スマホの幅（390px）では、メニューをボタンで開き閉じし、どの画面も横にはみ出さない', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 2, transaction: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/tenants/${tenant.id}`);
    await expect(page.getByTestId('tenant-name')).toHaveText(tenant.name);

    // メニューは閉じた形で始まり、ボタンで開く。項目を押して画面を移ると閉じる
    const toggle = page.getByTestId('menu-toggle');
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(mainMenu(page)).toBeHidden();
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(mainMenu(page).getByTestId('nav-listings')).toBeVisible();
    await expectNoOverflow(page);
    await mainMenu(page).getByTestId('nav-listings').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('商品');
    await expect(mainMenu(page)).toBeHidden();

    // 店の切り替えの窓も幅に収まる
    await switcher(page).click();
    await expect(page.getByTestId('shop-switcher-panel')).toBeVisible();
    await expectNoOverflow(page);
    await page.keyboard.press('Escape');

    for (const path of ['', '/listings', '/categories', '/transactions', '/photos', '/theme', '/pages', '/plugins', '/settings', '/settings/basic', '/settings/legal', '/settings/order-terms', '/settings/payments', '/settings/shipping', '/settings/records']) {
        await page.goto(`/tenants/${tenant.id}${path}`);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expect(page.getByText('読み込んでいます…')).toHaveCount(0);
        await expectNoOverflow(page);
    }

    // 設定の 1 項目の画面では、横の目次を出さず中身だけ（目次は設定の目次の画面から）
    await page.goto(`/tenants/${tenant.id}/settings/shipping`);
    await expect(page.getByTestId('settings-toc')).toBeHidden();
    await page.goto('/help');
    await expectNoOverflow(page);
});
