import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// App「お知らせ・ブログ」（ADR 0072）。App の画面で記事を書き（本文は商品の説明と同じブロックのエディタ）、下書きのうちはショップに出ず、
// 「公開する」でショップの /news と記事の画面に出る。写真・ブロック・日時の欄は核の部品（SettingFields）が描く

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;
const mainMenu = (page) => page.getByRole('navigation', { name: '店のメニュー' });

test('お知らせ・ブログを使うにして記事を書き、公開するとショップの /news に出る', async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant();

    // App の画面から「お知らせ・ブログ」を使うにする（直前の再確認）
    await page.goto(`/tenants/${tenant.id}/plugins`);
    const row = page.getByTestId('tenant-plugin-row').filter({ hasText: 'お知らせ・ブログ' });
    await row.getByTestId('tenant-plugin-toggle').click();
    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();
    await expect(row.getByTestId('tenant-plugin-state')).toHaveText('使う');
    await mainMenu(page).getByTestId('nav-app-blog').click();
    await expect(page.getByTestId('app-records-empty')).toBeVisible();

    // 書く: 題・URL の名前・本文のブロック（段落）・予約の日時の欄がある
    await page.getByTestId('app-create-open').click();
    const form = page.getByTestId('app-create-form');
    await form.getByTestId('app-create-form-title').fill('冬のお休みのお知らせ');
    await form.getByTestId('app-create-form-slug').fill('winter-holiday');
    await expect(form.getByTestId('app-create-form-publish_at')).toHaveAttribute('type', 'datetime-local');
    // ブロックのエディタは 2 つ（本文と「ここから有料」）。先のものが本文
    await form.getByTestId('add-paragraph').first().click();
    await form.getByTestId('block-text').fill('12 月 29 日から 1 月 3 日までお休みします。<b>太字にならない</b>');
    await form.getByTestId('app-create-form-submit').click();
    await expect(page.getByTestId('app-create-done')).toBeVisible();

    // 下書きはショップに出ない
    const shop = await page.context().newPage();
    expect((await shop.goto(storefront(tenant, '/news/winter-holiday'))).status()).toBe(404);

    // 1 件の画面で公開する
    // 題の列がある一覧は、題そのものが 1 件の画面へのリンク（「開く」の列は無い）
    await page.getByTestId('app-row').first().getByRole('link', { name: '冬のお休みのお知らせ' }).click();
    await expect(page.getByTestId('app-facts')).toContainText('下書き');
    await expect(page.getByTestId('app-fact-copy').first()).toBeVisible();
    await page.getByTestId('app-action-publish').click();
    await page.getByTestId('confirm-app-action-publish').click();
    await expect(page.getByTestId('app-action-done')).toHaveText('「公開する」が済みました。');
    await expect(page.getByTestId('app-facts')).toContainText('公開中');

    // ショップの一覧と記事に出る（本文は文字のまま）
    await shop.goto(storefront(tenant, '/news'));
    await expect(shop.getByTestId('news-item')).toHaveCount(1);
    await shop.getByRole('link', { name: '冬のお休みのお知らせ' }).click();
    await expect(shop).toHaveURL(storefront(tenant, '/news/winter-holiday'));
    await expect(shop.getByTestId('news-post-title')).toHaveText('冬のお休みのお知らせ');
    await expect(shop.getByTestId('news-post-body')).toContainText('<b>太字にならない</b>');
    await expect(shop.locator('[data-test="news-post-body"] b')).toHaveCount(0);
});
