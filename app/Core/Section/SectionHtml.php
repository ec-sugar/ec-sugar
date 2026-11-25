<?php

declare(strict_types=1);

namespace App\Core\Section;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Illuminate\Support\HtmlString;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * 区画の HTML の整え方（核の区画「自由な HTML」と「見たまま書ける文」の欄 html・richtext）。
 * 店のスタッフが書いた HTML がお客さんの画面に出る境界なので、**保存するときと描くときの両方**でこの決まりを通す
 * （決まりを後で狭めても、保存済みの値から危ないものが出ない）。
 *
 * - 整えるのは symfony/html-sanitizer（PHP の HTML5 の解析器 Dom\HTMLDocument で読み、許した要素と属性だけを書き出す）。
 *   自分で正規表現で HTML を整えない。核が足すのは、許す要素・属性・class の表と、属性の値の確かめ（SectionHtmlAttributes）と、
 *   解析した木の下見（SectionHtmlParser: 入れ子の深さ・店の写真でない写真を要素ごと外す・外したものの一覧）だけ
 * - 残すのは下の ELEMENTS（見出し・段落・改行・太字・斜体・リスト・表・引用・区切り線・写真・リンク・div/span など）。
 *   UNWRAP はタグだけ外して中の文を残す。それ以外（script・style・iframe・object・embed・form と入力の欄・base・meta・link・
 *   svg・math・template など）は中身ごと消す
 * - 属性は表にあるものだけ。on… の属性・style・id は残さない。class は CLASSES の決まった名前だけ（見た目は核の CSS が付ける）
 * - リンクは店の中の道筋か https だけ（SectionValues::safeLink と同じ決まり。javascript:・data: などは属性ごと消す）。
 *   リンクにはいつも rel="noopener noreferrer" を付ける
 * - 写真は店の写真の置き場（/media/shop/{ID}/large|medium）だけ。外の URL の写真は要素ごと消す
 * - 大きさは 2 万字まで（ショップに出す形の字数。文字の参照は 1 字）で、しまう形（整えた後の HTML）は 8 万字まで。入れ子は 20 段まで。
 *   越えたら保存で断る。保存・確かめ・描くときは同じ決まりなので、保存できたものは描ける（描くときに何も出さないのは、DB を直に書き換えたときだけ）
 * - 整え直しても形が変わらない（冪等）。pre の先頭の改行も減らさない
 *
 * ショップの CSP（script-src 'self'・style-src 'self'・Trusted Types）は緩めない。整えた後の HTML も CSP の下で描く（二重の守り）
 */
final class SectionHtml
{
    /** HTML の大きさの上限（字数。整えた後の形で、文字の参照〔&#34; など〕を 1 字と数える。欄の上限） */
    public const int MAX_CHARS = 20_000;

    /**
     * しまう形（整えた後の HTML）の字数の上限。整えると「"」「+」などが文字の参照（&#34;・&#43;）になって長くなるので、字数の上限より広い。
     * 保存・確かめの API・描くときのどれも、この数で断る（保存で通ったものが描くときに消えない）。解析の前の歯止めも同じ数
     */
    public const int MAX_STORED_CHARS = 4 * self::MAX_CHARS;

    /** 入れ子の深さの上限（いちばん外の要素が 1 段目） */
    public const int MAX_DEPTH = 20;

    /** 外したものの一覧の数の上限（管理画面に知らせる分） */
    public const int MAX_REPORTS = 30;

    /** 写真の道筋: 店の写真の置き場だけ（ショップの経路 storefront.media.shop と同じ形。ID は小文字の ULID） */
    public const string MEDIA_SRC = '#\A/media/shop/([0-9a-hjkmnp-tv-z]{26})/(large|medium)\z#';

    /**
     * 残す class（核の CSS が見た目を付ける。名前 => 何に使うか。docs/design/theme-api.md の一覧と同じ）。
     * 名前を消す・変えると保存済みの HTML の見た目が変わるので、消さずに残す
     */
    public const array CLASSES = [
        'sugar-cols-2' => '中を 2 列に並べる（狭い画面では 1 列）',
        'sugar-cols-3' => '中を 3 列に並べる（狭い画面では 1 列）',
        'sugar-align-center' => '中央に寄せる',
        'sugar-align-right' => '右に寄せる',
        'sugar-btn' => 'リンクをボタンの形にする',
        'sugar-btn-outline' => 'リンクを枠だけのボタンの形にする',
        'sugar-note' => '注記の枠',
        'sugar-lead' => '大きめの文',
        'sugar-small' => '小さめの文',
        'sugar-space-s' => '上下の余白を小さく',
        'sugar-space-l' => '上下の余白を大きく',
        'sugar-wide' => '写真や表を幅いっぱいに',
    ];

    /**
     * 残す要素 => 残す属性（class はどの要素にも残せる。値は SectionHtmlAttributes が確かめる）
     */
    public const array ELEMENTS = [
        'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'p' => [], 'br' => [], 'hr' => [], 'div' => [], 'span' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'del' => [], 'small' => [], 'mark' => [],
        'sub' => [], 'sup' => [], 'code' => [], 'pre' => [], 'q' => [], 'cite' => [], 'abbr' => ['title'],
        'blockquote' => [],
        'ul' => [], 'ol' => ['start'], 'li' => [], 'dl' => [], 'dt' => [], 'dd' => [],
        'table' => [], 'caption' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
        'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
        'figure' => [], 'figcaption' => [], 'details' => [], 'summary' => [],
        'a' => ['href', 'title', 'rel'],
        'img' => ['src', 'alt', 'width', 'height'],
    ];

    /**
     * タグだけ外して中の文を残す要素（害は無いが見た目を核が持たないもの。h1 はページの題に使うので、見出しは h2 から）
     */
    public const array UNWRAP = [
        'h1', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'address', 'hgroup', 'search',
        'center', 'font', 'big', 'tt', 'strike', 'nobr', 'acronym', 'time', 'ins', 'bdi', 'bdo', 'data', 'var', 'kbd', 'samp', 'dfn',
        'label', 'picture', 'menu',
    ];

    private static ?HtmlSanitizerConfig $config = null;

    /**
     * 整える（保存のとき・管理画面の確かめ・描くとき。どれも同じ決まり）。大きすぎる・壊れた文字・使えない制御文字・入れ子が深すぎるときは refused に理由。
     *
     * 大きさは 3 つで見る（保存できたものは、そのまま保存し直せて、描くときも必ず描ける。整え直しても形が変わらないので、どれも同じ数になる）:
     * 1. 解析の前の歯止め: 渡された字数が MAX_STORED_CHARS まで（しまってある形も、この中に収まる）
     * 2. 字数: 整えた後の形で、文字の参照（&#34; など）を 1 字と数えて $limit（2 万字）まで。打った形でなく整えた後の形で数えるので、
     *    しまった形（記号が文字の参照になって長い）を管理画面がそのまま送り返しても、数は変わらない
     * 3. しまう形の長さ: 整えた後の HTML の字数が MAX_STORED_CHARS まで。記号は文字の参照で 5〜8 倍になるので、2 の中でも越えることがある
     */
    public static function clean(string $html, int $limit = self::MAX_CHARS): CleanedHtml
    {
        if (! mb_check_encoding($html, 'UTF-8')) {
            return CleanedHtml::refused('壊れた文字が入っています。');
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", $html));

        // 制御文字（C0・DEL・C1）は改行とタブのほかは断る（NUL など。解析器と見た目で読み方が分かれるもの）
        if (preg_match('/[^\P{Cc}\n\t]/u', $text) !== 0) {
            return CleanedHtml::refused('使えない文字（制御文字）が入っています。');
        }

        if ($text === '') {
            return new CleanedHtml('');
        }

        $typed = mb_strlen($text);
        if ($typed > self::MAX_STORED_CHARS) {
            return CleanedHtml::refused('HTML が長すぎます（'.self::MAX_STORED_CHARS." 字まで。今は {$typed} 字）。");
        }

        $parser = new SectionHtmlParser;
        $out = self::keepPreNewlines(trim((new HtmlSanitizer(self::config(), $parser))->sanitize($text)));

        if ($parser->tooDeep) {
            return CleanedHtml::refused('タグの入れ子が深すぎます（'.self::MAX_DEPTH.' 階層まで）。');
        }

        $chars = mb_strlen(html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($chars > $limit) {
            return CleanedHtml::refused("HTML は {$limit} 字までです（今は {$chars} 字）。");
        }

        $stored = mb_strlen($out);
        if ($stored > self::MAX_STORED_CHARS) {
            return CleanedHtml::refused('ショップに表示する形に変換すると '.self::MAX_STORED_CHARS." 字を超えます（今は {$stored} 字）。"
                .'「"」「+」「=」「@」「`」や全角の「＜」「＞」「＋」「＝」「＠」「｀」は、ショップに表示する形では &#34; のような 5〜8 字になります。'
                .'これらの記号を減らすか、セクションを 2 つに分けてください。');
        }

        return new CleanedHtml($out, $parser->removed());
    }

    /**
     * 描くとき: 保存してある値を同じ決まりで整え直して、そのまま出してよい HTML にする。空・断ったものは null（区画は何も出さない）。
     * 決まりは保存と同じ（clean の既定の上限）なので、保存で通った値はここでも通る（黙って消えない）。null になるのは、
     * DB を直に書き換えた・決まりを後で狭めたときだけ。
     * テーマは $section['html'] を {{ }} で出す（HtmlString なのでエスケープされず、ほかの文字列は今までどおりエスケープされる）
     */
    public static function render(mixed $stored): ?HtmlString
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        $clean = self::clean($stored);

        return $clean->refused !== null || $clean->html === '' ? null : new HtmlString($clean->html);
    }

    /**
     * 整えた HTML の中の店の写真の ID（出てきた順・重ならない・小文字）。保存のときにこの店の置き場にあるかを確かめるため。
     * 渡すのは clean の結果だけ（写真は店の写真の置き場の道筋のものだけが残っている）
     *
     * @return list<string>
     */
    public static function mediaIds(string $cleaned): array
    {
        if (! str_contains($cleaned, '<img')) {
            return [];
        }

        $document = HTMLDocument::createFromString('<!DOCTYPE html><body>'.$cleaned.'</body>', LIBXML_NOERROR, 'UTF-8');
        $ids = [];

        foreach ($document->querySelectorAll('img[src]') as $image) {
            $src = SectionHtmlAttributes::mediaSrc((string) $image->getAttribute('src'));

            if ($src !== null && preg_match(self::MEDIA_SRC, $src, $match) === 1) {
                $ids[$match[1]] = $match[1];
            }
        }

        return array_values($ids);
    }

    /**
     * <pre> のすぐ後の改行: HTML の決まりで、<pre> の開きタグのすぐ後の改行 1 つは読むときに捨てられる。中身が改行で始まる pre を
     * そのまま書き出すと、読み直すたびに 1 つずつ減る（保存と描くときの 2 回で形が変わる）。書き出した形で <pre …> のすぐ後が改行なら、
     * 捨てられる分の改行を 1 つ足す（ブラウザが HTML を書き出すときと同じ）。これで整えても形が変わらない（冪等＝何度整えても同じ）。
     * 整えた後の形の中だけを見る: 文の中の < は &lt; に、属性の値の中の > は &gt; になっているので、「<pre」と次の「>」は本物のタグだけ
     */
    private static function keepPreNewlines(string $html): string
    {
        if (! str_contains($html, '<pre')) {
            return $html;
        }

        return (string) preg_replace('/(<pre(?:\s[^>]*)?>)\n/', "\$1\n\n", $html);
    }

    /**
     * 整えた HTML を、管理画面の「見たまま書ける文」が DOM を組むための木にする（管理画面は HTML の文字を DOM に書き込まない。
     * Trusted Types の下で、要素を 1 つずつ作る）。文字は文字列、要素は { t: 要素の名前, a: { 属性: 値 }, c: [ 子 ] }。
     * 渡すのは clean の結果だけ（残す要素と属性だけが入っている）
     *
     * @return list<string|array{t: string, a: array<string, string>, c: list<mixed>}>
     */
    public static function tree(string $cleaned): array
    {
        if ($cleaned === '') {
            return [];
        }

        $document = HTMLDocument::createFromString('<!DOCTYPE html><body>'.$cleaned.'</body>', LIBXML_NOERROR, 'UTF-8');

        return $document->body === null ? [] : self::children($document->body, 0);
    }

    /**
     * @return list<string|array{t: string, a: array<string, string>, c: list<mixed>}>
     */
    private static function children(Node $node, int $depth): array
    {
        $out = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof Element && $depth < self::MAX_DEPTH) {
                $name = strtolower($child->nodeName);

                if (! array_key_exists($name, self::ELEMENTS)) {
                    continue;
                }

                $attributes = [];
                foreach ($child->attributes as $attribute) {
                    $attributes[$attribute->name] = $attribute->value;
                }

                $out[] = ['t' => $name, 'a' => $attributes, 'c' => self::children($child, $depth + 1)];
            } elseif ($child->nodeType === XML_TEXT_NODE) {
                $out[] = (string) $child->textContent;
            }
        }

        return $out;
    }

    /**
     * 要素の扱い: allow（残す）・unwrap（タグだけ外す）・drop（中身ごと消す）
     *
     * @return 'allow'|'unwrap'|'drop'
     */
    public static function action(string $element): string
    {
        return match (true) {
            array_key_exists($element, self::ELEMENTS) => 'allow',
            in_array($element, self::UNWRAP, true) => 'unwrap',
            default => 'drop',
        };
    }

    /**
     * symfony/html-sanitizer の設定。既定は「表に無い要素は中身ごと消す」（symfony の既定の Drop）
     */
    public static function config(): HtmlSanitizerConfig
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $config = (new HtmlSanitizerConfig)
            // リンクは https と店の中の道筋。写真は店の中の道筋だけ（外の写真は SectionHtmlParser が要素ごと外す。ここは二重の守り）
            ->allowLinkSchemes(['https'])
            ->allowRelativeLinks()
            ->allowMediaSchemes([])
            ->allowRelativeMedias()
            ->withAttributeSanitizer(new SectionHtmlAttributes)
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            // 字数は clean が先に確かめる。ここはバイトでの最後の歯止め（日本語は 1 字 3 バイト）
            ->withMaxInputLength(4 * self::MAX_STORED_CHARS);

        foreach (self::ELEMENTS as $element => $attributes) {
            $config = $config->allowElement($element, [...$attributes, 'class']);
        }

        foreach (self::UNWRAP as $element) {
            $config = $config->blockElement($element);
        }

        return self::$config = $config;
    }
}
