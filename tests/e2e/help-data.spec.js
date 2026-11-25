import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { HELP_PAGES } from '../../resources/admin/navigation.js';
import { articleOf, HELP_ARTICLES, HELP_SCREENS, helpAvailable, plainOf, searchHelp, sectionTexts, stepOf } from '../../resources/admin/help/index.js';

// ヘルプのデータ（resources/admin/help/）の形を確かめる。ブラウザを開かない（Node のままデータを読む）。
// 目次（navigation.js の HELP_PAGES）とファイルが食い違う・節の題や中身が無い・行き先の画面が無い・写しのファイルが無い・
// ヘルプの中のリンクの行き先が無い・HTML や他社の製品名が混ざる、のどれかで落ちる

const ROUTES = new Set([...readFileSync('resources/admin/router.js', 'utf8').matchAll(/name: '([a-z-]+)'/g)].map((m) => m[1]));
const FILES = readdirSync('resources/admin/help').filter((file) => file.endsWith('.js') && file !== 'index.js').map((file) => file.replace(/\.js$/, ''));

// 文の部品をすべて（節の題を除く本文・手順・補足・注意）
function partsIn(section) {
    const steps = (section.steps ?? []).map(stepOf);
    const texts = [
        ...(section.body ?? []), ...(section.list ?? []), ...(section.notes ?? []), ...(section.caution ? [section.caution] : []),
        ...steps.flatMap((step) => [step.text, ...step.notes]),
    ];

    return texts.flatMap((text) => (typeof text === 'string' ? [text] : text));
}

function figuresIn(section) {
    return [...(section.figures ?? []), ...(section.steps ?? []).map(stepOf).map((step) => step.figure).filter(Boolean)];
}

test('ヘルプのデータ: 入っていない App の説明のページは出さない（公開用の 1.0 には定期便などの App が無い。ADR 0098）', () => {
    const subscriptions = articleOf('subscriptions');
    expect(subscriptions.plugin).toBe('subscription');
    expect(helpAvailable(subscriptions, ['faq', 'blog'])).toBe(false);
    expect(helpAvailable(subscriptions, ['subscription'])).toBe(true);
    // 入っている App がまだ分からないときは出す
    expect(helpAvailable(subscriptions, null)).toBe(true);
    // App の説明でないページはいつも出す
    expect(helpAvailable(articleOf('setup'), [])).toBe(true);
    expect(helpAvailable(null, null)).toBe(false);
    // 探した結果にも出さない
    expect(searchHelp('定期便').some((r) => r.article === 'subscriptions')).toBe(true);
    expect(searchHelp('定期便', 30, ['faq']).some((r) => r.article === 'subscriptions')).toBe(false);
});

test('ヘルプのデータ: 目次とファイルがそろい、どの節にも題と中身がある', () => {
    expect(HELP_PAGES.map((page) => page.key).sort()).toEqual([...FILES].sort());

    for (const article of HELP_ARTICLES) {
        expect(article.label, article.key).toBeTruthy();
        expect(article.description, article.key).toBeTruthy();
        expect(article.sections.length, `${article.key} に節が無い`).toBeGreaterThan(0);
        const ids = article.sections.map((section) => section.id);
        expect(new Set(ids).size, `${article.key} の節の印が重なっている`).toBe(ids.length);

        for (const section of article.sections) {
            const where = `${article.key}#${section.id}`;
            expect(section.id, where).toMatch(/^[a-z][a-z0-9-]{0,31}$/);
            expect(typeof section.title === 'string' && section.title.trim() !== '', `${where} に題が無い`).toBe(true);
            expect(sectionTexts(section).join('').trim().length + figuresIn(section).length, `${where} に中身が無い`).toBeGreaterThan(0);
        }
    }
});

test('ヘルプのデータ: 行き先の画面・写し・ヘルプの中のリンクが実在する', () => {
    const screens = HELP_ARTICLES.flatMap((article) => article.sections.flatMap((section) => section.screens ?? []));
    expect(new Set(screens).size, '1 つの画面が 2 つの節の「説明を見る」の行き先になっている').toBe(screens.length);
    expect(Object.keys(HELP_SCREENS).sort()).toEqual([...screens].sort());

    for (const article of HELP_ARTICLES) {
        for (const section of article.sections) {
            const where = `${article.key}#${section.id}`;

            for (const screen of section.screens ?? []) {
                expect(ROUTES.has(screen), `${where}: 画面「${screen}」が router.js に無い`).toBe(true);
            }

            for (const figure of figuresIn(section)) {
                expect(existsSync(`resources/help/${figure.file}`), `${where}: 写し ${figure.file} が resources/help/ に無い`).toBe(true);
                // 幅と高さは PNG の頭（IHDR）と同じ（撮り直して大きさが変わったら、データも直す）
                const head = readFileSync(`resources/help/${figure.file}`).subarray(16, 24);
                const actual = `${head.readUInt32BE(0)}x${head.readUInt32BE(4)}`;
                expect(`${figure.width}x${figure.height}`, `${where}: ${figure.file} の大きさがデータと違う（今は ${actual}）`).toBe(actual);
                expect(figure.alt?.trim(), `${where}: ${figure.file} の代わりの文字`).toBeTruthy();
                expect(figure.caption?.trim(), `${where}: ${figure.file} の説明`).toBeTruthy();
            }

            for (const part of partsIn(section)) {
                if (typeof part === 'string') {
                    continue;
                }

                // 部品は太字かヘルプの中のリンクだけ（HTML や外の URL を入れる形を作らない）
                const keys = Object.keys(part).sort().join(',');
                expect(['strong', 'help,text', 'help,section,text'], `${where}: 知らない形の部品（${keys}）`).toContain(keys);

                if (part.help !== undefined) {
                    const target = HELP_ARTICLES.find((a) => a.key === part.help);
                    expect(target, `${where}: リンクの行き先のページ ${part.help} が無い`).toBeTruthy();

                    if (part.section !== undefined) {
                        expect(target.sections.some((s) => s.id === part.section), `${where}: リンクの行き先の節 ${part.help}#${part.section} が無い`).toBe(true);
                    }

                    expect(part.text?.trim(), `${where}: リンクの文字が無い`).toBeTruthy();
                }
            }
        }
    }
});

test('ヘルプのデータ: 本文に HTML・外の URL・他社の製品名を書かない', () => {
    for (const article of HELP_ARTICLES) {
        for (const section of article.sections) {
            const where = `${article.key}#${section.id}`;
            const text = [section.title, ...sectionTexts(section), ...figuresIn(section).map((figure) => figure.alt)].map(plainOf).join('\n');
            expect(text, `${where}: HTML のタグ`).not.toMatch(/<\/?[a-z][^>]*>/i);
            expect(text, `${where}: 外の URL`).not.toMatch(/https?:\/\//);
            expect(text, `${where}: 他社の製品名（docs/design/terms.md）`).not.toMatch(/\bBASE\b|EC-CUBE|Shopify|\bSTORES\b|カラーミー|MakeShop/i);
        }
    }
});

test('ヘルプの中の検索: 題と本文から探し、全角と半角・大文字と小文字をそろえ、無い言葉は 0 件', () => {
    const shipping = searchHelp('送料');
    expect(shipping.length).toBeGreaterThan(0);
    // 題に当たった節が先
    expect(shipping[0]).toMatchObject({ article: 'setup', section: 'shipping' });
    expect(shipping[0].snippet.some((part) => part.hit && part.text === '送料')).toBe(true);

    // すべての言葉を含む節だけ（空白で区切る。全角の空白も）
    for (const result of searchHelp('返金　Stripe')) {
        const article = HELP_ARTICLES.find((a) => a.key === result.article);
        const section = article.sections.find((s) => s.id === result.section);
        const all = [article.label, section.title, ...sectionTexts(section)].join('\n').toLowerCase();
        expect(all).toContain('返金');
        expect(all).toContain('stripe');
    }

    expect(searchHelp('ｗｅｂｈｏｏｋ').map((r) => r.key)).toEqual(searchHelp('Webhook').map((r) => r.key));
    expect(searchHelp('webhook').length).toBeGreaterThan(0);
    // 本文の全角の記号も、半角で打った言葉で当たる（本文の「（sk_ で始まる）」）
    expect(searchHelp('(sk_').map((r) => r.key)).toContain('payments-keys');
    expect(searchHelp('存在しない言葉ぴよぴよ')).toEqual([]);
    expect(searchHelp('   ')).toEqual([]);
});
