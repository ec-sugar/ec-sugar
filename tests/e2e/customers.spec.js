import { expect, runFixture, test } from './support/fixtures.js';

// 管理画面の「お客さん」（ADR 0044 の 2・4）。一覧と 1 人の画面は件数と金額だけ、名前と連絡先は「表示する」まで出さない（見た記録が残る）。
// 名前と電話が同じでメールの違う記録を、店の人がまとめる・分け直す。閲覧の段階の人には名前と連絡先・まとめるのボタンを出さない（サーバーも断る）。
// 下ごしらえ（fixtures.php の --customers）: 花子（払った注文 2 つ・入金待ち 1 つ）、同じ名前と電話の work と old、会員の太郎

// お客さんの番号は ID の後ろの 8 文字（resources/admin/format.js の customerNumber。頭の 10 文字は作った時刻で、続けて作った記録ではそろう）
const customerNumber = (id) => id.slice(-8).toUpperCase();

const PERSONAL = ['hanako@example.com', '山田 花子', '09012345678', '渋谷区', 'taro@example.com', '佐藤 太郎'];

async function expectNoPersonal(page) {
    for (const text of PERSONAL) {
        await expect(page.locator('main')).not.toContainText(text);
    }
}

function rowOf(page, testId, id) {
    return page.locator(`[data-test="${testId}"][data-customer="${id}"]`);
}

test('お客さんの一覧: 会員で絞る・並べ替える・数は払われた注文だけ・名前は出さない', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const { id: tenant, customers } = makeTenant({ customers: true });
    const { hanako, work, old, taro } = customers;

    await page.goto(`/tenants/${tenant}`);
    await page.getByRole('navigation', { name: '店のメニュー' }).getByRole('link', { name: 'お客さん' }).click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant}/customers$`));
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('お客さん');
    await expect(page.getByTestId('list-total')).toHaveText('4 件');

    // 既定は最後の注文の新しい順（作った順の逆）。花子の数は払われた 2 つだけ（入金待ちは入れない）
    const rows = page.getByTestId('customer-row');
    await expect(rows).toHaveCount(4);
    expect(await rows.evaluateAll((list) => list.map((row) => row.dataset.customer))).toEqual([taro.id, old.id, work.id, hanako.id]);
    // 番号は ID の後ろの 8 文字（頭は作った時刻なので、続けて作った記録では同じになる）。4 人とも違う
    await expect(rows.getByRole('link')).toHaveText([taro, old, work, hanako].map((c) => customerNumber(c.id)));
    expect(new Set(await rows.getByRole('link').allTextContents()).size).toBe(4);
    await expect(rowOf(page, 'customer-row', hanako.id).getByTestId('customer-orders')).toHaveText('2');
    await expect(rowOf(page, 'customer-row', hanako.id).getByTestId('customer-sales')).toHaveText('¥7,700');
    await expect(rowOf(page, 'customer-row', taro.id).getByTestId('customer-member')).toHaveText('会員');
    await expect(rowOf(page, 'customer-row', hanako.id).getByTestId('customer-member')).toHaveText('ゲスト');
    await expectNoPersonal(page);

    // 会員で絞る（件数は選択肢に）。URL に残り、読み込み直しても同じ
    await expect(page.getByTestId('member-filter').locator('option')).toHaveText(['すべて（4）', '会員（1）', 'ゲスト（3）']);
    await page.getByTestId('member-filter').selectOption('member');
    await expect(page).toHaveURL(/status=member/);
    await expect(rows).toHaveCount(1);
    await expect(page.getByTestId('list-total')).toHaveText('1 件（絞り込み中）');
    await page.reload();
    await expect(rows).toHaveCount(1);
    await expect(rowOf(page, 'customer-row', taro.id)).toBeVisible();
    await page.getByTestId('member-filter').selectOption('guest');
    await expect(rows).toHaveCount(3);
    await expect(rowOf(page, 'customer-row', taro.id)).toHaveCount(0);
    await page.getByTestId('clear-filters').click();
    await expect(rows).toHaveCount(4);

    // 並べ替え: 合計の高い順（選択）と、注文の数の多い順（見出し）。どちらも多い順だけで、もう一度押しても向きは変わらない
    await page.getByTestId('sort-select').selectOption('-sales');
    await expect(page).toHaveURL(/sort=-sales/);
    await expect.poll(() => rows.evaluateAll((list) => list.map((row) => row.dataset.customer))).toEqual([hanako.id, taro.id, work.id, old.id]);
    await page.getByTestId('sort-orders').click();
    await expect(page).toHaveURL(/sort=-orders/);
    await expect(rows.first()).toHaveAttribute('data-customer', hanako.id);
    await page.getByTestId('sort-orders').click();
    await expect(page).toHaveURL(/sort=-orders/);
    await expect(page.getByTestId('sort-select')).toHaveValue('-orders');

    // 行を押すと 1 人の画面へ
    await rowOf(page, 'customer-row', hanako.id).getByRole('link').click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant}/customers/${hanako.id}$`));
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(`お客さん ${customerNumber(hanako.id)}`);
});

test('1 人の画面: 注文の表、名前と連絡先は表示で記録が残り、同じお客さんをまとめて分け直す', async ({ page, operator, makeTenant }) => {
    void operator;
    const { id: tenant, customers } = makeTenant({ customers: true });
    const { hanako, work, old } = customers;

    await page.goto(`/tenants/${tenant}/customers/${hanako.id}`);
    await expect(page.getByTestId('customer-stat-orders')).toHaveText('2 件');
    await expect(page.getByTestId('customer-stat-sales')).toHaveText('¥7,700');
    await expect(page.getByTestId('customer-stat-member')).toHaveText('ゲスト');
    // 注文の表には入金待ちも並ぶ。行から注文の画面へ
    const orders = page.getByTestId('customer-order-row');
    await expect(orders).toHaveCount(3);
    await expect(orders.filter({ hasText: '入金待ち' })).toHaveCount(1);
    await expect(orders.filter({ hasText: '入金済み' })).toHaveCount(2);
    await expectNoPersonal(page);

    // 名前と連絡先: 押すまで出さない。押すと出て、見た記録が監査ログに残る
    expect(runFixture('audit', tenant, 'operator.personal_data_viewed').events).toEqual([]);
    await page.getByTestId('show-personal').click();
    const details = page.getByTestId('personal-details');
    await expect(details).toContainText('hanako@example.com');
    await expect(details).toContainText('山田 花子');
    await expect(details).toContainText('09012345678');
    await expect(details).toContainText('〒150-0001 東京都 渋谷区 神宮前 9-9');
    expect(runFixture('audit', tenant, 'operator.personal_data_viewed').events).toEqual([{ subject: 'customer', customer_account_id: hanako.id }]);
    await page.getByTestId('hide-personal').click();
    await expectNoPersonal(page);

    // 同じお客さんかもしれない記録: 名前と電話が同じでメールの違う work と old
    const similar = page.getByTestId('similar-row');
    await expect(similar).toHaveCount(2);
    await expect(page.locator('main')).not.toContainText('hanako.work@example.com');

    // work をまとめる: 問いに注文は書き換えない・分け直せると書き、まとめると数に足され、注文の表に印が付く
    await rowOf(page, 'similar-row', work.id).getByTestId('merge').click();
    await expect(rowOf(page, 'similar-row', work.id)).toContainText('注文そのものは書き換えず');
    await rowOf(page, 'similar-row', work.id).getByTestId('confirm-merge').click();
    await expect(page.getByTestId('merge-done')).toContainText('まとめました');
    await expect(similar).toHaveCount(1);
    await expect(rowOf(page, 'similar-row', old.id)).toBeVisible();
    await expect(page.getByTestId('merged-row')).toHaveCount(1);
    await expect(rowOf(page, 'merged-row', work.id)).toBeVisible();
    await expect(page.getByTestId('customer-stat-orders')).toHaveText('3 件');
    await expect(page.getByTestId('customer-stat-sales')).toHaveText('¥9,700');
    await expect(orders).toHaveCount(4);
    await expect(page.locator('[data-test="customer-order-row"][data-from-merged="true"]')).toHaveCount(1);
    await expect(page.locator('[data-test="customer-order-row"][data-from-merged="true"]')).toContainText('まとめた記録の注文');

    // まとめた後は、まとめた記録のメールも表示で見える
    await page.getByTestId('show-personal').click();
    await expect(page.getByTestId('personal-merged-emails')).toContainText('hanako.work@example.com');

    // 一覧では、まとめた記録は消えて親に足される
    await page.goto(`/tenants/${tenant}/customers`);
    await expect(page.getByTestId('customer-row')).toHaveCount(3);
    await expect(rowOf(page, 'customer-row', work.id)).toHaveCount(0);
    await expect(rowOf(page, 'customer-row', hanako.id).getByTestId('customer-orders')).toHaveText('3');
    await expect(rowOf(page, 'customer-row', hanako.id).getByTestId('customer-sales')).toHaveText('¥9,700');
    await expect(rowOf(page, 'customer-row', hanako.id).getByTestId('customer-merged')).toHaveText('1');

    // まとめられた記録を開くと、まとめた先へのリンク。まとめる欄は出さない
    await page.goto(`/tenants/${tenant}/customers/${work.id}`);
    await expect(page.getByTestId('merged-into')).toContainText(`お客さん ${customerNumber(hanako.id)} にまとめられています`);
    await expect(page.getByTestId('customer-similar')).toHaveCount(0);
    await page.getByTestId('merged-into-link').click();
    await expect(page).toHaveURL(new RegExp(`/customers/${hanako.id}$`));

    // 断られたとき: old の画面で、まとめた記録を持つ花子をまとめようとすると、サーバーの文を出す
    await page.goto(`/tenants/${tenant}/customers/${old.id}`);
    await expect(similar).toHaveCount(1);
    await rowOf(page, 'similar-row', hanako.id).getByTestId('merge').click();
    await rowOf(page, 'similar-row', hanako.id).getByTestId('confirm-merge').click();
    await expect(page.getByTestId('merge-error')).toHaveText(/まとめた記録があります。先にそちらを分け直してください/);
    await expect(page.getByTestId('customer-stat-orders')).toHaveText('1 件');

    // 分け直すと元どおり
    await page.goto(`/tenants/${tenant}/customers/${hanako.id}`);
    await rowOf(page, 'merged-row', work.id).getByTestId('unmerge').click();
    await rowOf(page, 'merged-row', work.id).getByTestId('confirm-unmerge').click();
    await expect(page.getByTestId('unmerge-done')).toContainText('分け直しました');
    await expect(page.getByTestId('merged-row')).toHaveCount(0);
    await expect(page.getByTestId('customer-stat-orders')).toHaveText('2 件');
    await expect(similar).toHaveCount(2);
    await page.goto(`/tenants/${tenant}/customers`);
    await expect(page.getByTestId('customer-row')).toHaveCount(4);
});

test('閲覧のスタッフには、名前と連絡先・まとめるのボタンを出さない（サーバーも断る）', async ({ page, staff, makeTenant }) => {
    const tenant = makeTenant({ customers: true });
    const { hanako, work } = tenant.customers;
    await staff(tenant, 'read');

    await page.goto(`/tenants/${tenant.id}/customers`);
    await expect(page.getByTestId('customer-row')).toHaveCount(4);
    await rowOf(page, 'customer-row', hanako.id).getByRole('link').click();
    await expect(page.getByTestId('customer-stat-orders')).toHaveText('2 件');
    await expect(page.getByTestId('customer-order-row')).toHaveCount(3);
    await expect(page.getByTestId('personal-no-permission')).toBeVisible();
    await expect(page.getByTestId('show-personal')).toHaveCount(0);
    await expect(page.getByTestId('customer-similar')).toHaveCount(0);
    await expect(page.getByTestId('merge')).toHaveCount(0);
    await expectNoPersonal(page);

    // 画面で隠すのは見た目だけ。API を直接たたいてもサーバーが断る
    const statuses = await page.evaluate(async ({ shop, parent, child }) => {
        const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
        const headers = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token };
        const base = `/api/tenants/${shop}/customers/${parent}`;

        return {
            personal: await fetch(`${base}/personal`, { headers }).then((r) => r.status),
            similar: await fetch(`${base}/similar`, { headers }).then((r) => r.status),
            merge: await fetch(`${base}/merge`, { method: 'POST', headers, body: JSON.stringify({ merged_id: child }) }).then((r) => r.status),
        };
    }, { shop: tenant.id, parent: hanako.id, child: work.id });
    expect(statuses).toEqual({ personal: 403, similar: 403, merge: 403 });
    expect(runFixture('audit', tenant.id, 'operator.personal_data_viewed').events).toEqual([]);
});
