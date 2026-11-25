import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// ページの編集の画面（ADR 0039 区切り 2）。左に区画の一覧、真ん中にショップのホストの下書きのプレビュー（枠）、右に選んだ区画の設定。
// 区画を足す・並べ替えると自動で下書きに保存し、プレビューの順が変わる。プレビューの区画を押すとその区画を選ぶ。公開するとショップに出る。
// 区画を並べられるテーマはまだ見本（tests/Support/Themes/sample。env.js の STOREFRONT_SAMPLE_THEME）だけ

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;

test('区画を足して並べ替えると、下書きのプレビューの順が変わり、公開するとショップに出る', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 2, theme: 'sample' });

    // テーマの中身の画面から入る（枠を許す殻を読むため、文書ごと読み直して入る）
    await page.goto(`/tenants/${tenant.id}/theme`);
    await page.getByTestId('open-page-editor').click();
    await expect(page).toHaveURL(new RegExp(`/tenants/${tenant.id}/theme/pages/home\\?theme=sample$`));
    await expect(page.getByRole('heading', { name: 'トップの編集' })).toBeVisible();

    // まだ保存していない: テーマの最初の並び（お知らせの帯・ヒーロー・特集・商品の並び・カテゴリ）
    const rows = page.getByTestId('section-row');
    await expect(rows).toHaveCount(5);
    await expect(page.getByTestId('draft-state')).toHaveText('下書き（まだ公開していません）');
    const preview = page.frameLocator('[data-test="page-preview"]');
    await expect(preview.locator('[data-section]').first()).toHaveAttribute('data-test', 'hero');

    // ヒーローの「左右」を足す（選んでいるお知らせの帯の後ろへ）。見出しを入れると少し待って自動で保存し、プレビューに出る
    await page.getByTestId('add-section').click();
    await page.getByTestId('add-hero-split').click();
    await expect(rows).toHaveCount(6);
    await expect(rows.nth(1)).toHaveAttribute('data-section-type', 'hero');
    await page.getByTestId('field-1-heading').fill('新しいヒーロー');
    await expect(preview.locator('[data-test="hero"][data-pattern="split"]')).toContainText('新しいヒーロー');
    await expect(page.getByTestId('draft-state')).toHaveText('下書き（まだ公開していません）');

    // 商品の並びを一番上へ（↑ のボタン 4 回）。プレビューの最初の区画が商品の並びになる
    const rail = page.locator('[data-test="section-row"][data-section-type="rail"]');
    for (let i = 0; i < 4; i++) {
        await rail.getByRole('button', { name: /を上へ/ }).click();
    }
    await expect(rows.first()).toHaveAttribute('data-section-type', 'rail');
    await expect(preview.locator('[data-section]').first()).toHaveAttribute('data-test', 'rail');

    // プレビューで区画を押すと、その区画を左と右で選ぶ（リンクは動かない）
    await preview.getByText('新しいヒーロー').click();
    await expect(rows.nth(2).getByTestId('section-select')).toHaveAttribute('aria-current', 'true');
    await expect(page.getByTestId('field-2-heading')).toHaveValue('新しいヒーロー');
    await expect(preview.locator('[data-sugar-selected]')).toHaveAttribute('data-pattern', 'split');

    // 下書きはまだショップに出ていない（別のタブで開く）
    const shop = await page.context().newPage();
    await shop.goto(storefront(tenant));
    await expect(shop.getByTestId('hero')).toHaveCount(1);
    await expect(shop.getByText('新しいヒーロー')).toHaveCount(0);

    // 打ってすぐ公開する（確かめてから）。自動の保存を待たずに押しても、打った分を保存してから最新の改訂で公開する
    await page.getByTestId('field-2-heading').fill('公開の直前の見出し');
    await page.getByTestId('publish-page').click();
    await page.getByTestId('confirm-publish-page').click();
    await expect(page.getByRole('status').filter({ hasText: '公開しました' })).toBeVisible();
    await expect(page.getByTestId('draft-state')).toHaveText('公開中の内容と同じ');

    // ショップは公開した順（商品の並び → 足したヒーロー → 元のヒーロー）
    await shop.reload();
    const order = await shop.locator('[data-section]').evaluateAll((elements) => elements.map((e) => `${e.dataset.test}:${e.dataset.pattern ?? ''}`));
    expect(order).toEqual(['rail:', 'hero:split', 'hero:full']);
    await expect(shop.getByText('公開の直前の見出し')).toBeVisible();
    await shop.close();

    if (process.env.E2E_SCREENSHOTS) {
        await page.screenshot({ path: 'test-results/screens/page-editor.png', fullPage: true });
        await page.getByRole('button', { name: 'スマホ' }).click();
        await page.screenshot({ path: 'test-results/screens/page-editor-mobile.png', fullPage: true });
    }
});

// 区画の見本（theme.json の example・example_blocks）。足した区画には見本の文とブロックが入っていて、そのまま下書きのプレビューに出る。
// ブロックを足すときも、そのブロックの種類の見本が入る
test('足した区画に見本の文が入っていてプレビューに出る', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, theme: 'sample' });

    await page.goto(`/tenants/${tenant.id}/theme`);
    await page.getByTestId('open-page-editor').click();
    await expect(page.getByRole('heading', { name: 'トップの編集' })).toBeVisible();
    const rows = page.getByTestId('section-row');
    await expect(rows).toHaveCount(5);

    // ヒーローの「左右」を足す（選んでいるお知らせの帯の後ろへ）。欄に見本の値、中に見本のボタンが 1 つ
    await page.getByTestId('add-section').click();
    await page.getByTestId('add-hero-split').click();
    await expect(rows).toHaveCount(6);
    await expect(page.getByTestId('field-1-heading')).toHaveValue('見出しを入れてください');
    await expect(page.getByTestId('field-1-link')).toHaveValue('/products');
    await expect(rows.nth(1).getByTestId('block-row')).toHaveCount(1);

    // 自動の保存のあと、プレビューに見本の見出しとボタンが出る
    const preview = page.frameLocator('[data-test="page-preview"]');
    const hero = preview.locator('[data-test="hero"][data-pattern="split"]');
    await expect(hero.getByTestId('hero-heading')).toHaveText('見出しを入れてください');
    await expect(hero.getByTestId('hero-button')).toHaveText('くわしく見る');

    // ボタンをもう 1 つ足すと、ボタンの見本が入る
    await rows.nth(1).getByTestId('block-add').selectOption('button');
    await expect(rows.nth(1).getByTestId('block-row')).toHaveCount(2);
    await expect(page.getByTestId('block-field-label')).toHaveValue('くわしく見る');
    await expect(hero.getByTestId('hero-button')).toHaveCount(2);
});

// 「この区画を消す」の確かめは、選んでいる区画のもの。消した後に次の区画が選ばれても、その区画の確かめは閉じている
// （前は開いたまま残り、もう 1 回押すと次の区画まで消えた）。開いたまま別の区画を選ぶ・並べ替えるときも閉じる
test('区画を消した後に、別の区画の消す確かめが開いていない', async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 1, theme: 'sample' });

    await page.goto(`/tenants/${tenant.id}/theme`);
    await page.getByTestId('open-page-editor').click();
    await expect(page.getByRole('heading', { name: 'トップの編集' })).toBeVisible();
    const rows = page.getByTestId('section-row');
    await expect(rows).toHaveCount(5);
    const remove = page.getByTestId('remove-section');
    const confirmRemove = page.getByTestId('confirm-remove-section');

    // 2 番目のヒーローを選んで消す。次の特集が選ばれ、その「消す」は閉じた形（1 回目のボタン）で出る
    await rows.nth(1).getByTestId('section-select').click();
    await remove.click();
    await confirmRemove.click();
    await expect(rows).toHaveCount(4);
    await expect(rows.nth(1)).toHaveAttribute('data-section-type', 'features');
    await expect(rows.nth(1).getByTestId('section-select')).toHaveAttribute('aria-current', 'true');
    await expect(confirmRemove).toHaveCount(0);
    await expect(remove).toBeVisible();

    // 確かめを開いたまま別の区画を選ぶと閉じる
    await remove.click();
    await expect(confirmRemove).toBeVisible();
    await rows.nth(2).getByTestId('section-select').click();
    await expect(confirmRemove).toHaveCount(0);

    // 確かめを開いたまま並べ替えると閉じる（選んでいる商品の並びを上へ）
    await remove.click();
    await expect(confirmRemove).toBeVisible();
    await rows.nth(2).getByRole('button', { name: /を上へ/ }).click();
    await expect(rows.nth(1)).toHaveAttribute('data-section-type', 'rail');
    await expect(confirmRemove).toHaveCount(0);
    await expect(rows).toHaveCount(4);
});

// 「＋ 区画を足す」はワイヤーの絵で選ぶ。絵に指を置く・焦点を当てると、選んでいる区画の後ろに見本の値の区画がプレビューの中へ仮に出て、離れると消える。
// 押すと足す。試している間は何も保存しない（区画の行も下書きの印も変わらない）
test('区画を足す欄はワイヤーの絵で並び、指を置くとプレビューで試せる', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
    void operator;
    const tenant = makeTenant({ listings: 2, theme: 'sample' });

    await page.goto(`/tenants/${tenant.id}/theme`);
    await page.getByTestId('open-page-editor').click();
    await expect(page.getByRole('heading', { name: 'トップの編集' })).toBeVisible();
    const rows = page.getByTestId('section-row');
    await expect(rows).toHaveCount(5);
    const preview = page.frameLocator('[data-test="page-preview"]');
    await expect(preview.locator('[data-section]').first()).toHaveAttribute('data-test', 'hero');

    // 種類ごとに、パターンの絵のカード（絵はテーマが選んだ核の語彙。読み上げの名前は種類とパターン）
    await page.getByTestId('add-section').click();
    await expect(page.getByTestId('add-group-hero').getByRole('button')).toHaveCount(3);
    await expect(page.getByTestId('add-hero-split').locator('svg')).toHaveAttribute('data-wireframe', 'hero-split');
    await expect(page.getByTestId('add-rail-default').locator('svg')).toHaveAttribute('data-wireframe', 'products-grid');
    await expect(page.getByRole('button', { name: 'ヒーローの左右', exact: true })).toBeVisible();
    // お知らせの帯は 1 つまでで、もう置いてあるので押せない
    await expect(page.getByTestId('add-announcement-default')).toBeDisabled();

    // 指を置くと、選んでいるお知らせの帯の後ろ（枠の中ではヒーローの前）に、見本の値のヒーロー「左右」が仮に出る
    const trial = preview.locator('[data-sugar-trial-node]');
    await page.getByTestId('add-hero-split').hover();
    await expect(trial).toHaveCount(1);
    await expect(trial).toHaveAttribute('data-pattern', 'split');
    await expect(trial).toContainText('見出しを入れてください');
    const order = await preview.locator('[data-section], [data-sugar-trial-node]').evaluateAll((elements) => elements.map((e) => (e.hasAttribute('data-sugar-trial-node') ? 'trial' : e.dataset.test)));
    expect(order.slice(0, 2)).toEqual(['trial', 'hero']);

    // 隣の絵へ動かすと入れ替わり、離れると消える
    await page.getByTestId('add-features-default').hover();
    await expect(trial.first()).toContainText('特集の題を入れてください');
    await expect(preview.locator('[data-sugar-trial-node][data-pattern="split"]')).toHaveCount(0);
    await page.getByTestId('editor-back').hover();
    await expect(trial).toHaveCount(0);
    // 消しても、道筋を持つ編集の印は残る（試しの区画と印を取り違えない）
    await expect(preview.locator('[data-sugar-editor][data-sugar-trial]')).toHaveCount(1);

    // キーボードで焦点を当てても試せる。何も保存していない
    await page.getByTestId('add-rail-default').focus();
    await expect(trial.first()).toContainText('新着の商品');
    await expect(rows).toHaveCount(5);
    await expect(page.getByTestId('draft-state')).toHaveText('下書き（まだ公開していません）');

    // 押すと足す（試しの区画は消え、保存した後のプレビューに本物の区画が出る）
    await page.getByTestId('add-hero-grid').click();
    await expect(rows).toHaveCount(6);
    await expect(rows.nth(1)).toHaveAttribute('data-section-type', 'hero');
    await expect(preview.locator('[data-test="hero"][data-pattern="grid"]')).toHaveCount(1);
    await expect(trial).toHaveCount(0);

    if (process.env.E2E_SCREENSHOTS) {
        await page.getByTestId('add-section').click();
        await page.getByTestId('add-hero-split').hover();
        await page.screenshot({ path: 'test-results/screens/page-editor-add.png', fullPage: true });
    }
});

// 1280px の幅: 左の一覧の長い題（角砂糖の「ヒーローとショップの説明」）は 2 行まで折り返して ↑ ↓ のボタンに重ねない。
// 核の区画の試し見は、点線の輪郭と中身の文の間に余白がある（文が輪郭に触れない）
test.describe('1280px の幅', () => {
    test.use({ viewport: { width: 1280, height: 900 } });

    test('長い区画の題が並べ替えのボタンに重ならず、核の区画の試し見は文が点線の輪郭に触れない', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
        void operator;
        const tenant = makeTenant({ listings: 1, theme: 'default' });

        await page.goto(`/tenants/${tenant.id}/theme`);
        await page.getByTestId('open-page-editor').click();
        await expect(page.getByRole('heading', { name: 'トップの編集' })).toBeVisible();

        const hero = page.locator('[data-test="section-row"][data-section-type="hero"]');
        const label = hero.getByTestId('section-label');
        await expect(label).toHaveText('ヒーローとショップの説明');
        const text = await label.boundingBox();
        const up = await hero.locator('[data-move="up"]').boundingBox();
        expect(text.x + text.width).toBeLessThanOrEqual(up.x);
        // 2 行より長い題は省くので、指を置くと全部見える
        await expect(hero.getByTestId('section-select')).toHaveAttribute('title', 'ヒーローとショップの説明');
        if (process.env.E2E_SCREENSHOTS) {
            await page.screenshot({ path: 'test-results/screens/page-editor-1280-list.png' });
        }

        // 特長の 3 列に指を置くと試し見が出る（角砂糖は区画の上下の余白が 0）。中身の文は、内側に描く点線の輪郭から四方とも余白の分だけ離れる
        await page.getByTestId('add-section').click();
        await page.getByTestId('add-basic_features-three').hover();
        const preview = page.frameLocator('[data-test="page-preview"]');
        const trial = preview.locator('.sugar-features[data-sugar-trial-node]');
        await expect(trial).toHaveCount(1);
        const gaps = await trial.evaluate((node) => {
            const box = node.getBoundingClientRect();
            const parts = [...node.querySelectorAll('h2, h3, p')].map((e) => e.getBoundingClientRect()).filter((r) => r.width > 0);

            return {
                left: Math.min(...parts.map((r) => r.left)) - box.left,
                right: box.right - Math.max(...parts.map((r) => r.right)),
                top: Math.min(...parts.map((r) => r.top)) - box.top,
                bottom: box.bottom - Math.max(...parts.map((r) => r.bottom)),
            };
        });
        for (const side of ['left', 'right', 'top', 'bottom']) {
            expect(gaps[side], `${side} の余白`).toBeGreaterThanOrEqual(8);
        }

        if (process.env.E2E_SCREENSHOTS) {
            await page.screenshot({ path: 'test-results/screens/page-editor-1280.png', fullPage: true });
        }
    });
});

test.describe('スマホの幅と指の操作', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

    test('区画を足す欄は 390px の幅ではみ出さず、1 回目で試し、「足す」で足す', { tag: '@nightly' }, async ({ page, operator, makeTenant }) => {
        void operator;
        const tenant = makeTenant({ listings: 1, theme: 'sample' });

        await page.goto(`/tenants/${tenant.id}/theme`);
        await page.getByTestId('open-page-editor').click();
        await expect(page.getByRole('heading', { name: 'トップの編集' })).toBeVisible();
        const rows = page.getByTestId('section-row');
        await expect(rows).toHaveCount(5);

        await page.getByTestId('add-section').tap();
        await expect(page.getByTestId('add-hero-split')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);

        // 1 回目は試すだけ（足さない）。「足す」で足す
        await page.getByTestId('add-hero-split').tap();
        const preview = page.frameLocator('[data-test="page-preview"]');
        await expect(preview.locator('[data-sugar-trial-node]')).toHaveAttribute('data-pattern', 'split');
        await expect(rows).toHaveCount(5);
        await page.getByTestId('add-armed').tap();
        await expect(rows).toHaveCount(6);
        await expect(rows.nth(1)).toHaveAttribute('data-section-type', 'hero');
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    });
});
