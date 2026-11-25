import { existsSync } from 'node:fs';
import { expect, test } from './support/fixtures.js';
import { HELP_ARTICLES, HELP_SCREENS } from '../../resources/admin/help/index.js';

// ヘルプ（説明のページ・目次の検索・各画面の「説明を見る」）。中身はデータ（resources/admin/help/）から描く。
// CSP と Trusted Types の違反は全場面に自動で掛かる（support/fixtures.js）。写しは自分のオリジンから読まれて表示されること

// 目次に出る説明のページ（入っていない App の説明のページは出さない。公開用の 1.0 には定期便・予約・チケット・抽選販売の App が無い。ADR 0098）
const SHOWN = HELP_ARTICLES.filter((article) => article.plugin === null || existsSync(`plugins/plugin-${article.plugin}/composer.json`));

async function expectImagesLoaded(page) {
    for (const image of await page.locator('main img').all()) {
        await image.scrollIntoViewIfNeeded();
        await expect.poll(() => image.evaluate((img) => img.complete && img.naturalWidth > 0), { message: await image.getAttribute('alt') }).toBe(true);
    }
}

test('ヘルプの目次から全部の説明のページが開け、節がデータのとおりに並び、写しが表示される', { tag: '@nightly' }, async ({ page, operator }) => {
    void operator;

    for (const article of SHOWN) {
        await page.goto('/help');
        await page.getByTestId(`help-${article.key}`).click();
        await expect(page).toHaveURL(new RegExp(`/help/${article.key}(\\?shop=[a-z0-9_-]+)?$`));
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(article.label);
        await expect(page).toHaveTitle(`${article.label} — EC-SUGAR 管理`);
        const sections = page.getByTestId(`help-article-${article.key}`).locator('[data-test^="help-section-"]');
        await expect(sections).toHaveCount(article.sections.length);
        await expect(sections.locator('h2')).toHaveText(article.sections.map((section) => section.title));
        await expectImagesLoaded(page);
    }

    // 節の目次（広い画面）から節へ移る
    await page.setViewportSize({ width: 1400, height: 900 });
    await page.goto('/help/orders');
    await page.getByRole('navigation', { name: 'このページの目次' }).getByRole('button', { name: '取消と返金' }).click();
    await expect(page.getByTestId('help-section-cancel')).toBeFocused();

    // ヘルプの中のリンクで、別のページの節へ
    await page.goto('/help/faq');
    await page.getByTestId('help-section-mail').getByRole('link', { name: 'お客さんへのメール' }).click();
    // 見られる店が 1 つなら、その店の印（?shop=）も付いてくる（同時に走るほかの場面の店の数で変わる）
    await expect(page).toHaveURL(/\/help\/orders(\?shop=[a-z0-9_-]+)?#mails$/);
    await expect(page.getByTestId('help-section-mails')).toBeInViewport();

    // 知らない名前のページ
    await page.goto('/help/no-such-page');
    await expect(page.getByTestId('help-not-found')).toBeVisible();
    await expect(page.getByRole('navigation', { name: '現在地' }).getByRole('listitem')).toHaveText(['ヘルプ', '見つかりません']);
});

test('ヘルプの中を言葉で探せる（見つかる・見つからない・URL に残る）', { tag: '@nightly' }, async ({ page, operator }) => {
    void operator;
    await page.goto('/help');
    const search = page.getByTestId('help-search');

    await search.fill('送料');
    await expect(page.getByTestId('help-result-count')).toHaveText(/^\d+ 件$/);
    const first = page.getByTestId('help-result-setup-shipping');
    await expect(first).toBeVisible();
    await expect(first.locator('mark').first()).toHaveText('送料');
    await expect(page).toHaveURL(/\/help\?q=/);

    // 読み込み直しても言葉と結果が残る
    await page.reload();
    await expect(search).toHaveValue('送料');
    await expect(first).toBeVisible();

    await first.click();
    await expect(page).toHaveURL(/\/help\/setup#shipping$/);
    await expect(page.getByTestId('help-section-shipping')).toBeFocused();
    await page.goBack();
    await expect(search).toHaveValue('送料');

    // 全角の英字でも当たる
    await search.fill('ｗｅｂｈｏｏｋ');
    await expect(page.getByTestId('help-result-faq-payment-pending')).toBeVisible();

    // 見つからない
    await search.fill('存在しない言葉ぴよぴよ');
    await expect(page.getByTestId('help-result-count')).toHaveText('見つかりませんでした');
    await expect(page.getByTestId('help-no-result')).toBeVisible();
    await page.getByTestId('help-search-clear').click();
    await expect(search).toHaveValue('');
    await expect(page.getByTestId('help-no-result')).toHaveCount(0);
    await expect(page.getByTestId('help-payments')).toBeVisible();
});

test('各画面の「説明を見る」から、その画面の説明の節へ移り、説明から画面へ戻れる', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, transaction: true, customers: true });
    // 商品の 1 件の画面の URL は一覧のリンクから
    await page.goto(`/tenants/${tenant.id}/listings`);
    const listing = await page.getByTestId('listing-row').first().getByRole('link').first().getAttribute('href');
    // 説明の行き先のある画面ごとの URL（足したら、ここにも足す）
    const paths = {
        tenant: `/tenants/${tenant.id}`,
        listings: `/tenants/${tenant.id}/listings`,
        'listing-new': `/tenants/${tenant.id}/listings/new`,
        listing: listing,
        categories: `/tenants/${tenant.id}/categories`,
        'listing-import': `/tenants/${tenant.id}/listings/import`,
        coupons: `/tenants/${tenant.id}/coupons`,
        'coupon-new': `/tenants/${tenant.id}/coupons/new`,
        coupon: `/tenants/${tenant.id}/coupons/${tenant.coupon.id}`,
        transactions: `/tenants/${tenant.id}/transactions`,
        transaction: `/tenants/${tenant.id}/transactions/${tenant.transaction.id}`,
        customers: `/tenants/${tenant.id}/customers`,
        customer: `/tenants/${tenant.id}/customers/${tenant.customers.hanako.id}`,
        sales: `/tenants/${tenant.id}/sales`,
        'theme-content': `/tenants/${tenant.id}/theme`,
        'shop-pages': `/tenants/${tenant.id}/pages`,
        photos: `/tenants/${tenant.id}/photos`,
        'tenant-plugins': `/tenants/${tenant.id}/plugins`,
        'tenant-plugin': `/tenants/${tenant.id}/plugins/ga4`,
        'settings-legal': `/tenants/${tenant.id}/settings/legal`,
        'settings-privacy': `/tenants/${tenant.id}/settings/privacy`,
        'settings-shipping': `/tenants/${tenant.id}/settings/shipping`,
        'settings-delivery-dates': `/tenants/${tenant.id}/settings/delivery-dates`,
        'settings-gift-cards': `/tenants/${tenant.id}/settings/gift-cards`,
        'settings-mails': `/tenants/${tenant.id}/settings/mails`,
        'settings-points': `/tenants/${tenant.id}/settings/points`,
        'settings-seo': `/tenants/${tenant.id}/settings/seo`,
        'settings-order-terms': `/tenants/${tenant.id}/settings/order-terms`,
        'settings-order-fields': `/tenants/${tenant.id}/settings/order-fields`,
        'settings-payments': `/tenants/${tenant.id}/settings/payments`,
        'settings-consent': `/tenants/${tenant.id}/settings/consent`,
        'settings-basic': `/tenants/${tenant.id}/settings/basic`,
        'settings-agents': `/tenants/${tenant.id}/settings/agents`,
        'agent-proposals': `/tenants/${tenant.id}/agent-proposals`,
        members: '/members',
        passkeys: '/passkeys',
    };
    expect(Object.keys(paths).sort(), '説明の行き先のある画面の URL を、この表にも足す').toEqual(Object.keys(HELP_SCREENS).sort());

    for (const [screen, target] of Object.entries(HELP_SCREENS)) {
        const article = HELP_ARTICLES.find((a) => a.key === target.article);
        await page.goto(paths[screen]);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await page.getByTestId('help-link').click();
        const shop = paths[screen].startsWith('/tenants/') ? `\\?shop=${tenant.id}` : '';
        await expect(page, screen).toHaveURL(new RegExp(`/help/${target.article}${shop}#${target.section}$`));
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(article.label);
        await expect(page.getByTestId(`help-section-${target.section}`), screen).toBeFocused();
        await expect(page.getByTestId(`help-section-${target.section}`)).toBeInViewport();
    }

    // 説明から、その店の画面へ戻る
    await page.goto(paths['settings-shipping']);
    await page.getByTestId('help-link').click();
    await page.getByTestId('help-open-settings-shipping').click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/settings/shipping$`));
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('配送と送料');
});
