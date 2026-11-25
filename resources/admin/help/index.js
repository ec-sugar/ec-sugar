// ヘルプの中身（説明のページ）。目次の順と題・一言の説明はメニューの定義（navigation.js の HELP_PAGES）、節はここの各ファイル。
// 説明のページ（HelpArticlePage）・目次の検索（HelpPage）・各画面の「説明を見る」（PageHeader の help）・よくある質問は、すべてこのデータから作る。
// 画像は読み込まない（ファイルの名前だけ。画面が import.meta.glob で resources/help/ の写しに引き当てる）ので、
// ブラウザのテスト（help-data.spec.js）が Node のままこのファイルを読み、形・経路・写しのファイルを確認できる。
//
// 節の形:
//   { id, title, screens?, body?, list?, steps?, stepsSize?, figures?, notes?, caution? }
//   id        節の印（URL の # の後ろ。英小文字・数字・ハイフン。ページの中で重ならない）
//   screens   この節を「説明を見る」の行き先にする画面（router.js の名前）。1 つの画面は、すべての節を通して 1 か所だけ
//   appScreens App の画面（ADR 0051）のうち、この節を「説明を見る」の行き先にする画面（app-page・app-record）。ページのファイルに plugin（App の名前）があるときだけ
//   body      段落の並び / list 箇条書き / steps 番号つきの手順（文か { text, notes, figure }）/ notes 補足（薄い文字）/ caution 注意（黄色の帯）
//   figures   写し（{ file, width, height, alt, caption }。file は resources/help/ の中の名前、width と height はその画素の数（読み込む前に場所を空ける））
// 文は文字列か部品の並び。部品は文字列・{ strong: '…' }（太字）・{ help: 'ページ', section: '節', text: '…' }（ヘルプの中のリンク）。HTML は書かない
import { HELP_PAGES, OPERATOR_ITEMS, SELF_ITEMS, SETTINGS_SECTIONS, TENANT_ITEMS } from '../navigation.js';
import access from './access.js';
import agents from './agents.js';
import ageCheck from './age-check.js';
import contact from './contact.js';
import coupons from './coupons.js';
import apps from './apps.js';
import blog from './blog.js';
import booking from './booking.js';
import bundle from './bundle.js';
import cartReminder from './cart-reminder.js';
import catalogSort from './catalog-sort.js';
import customers from './customers.js';
import design from './design.js';
import downloads from './downloads.js';
import faq from './faq.js';
import favorites from './favorites.js';
import giftCards from './gift-cards.js';
import gifts from './gifts.js';
import listings from './listings.js';
import memberRanks from './member-ranks.js';
import newsletter from './newsletter.js';
import orders from './orders.js';
import payments from './payments.js';
import lottery from './lottery.js';
import preorder from './preorder.js';
import salePeriod from './sale-period.js';
import productFaq from './product-faq.js';
import productFeed from './product-feed.js';
import productReviews from './product-reviews.js';
import purchaseLimits from './purchase-limits.js';
import recommendations from './recommendations.js';
import referral from './referral.js';
import restock from './restock.js';
import sales from './sales.js';
import seo from './seo.js';
import setup from './setup.js';
import shippingCsv from './shipping-csv.js';
import shopNotify from './shop-notify.js';
import line from './line.js';
import subscriptions from './subscriptions.js';
import tickets from './tickets.js';
import wrapping from './wrapping.js';

const CONTENT = { setup, payments, listings, orders, customers, coupons, sales, subscriptions, booking, gifts, 'gift-cards': giftCards, preorder, lottery, 'age-check': ageCheck, wrapping, tickets, downloads, 'sale-period': salePeriod, bundle, 'purchase-limits': purchaseLimits, 'product-faq': productFaq, 'product-feed': productFeed, 'product-reviews': productReviews, 'shipping-csv': shippingCsv, restock, 'shop-notify': shopNotify, contact, newsletter, 'cart-reminder': cartReminder, 'catalog-sort': catalogSort, line, blog, favorites, recommendations, referral, 'member-ranks': memberRanks, design, seo, apps, access, agents, faq };

// 説明のページ（目次の順）。中身の無いページは節が空（テストが落とす）。plugin は App の画面の説明のページの App の名前（無ければ null）
export const HELP_ARTICLES = HELP_PAGES.map((page) => ({ ...page, plugin: CONTENT[page.key]?.plugin ?? null, sections: CONTENT[page.key]?.sections ?? [] }));

export function articleOf(key) {
    return HELP_ARTICLES.find((article) => article.key === key) ?? null;
}

// 説明のページを出すか: App の説明のページは、その App が入っているときだけ（公開用の 1.0 には定期便・予約・チケット・抽選販売の App が無い。ADR 0098）。
// apps は入っている App の名前の並び（GET /auth/me の apps）。まだ分からない（null）ときは全部を出す
export function helpAvailable(article, apps) {
    return article !== null && (article.plugin === null || !Array.isArray(apps) || apps.includes(article.plugin));
}

// 手順の 1 つを { text, notes, figure } の形にそろえる
export function stepOf(step) {
    return typeof step === 'string' || Array.isArray(step) ? { text: step, notes: [], figure: null } : { notes: [], figure: null, ...step };
}

// 説明のページの節への経路（ヘルプの中のリンクと「説明を見る」）。shop を渡すと、ヘルプから元の店の画面を開ける
export function helpRoute(article, section = null, shop = null) {
    return {
        name: 'help-article',
        params: { article },
        ...(shop ? { query: { shop } } : {}),
        ...(section ? { hash: `#${section}` } : {}),
    };
}

// 文の部品のうち、ヘルプの中のリンクを経路に替える（RichText が描く形に）
export function partsOf(text, shop = null) {
    if (typeof text === 'string') {
        return text;
    }

    return text.map((part) => (typeof part === 'object' && part.help ? { to: helpRoute(part.help, part.section ?? null, shop), text: part.text } : part));
}

// 文の中身だけ（検索と、画像の説明の確認に）
export function plainOf(text) {
    if (typeof text === 'string') {
        return text;
    }

    return text.map((part) => (typeof part === 'string' ? part : (part.strong ?? part.text ?? ''))).join('');
}

// 節の中の文をすべて（題は含めない）
export function sectionTexts(section) {
    const steps = (section.steps ?? []).map(stepOf);

    return [
        ...(section.body ?? []),
        ...(section.list ?? []),
        ...steps.flatMap((step) => [step.text, ...step.notes, ...(step.figure ? [step.figure.caption] : [])]),
        ...(section.notes ?? []),
        ...(section.caution ? [section.caution] : []),
        ...(section.figures ?? []).map((figure) => figure.caption),
    ].map(plainOf);
}

// 画面 → その画面の「説明を見る」の行き先（節の screens から作る）
export const HELP_SCREENS = Object.fromEntries(HELP_ARTICLES.flatMap((article) => article.sections.flatMap(
    (section) => (section.screens ?? []).map((screen) => [screen, { article: article.key, section: section.id }]),
)));

/**
 * 画面の「説明を見る」の行き先。その画面の説明が無ければ null（リンクを出さない）。
 * tenant を渡すと、説明の中の「画面を開く」でその店の画面へ戻れる
 */
export function helpLink(routeName, tenant = null) {
    const target = HELP_SCREENS[routeName];

    return target ? helpRoute(target.article, target.section, tenant) : null;
}

/**
 * App の画面（ADR 0051）の「説明を見る」の行き先。その App の説明のページ（plugin が同じ）の、appScreens にその画面を持つ節。
 * 無ければ null（リンクを出さない）
 */
export function appHelpLink(plugin, routeName, tenant = null) {
    const article = HELP_ARTICLES.find((a) => a.plugin !== null && a.plugin === plugin);
    const section = article?.sections.find((s) => (s.appScreens ?? []).includes(routeName));

    return section ? helpRoute(article.key, section.id, tenant) : null;
}

// ヘルプから開ける画面（店の中で店の ID だけが要る画面と、店の外の画面）。商品や注文の 1 件の画面は開けない（どれか決まらない）
const SHOP_SCREENS = new Set([
    ...TENANT_ITEMS.flatMap((item) => [item.route, ...(item.tabs ?? []).map((tab) => tab.route)]),
    ...SETTINGS_SECTIONS.flatMap((section) => section.items.map((item) => item.route)).filter(Boolean),
    'listing-new',
    'listing-import',
]);
const OWN_SCREENS = new Set([...OPERATOR_ITEMS, ...SELF_ITEMS].map((item) => item.route));

/**
 * 節の「画面を開く」の行き先。店の画面は、どの店か分かるとき（shop）だけ
 */
export function screenRoute(routeName, shop = null) {
    if (OWN_SCREENS.has(routeName)) {
        return { name: routeName };
    }

    return SHOP_SCREENS.has(routeName) && shop ? { name: routeName, params: { tenant: shop } } : null;
}

// --- ヘルプの中の検索（手元のデータだけで探す。サーバーへは送らない） ---

// 比べるための形（全角と半角・大文字と小文字をそろえる）。1 文字ずつそろえ、そろえた後の何文字目が元の何文字目かを覚える（当たった所を元の文で示すため）
function folded(text) {
    let value = '';
    const origin = [];

    for (let index = 0; index < text.length; index++) {
        const piece = text[index].normalize('NFKC').toLowerCase();
        value += piece;

        for (let k = 0; k < piece.length; k++) {
            origin.push(index);
        }
    }

    return { value, origin };
}

// 探す言葉（空白で区切る。全角の空白も。多すぎる言葉は先の 5 つだけ）
export function searchWords(query) {
    return String(query ?? '').normalize('NFKC').toLowerCase().split(/\s+/).filter((word) => word !== '').slice(0, 5);
}

// 当たった所の前後の文（部品の並び。当たった所は hit）
function snippetOf(text, words, around = 36) {
    const { value, origin } = folded(text);
    const hits = [];

    for (const word of words) {
        let from = value.indexOf(word);

        while (from !== -1) {
            hits.push([origin[from], origin[from + word.length - 1] + 1]);
            from = value.indexOf(word, from + word.length);
        }
    }

    if (hits.length === 0) {
        return [];
    }

    hits.sort((a, b) => a[0] - b[0]);
    const start = Math.max(0, hits[0][0] - around);
    const end = Math.min(text.length, hits[0][1] + around * 2);
    const parts = start > 0 ? [{ text: '…', hit: false }] : [];
    let cursor = start;

    for (const [hitStart, hitEnd] of hits) {
        if (hitStart < cursor || hitStart >= end) {
            continue;
        }

        if (hitStart > cursor) {
            parts.push({ text: text.slice(cursor, hitStart), hit: false });
        }

        parts.push({ text: text.slice(hitStart, Math.min(hitEnd, end)), hit: true });
        cursor = Math.min(hitEnd, end);
    }

    if (cursor < end) {
        parts.push({ text: text.slice(cursor, end), hit: false });
    }

    if (end < text.length) {
        parts.push({ text: '…', hit: false });
    }

    return parts;
}

/**
 * 題と本文から言葉で探す。すべての言葉を含む節を、題に当たったものから並べる。
 * 返すのは [{ key, article, section, place, title, snippet }]（多くても limit 件）。apps を渡すと、入っていない App の説明のページは探さない（helpAvailable）
 */
export function searchHelp(query, limit = 30, apps = null) {
    const words = searchWords(query);

    if (words.length === 0) {
        return [];
    }

    const results = [];

    HELP_ARTICLES.forEach((article, articleIndex) => {
        if (!helpAvailable(article, apps)) {
            return;
        }

        article.sections.forEach((section, sectionIndex) => {
            const texts = sectionTexts(section);
            const title = folded(section.title).value;
            const all = folded([article.label, section.title, ...texts].join('\n')).value;

            if (!words.every((word) => all.includes(word))) {
                return;
            }

            // 前後の文は、言葉に当たった本文の段落から（題にしか当たらなければ最初の段落の頭）
            const body = texts.find((text) => words.some((word) => folded(text).value.includes(word))) ?? texts[0] ?? '';
            const snippet = snippetOf(body, words);

            results.push({
                key: `${article.key}-${section.id}`,
                article: article.key,
                section: section.id,
                place: article.label,
                title: section.title,
                snippet: snippet.length > 0 ? snippet : [{ text: body.slice(0, 80) + (body.length > 80 ? '…' : ''), hit: false }],
                score: words.filter((word) => title.includes(word)).length * 100 - articleIndex * 10 - sectionIndex / 100,
            });
        });
    });

    return results.sort((a, b) => b.score - a.score).slice(0, limit);
}
