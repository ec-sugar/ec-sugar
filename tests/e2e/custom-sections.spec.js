import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// カスタム区画: 「自由な HTML」と「見たまま書ける文」。
// 自由な HTML に危ないもの（script・on… の属性・外の写真・javascript: のリンク・id）を書くと、欄の下に外すものが並び、プレビューとショップには残るものだけが出る。
// 見たまま書ける文は道具の帯のボタンで太字とリストを付けられる（管理画面の Trusted Types の下で。違反は全場面の見張りが落とす）。
// ショップ（390px）では、スクリプトが動かず（alert が出ない・CSP の違反が無い）、横にはみ出さない

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

const ATTACK = '<h2 id="cart">自由な見出し</h2><p onclick="alert(1)">自由な文</p><script>alert(1)</script>'
    + '<img src="https://evil.example/a.png" onerror="alert(1)"><a href="javascript:alert(1)">悪いリンク</a>'
    + '<div class="sugar-cols-2 fixed"><div><p>左の列</p></div><div><p>右の列</p></div></div>';

test('カスタム区画を足して公開すると、整えた形でショップに出て、スクリプトは動かない', async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, theme: 'kinari' });

    await page.goto(`/tenants/${tenant.id}/theme`);
    await page.getByTestId('open-page-editor').click();
    await expect(page.getByRole('heading', { name: 'トップの編集' })).toBeVisible();
    const rows = page.getByTestId('section-row');
    const before = await rows.count();
    const preview = page.frameLocator('[data-test="page-preview"]');

    // 自由な HTML: 絵は核の語彙。足すと見本の HTML が欄に入り、プレビューに 2 列の箱が出る
    await page.getByTestId('add-section').click();
    await expect(page.getByTestId('add-basic_html-narrow').locator('svg')).toHaveAttribute('data-wireframe', 'html-narrow');
    await page.getByTestId('add-basic_html-narrow').click();
    await expect(rows).toHaveCount(before + 1);
    const input = page.getByTestId('html-input');
    await expect(input).toHaveValue(/sugar-cols-2/);
    await expect(preview.locator('.sugar-custom .sugar-html .sugar-cols-2')).toBeVisible();

    // 危ないものを書くと、外すものが欄の下に並ぶ。プレビューには残るものだけ
    await input.fill(ATTACK);
    const removed = page.getByTestId('html-removed');
    await expect(removed).toContainText('<script>');
    await expect(removed).toContainText('onclick の属性（スクリプト）');
    await expect(removed).toContainText('写真「https://evil.example/a.png」');
    await expect(removed).toContainText('リンク先「javascript:alert(1)」');
    await expect(removed).toContainText('class「fixed」');
    const custom = preview.locator('[data-section-type="basic_html"] .sugar-html');
    await expect(custom.locator('h2')).toHaveText('自由な見出し');
    await expect(custom.locator('script, img, [onclick], [id]')).toHaveCount(0);

    // 「整えた形を欄に入れる」で、保存される形が欄に入る
    await page.getByTestId('html-use-cleaned').click();
    await expect(input).not.toHaveValue(/script|onclick|evil/);
    await expect(page.getByTestId('html-clean')).toBeVisible();

    // 見たまま書ける文: 道具の帯で、打った行を太字にして箇条書きに
    await page.getByTestId('add-section').click();
    await page.getByTestId('add-basic_richtext-wide').click();
    await expect(rows).toHaveCount(before + 2);
    const surface = page.getByTestId('rt-surface');
    await expect(surface.locator('h2')).toHaveText('見出しを入力してください');
    // 段落の終わりで改行して打ち、打った行を選ぶ（End・Home のキーは OS で動きが違うので、カーソルは決まった形で置く）
    await surface.locator('p').first().click();
    await surface.evaluate((box) => {
        const range = document.createRange();
        range.selectNodeContents(box.querySelector('p'));
        range.collapse(false);
        document.getSelection().removeAllRanges();
        document.getSelection().addRange(range);
    });
    await page.keyboard.press('Enter');
    await page.keyboard.type('新しい行');
    await surface.evaluate((box) => {
        const text = [...box.querySelectorAll('p')].flatMap((p) => [...p.childNodes]).find((node) => node.nodeType === Node.TEXT_NODE && node.data === '新しい行');
        const range = document.createRange();
        range.selectNodeContents(text);
        document.getSelection().removeAllRanges();
        document.getSelection().addRange(range);
    });
    await page.getByTestId('rt-bold').click();
    await expect(surface.locator('p strong').filter({ hasText: '新しい行' })).toHaveCount(1);
    await page.getByTestId('rt-ul').click();
    await expect(surface.locator('li strong')).toHaveText('新しい行');
    const rich = preview.locator('[data-section-type="basic_richtext"] .sugar-html');
    await expect(rich.locator('li strong')).toHaveText('新しい行');

    await page.getByTestId('publish-page').click();
    await page.getByTestId('confirm-publish-page').click();
    await expect(page.getByRole('status').filter({ hasText: '公開しました' })).toBeVisible();

    // ショップ（スマホの幅）: 整えた形で出て、alert も CSP の違反も無く、横にはみ出さない
    const shop = await page.context().newPage();
    const problems = [];
    shop.on('dialog', async (dialog) => {
        problems.push(`dialog: ${dialog.message()}`);
        await dialog.dismiss();
    });
    shop.on('console', (message) => {
        if (/Content Security Policy|Trusted Type/i.test(message.text())) {
            problems.push(message.text());
        }
    });
    shop.on('pageerror', (error) => problems.push(`pageerror: ${error.message}`));
    await shop.setViewportSize({ width: 390, height: 844 });
    await shop.goto(storefront(tenant));

    const html = shop.locator('main [data-section-type="basic_html"] .sugar-html');
    await expect(html.locator('h2')).toHaveText('自由な見出し');
    await expect(html.locator('p').first()).toHaveText('自由な文');
    await expect(html.locator('.sugar-cols-2 > div')).toHaveCount(2);
    await expect(html.locator('script, img, iframe, [onclick], [onerror], [id], [style]')).toHaveCount(0);
    await expect(html.locator('a')).not.toHaveAttribute('href', /.+/);
    await expect(shop.locator('main [data-section-type="basic_richtext"] .sugar-html li strong')).toHaveText('新しい行');
    // 狭い画面では 2 列の箱も 1 列
    expect(await html.locator('.sugar-cols-2').evaluate((e) => getComputedStyle(e).gridTemplateColumns.split(' ').length)).toBe(1);
    expect(await shop.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    await html.locator('p').first().click();
    expect(problems).toEqual([]);

    if (process.env.E2E_SCREENSHOTS) {
        await shop.screenshot({ path: 'test-results/screens/custom-sections-kinari-390.png', fullPage: true });
        await page.screenshot({ path: 'test-results/screens/custom-sections-editor.png', fullPage: true });
    }
    await shop.close();
});
