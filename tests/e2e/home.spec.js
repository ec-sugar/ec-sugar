import { expect, test } from './support/fixtures.js';

// 店のホーム: 最初の 1 回の「ようこそ」・開設の手順（済は店のデータから自動で）・数とやることと最近の注文

const STEPS = {
    legal: /\/settings\/legal$/,
    shipping: /\/settings\/shipping$/,
    payment: /\/settings\/payments$/,
    listing: /\/listings\/new$/,
    design: /\/theme$/,
    publish: /\/settings\/basic$/,
    customers: /\/plugins$/,
};

test('新しい店では「ようこそ」が 1 回だけ出て、手順を進めると済になり、各手順から画面へ移る', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ preparing: true });
    await page.goto(`/tenants/${tenant.id}`);

    // ようこそ: 3 枚をめくる。「あとで見る」はこの画面を開いている間だけ隠す
    const welcome = page.getByTestId('welcome');
    await expect(welcome).toBeVisible();
    await expect(page.getByTestId('welcome-title')).toHaveText('EC-SUGAR へようこそ');
    await page.getByTestId('welcome-later').click();
    await expect(welcome).toHaveCount(0);
    await page.getByTestId('open-listings').click();
    await page.getByRole('navigation', { name: '店のメニュー' }).getByRole('link', { name: 'ホーム' }).click();
    await expect(page.getByTestId('setup')).toBeVisible();
    await expect(welcome).toHaveCount(0);
    await page.reload();
    await expect(welcome).toBeVisible();
    await page.getByTestId('welcome-next').click();
    await expect(page.getByTestId('welcome-title')).toHaveText('ショップはまだ準備中です');
    await page.getByTestId('welcome-next').click();
    await expect(page.getByTestId('welcome-title')).toHaveText('次にやること');
    await page.getByTestId('welcome-back').click();
    await expect(page.getByTestId('welcome-title')).toHaveText('ショップはまだ準備中です');
    await page.getByTestId('welcome-next').click();
    await page.getByTestId('welcome-start').click();
    await expect(welcome).toHaveCount(0);
    // 閉じたら、読み込み直しても出ない（印はサーバーに）。「はじめにの説明」でもう一度見られる
    await page.reload();
    await expect(page.getByTestId('setup')).toBeVisible();
    await expect(welcome).toHaveCount(0);
    await page.getByTestId('welcome-reopen').click();
    await expect(welcome).toBeVisible();
    await page.getByTestId('welcome-close').click();
    await expect(welcome).toHaveCount(0);

    // 開設の手順: 7 つ、どれもまだ。各手順のボタンは該当の画面へ
    await expect(page.getByTestId('setup-progress')).toHaveText('0 / 7 済');
    for (const [key, url] of Object.entries(STEPS)) {
        await expect(page.getByTestId(`setup-step-${key}`)).toHaveAttribute('data-done', 'false');
        await page.getByTestId(`setup-go-${key}`).click();
        await expect(page).toHaveURL(url);
        await page.goBack();
        await expect(page.getByTestId('setup')).toBeVisible();
    }

    // 送料を保存すると「送料」が済になる（手で済にしない）
    await page.getByTestId('setup-go-shipping').click();
    await page.getByTestId('shipping-fee').fill('600');
    await page.getByTestId('save-shipping').click();
    await expect(page.getByRole('status')).toContainText('送料を保存しました');
    await page.getByRole('navigation', { name: '店のメニュー' }).getByRole('link', { name: 'ホーム' }).click();
    await expect(page.getByTestId('setup-step-shipping')).toHaveAttribute('data-done', 'true');
    await expect(page.getByTestId('setup-step-shipping').getByTestId('step-done')).toHaveText('済');
    await expect(page.getByTestId('setup-progress')).toHaveText('1 / 7 済');
    // 準備中で注文の無い店には、数も最近の注文も出さない（空の 0 を並べない）
    await expect(page.getByTestId('home-stats')).toHaveCount(0);
    await expect(page.getByTestId('home-recent')).toHaveCount(0);
});

test('サンプルの入った店では、「最初の商品」の中にサンプルの手順が出て、サンプルだけでは済にならない', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ preparing: true, samples: true });
    await page.goto(`/tenants/${tenant.id}`);

    await page.getByTestId('welcome-next').click();
    await expect(page.getByTestId('welcome-title')).toHaveText('サンプルが入っています');
    await page.getByTestId('welcome-close').click();

    const listing = page.getByTestId('setup-step-listing');
    await expect(listing).toHaveAttribute('data-done', 'false');
    await expect(listing.getByTestId('sample-parts')).toContainText('商品 8 点');
    await expect(listing.getByTestId('sample-step-replace')).toHaveAttribute('data-done', 'false');
    await expect(listing.getByTestId('sample-step-remove')).toHaveAttribute('data-done', 'false');
    await expect(listing.getByTestId('remove-samples')).toBeVisible();
    // サンプルの表記・送料・テーマの中身も、お店が確かめるまで済にしない。公開の手順にはサンプルが残っている知らせ
    for (const key of ['legal', 'shipping', 'design']) {
        await expect(page.getByTestId(`setup-step-${key}`)).toHaveAttribute('data-done', 'false');
    }
    await expect(page.getByTestId('setup-step-publish').getByTestId('step-note')).toContainText('サンプルが残っています');
});

test('公開した店では数・やること・最近の注文が主役で、開設の手順は畳まれている（390px でも崩れない）', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, transaction: true });
    await page.goto(`/tenants/${tenant.id}`);
    await page.getByTestId('welcome-close').click();

    // 今日の注文 1 件・¥5,000（発送済みの注文）
    await expect(page.getByTestId('stat-today-orders')).toHaveText('1 件');
    await expect(page.getByTestId('stat-month-sales')).toHaveText('¥5,000');
    await expect(page.getByTestId('stat-month-average')).toHaveText('¥5,000');
    await expect(page.getByTestId('recent-order')).toHaveCount(1);
    await expect(page.getByTestId('home-todo')).toBeVisible();
    // 個人情報は出さない
    await expect(page.locator('main')).not.toContainText(tenant.transaction.customer);

    // 開設の手順は畳んで下に。開くと手順が出る
    const toggle = page.getByTestId('setup-toggle');
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByTestId('setup-step-legal')).toHaveCount(0);
    await toggle.click();
    await expect(page.getByTestId('setup-step-publish')).toHaveAttribute('data-done', 'true');
    await expect(page.getByTestId('setup-step-listing')).toHaveAttribute('data-done', 'true');

    // 最近の注文から注文の画面へ
    await page.getByTestId('recent-order').getByRole('link').click();
    await expect(page).toHaveURL(new RegExp(`/transactions/${tenant.transaction.id}$`));

    // 390px: 横にはみ出さない
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goBack();
    await expect(page.getByTestId('home-stats')).toBeVisible();
    await page.getByTestId('setup-toggle').click();
    await expect(page.getByTestId('setup-step-legal')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
});
