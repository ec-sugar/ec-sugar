import { existsSync } from 'node:fs';
import { expect, test } from './support/fixtures.js';

// 商品の画面の「App」の節（ADR 0050）。使っている App のうち商品ごとの欄を持つもの（定期便）だけを、核の部品で描く。
// 保存は商品の画面の下の「保存する」でまとめて。閲覧の段階の人には値を見せるだけ。
// E2E_SCREENSHOTS=1 のときは写しも撮る（test-results/screens/listing-apps.png）

async function openFirstListing(page, tenant) {
    await page.goto(`/tenants/${tenant.id}/listings`);
    await page.getByTestId('listing-row').first().getByRole('link').first().click();
    await expect(page.getByTestId('listing-title')).toHaveValue('商品 1');
}

// 例は定期便の App。定期便の App が入っていない木（公開用の 1.0。ADR 0098）では、このファイルの場面を飛ばす
test.skip(!existsSync('plugins/plugin-subscription/composer.json'), '定期便の App が入っていない木（公開用の 1.0。ADR 0098）');

test('定期便を使う店で、商品の画面の App の節から定期便を設定して保存し、読み直しても残る', async ({ page, operator, makeTenant }) => {
    void operator;
    // 定期便を使っていない店では、節も目次の項目も出ない
    const plain = makeTenant({ listings: 1 });
    await openFirstListing(page, plain);
    await expect(page.getByTestId('listing-apps')).toHaveCount(0);
    await expect(page.getByTestId('form-outline').getByRole('button', { name: 'App', exact: true })).toHaveCount(0);

    const tenant = makeTenant({ listings: 1, subscription: true });
    await openFirstListing(page, tenant);
    const panel = page.locator('[data-test="listing-app"][data-plugin="subscription"]');
    await expect(panel.getByRole('heading', { name: '定期便' })).toBeVisible();
    await expect(page.getByTestId('form-outline').getByRole('button', { name: 'App', exact: true })).toBeVisible();
    // まだ設定の無い商品: 定期にしない・毎月を選んでおく・値引きなし
    await expect(panel.getByTestId('app-subscription-enabled-off')).toBeChecked();
    await expect(panel.getByTestId('app-subscription-interval_monthly-on')).toBeChecked();
    await expect(panel.getByTestId('app-subscription-discount')).toHaveValue('off');

    await panel.getByTestId('app-subscription-enabled-on').check();
    await panel.getByTestId('app-subscription-interval_weekly-on').check();
    await panel.getByTestId('app-subscription-discount').selectOption('pct10');
    await expect(page.getByTestId('unsaved')).toBeVisible();
    await page.getByTestId('save-listing').click();
    await expect(page.getByTestId('save-message')).toHaveText('保存しました。');
    await expect(page.getByTestId('unsaved')).toHaveCount(0);

    if (process.env.E2E_SCREENSHOTS) {
        await panel.screenshot({ path: 'test-results/screens/listing-apps.png' });
    }

    await page.reload();
    await expect(panel.getByTestId('app-subscription-enabled-on')).toBeChecked();
    await expect(panel.getByTestId('app-subscription-interval_weekly-on')).toBeChecked();
    await expect(panel.getByTestId('app-subscription-interval_monthly-on')).toBeChecked();
    await expect(panel.getByTestId('app-subscription-interval_biweekly-off')).toBeChecked();
    await expect(panel.getByTestId('app-subscription-discount')).toHaveValue('pct10');

    // 定期でも買えるのに周期を 1 つも選ばないと、欄の下に理由が出て保存されない
    await panel.getByTestId('app-subscription-interval_weekly-off').check();
    await panel.getByTestId('app-subscription-interval_monthly-off').check();
    await page.getByTestId('save-listing').click();
    await expect(page.getByTestId('save-failed')).toContainText('定期便');
    await expect(panel.getByRole('alert')).toContainText('1 つ以上');
    await page.reload();
    await expect(panel.getByTestId('app-subscription-interval_weekly-on')).toBeChecked();
});

test('閲覧のスタッフは定期便の欄を見られるが、変えられず保存のボタンも無い', async ({ page, staff, makeTenant }) => {
    const tenant = makeTenant({ listings: 1, subscription: true });
    await staff(tenant, 'read');
    await openFirstListing(page, tenant);

    const panel = page.locator('[data-test="listing-app"][data-plugin="subscription"]');
    await expect(panel.getByTestId('app-subscription-enabled-off')).toBeChecked();
    await expect(panel.getByTestId('app-subscription-enabled-on')).toBeDisabled();
    await expect(panel.getByTestId('app-subscription-discount')).toBeDisabled();
    await expect(page.getByTestId('save-listing')).toHaveCount(0);
    await expect(page.getByTestId('listing-read-only')).toBeVisible();
});
