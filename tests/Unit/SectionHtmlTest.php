<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Section\CoreSections;
use App\Core\Section\FieldType;
use App\Core\Section\InvalidSectionSchemaException;
use App\Core\Section\SectionHtml;
use App\Core\Section\SectionSchema;
use Dom\HTMLDocument;
use Illuminate\Support\HtmlString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 区画の HTML の整え方。残すのは決まったタグ・属性・class だけで、外したものは一覧で知らせる。
 * リンクは店の中の道筋か https、写真は店の写真の置き場だけ。大きさと入れ子に上限。壊れた HTML も落ちずに整える
 */
final class SectionHtmlTest extends TestCase
{
    private const string MEDIA = '/media/shop/01k8z3x5y7a9b1c3d5e7f9g1h3/large';

    public function test_the_allowed_tags_attributes_and_classes_stay_as_they_are(): void
    {
        $html = '<h2>見出し</h2><h3>小見出し</h3><p class="sugar-lead">段落<br />改行<strong>太字</strong><b>太</b><em>斜体</em><i>斜</i><u>下線</u><s>取り消し</s><small>小</small><mark>印</mark><sub>下</sub><sup>上</sup><code>x</code></p>'
            .'<ul><li>a</li></ul><ol start="3"><li>b</li></ol><dl><dt>語</dt><dd>説明</dd></dl>'
            .'<table class="sugar-wide"><caption>表</caption><thead><tr><th scope="col" colspan="2">見出し</th></tr></thead><tbody><tr><td rowspan="2">a</td><td>b</td></tr></tbody></table>'
            .'<blockquote><p>引用</p></blockquote><hr />'
            .'<figure><img src="'.self::MEDIA.'" alt="店の写真" width="800" height="600" /><figcaption>写真の説明</figcaption></figure>'
            .'<div class="sugar-cols-2"><div class="sugar-note">左</div><div>右</div></div><span class="sugar-small">注</span>'
            .'<p class="sugar-align-center"><a class="sugar-btn" href="/products" rel="noopener noreferrer">商品</a> <a href="https://example.com/a?b&#61;c" title="外" rel="noopener noreferrer">外</a></p>'
            .'<details><summary>開く</summary><p>中</p></details><pre>  整形済み</pre>';

        $clean = SectionHtml::clean($html);

        $this->assertNull($clean->refused);
        $this->assertSame([], $clean->removed);
        $this->assertSame($html, $clean->html);
    }

    /**
     * 消すものを 1 つずつ（中身ごと消すものは、中の文も出ない）
     *
     * @return array<string, array{string, string, string}>
     */
    public static function removals(): array
    {
        return [
            'script' => ['<p>前</p><script>alert(1)</script><p>後</p>', 'alert', '<script>'],
            'イベントの属性' => ['<p onclick="alert(1)">文</p>', 'onclick', 'onclick の属性（スクリプト）'],
            'onmouseover' => ['<div onmouseover="alert(1)">文</div>', 'onmouseover', 'onmouseover の属性（スクリプト）'],
            'iframe' => ['<iframe src="https://evil.example/"></iframe>', 'iframe', '<iframe>'],
            'object' => ['<object data="https://evil.example/x.swf"></object>', 'object', '<object>'],
            'embed' => ['<embed src="https://evil.example/x.swf">', 'embed', '<embed>'],
            'form' => ['<form action="https://evil.example/"><p>名前</p></form>', 'form', '<form>'],
            '入力の欄' => ['<input name="card" value="x">', 'input', '<input>'],
            'button' => ['<button formaction="https://evil.example/">押す</button>', 'button', '<button>'],
            'select' => ['<select><option>a</option></select>', 'select', '<select>'],
            'textarea' => ['<textarea>文</textarea>', 'textarea', '<textarea>'],
            'style の要素' => ['<style>body{display:none}</style><p>文</p>', 'display', '<style>'],
            'style の属性' => ['<p style="position:fixed;inset:0">文</p>', 'style', 'style の属性'],
            'base' => ['<base href="https://evil.example/"><p>文</p>', 'base', '<base>'],
            'meta' => ['<meta http-equiv="refresh" content="0;url=https://evil.example/"><p>文</p>', 'refresh', '<meta>'],
            'link' => ['<link rel="stylesheet" href="https://evil.example/x.css"><p>文</p>', 'stylesheet', '<link>'],
            'svg の中身' => ['<svg><a href="https://evil.example/"><text>文</text></a><script>alert(1)</script></svg>', 'svg', '<svg>'],
            'math の中身' => ['<math><mi xlink:href="javascript:alert(1)">x</mi></math>', 'math', '<math>'],
            'template' => ['<template><img src=x onerror=alert(1)></template><p>文</p>', 'template', '<template>'],
            'noscript' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>', 'onerror', '<noscript>'],
            'video' => ['<video src="https://evil.example/a.mp4"></video>', 'video', '<video>'],
            'id' => ['<p id="cart">文</p>', 'id=', 'id の属性'],
            'data の属性' => ['<p data-sugar-editor="https://evil.example">文</p>', 'data-sugar', 'data-sugar-editor の属性'],
            'srcset' => ['<img src="'.self::MEDIA.'" srcset="https://evil.example/a.png 1x">', 'srcset', 'srcset の属性'],
            'target' => ['<a href="/products" target="_blank">文</a>', 'target', 'target の属性'],
        ];
    }

    #[DataProvider('removals')]
    public function test_each_forbidden_thing_is_removed_and_reported(string $input, string $gone, string $reported): void
    {
        $clean = SectionHtml::clean($input);

        $this->assertNull($clean->refused);
        $this->assertStringNotContainsStringIgnoringCase($gone, $clean->html);
        $this->assertContains($reported, $clean->removed);
    }

    /**
     * 大文字と小文字・属性の書き方の揺れ（引用符なし・単引用符・空白・斜線で区切る）でも同じに消す
     *
     * @return array<string, array{string}>
     */
    public static function spellings(): array
    {
        return [
            '大文字' => ['<SCRIPT>alert(1)</SCRIPT>'],
            '混ぜた' => ['<ScRiPt>alert(1)</sCrIpT>'],
            '属性の大文字' => ['<P ONCLICK="alert(1)">文</P>'],
            '引用符なし' => ['<p onclick=alert(1)>文</p>'],
            '単引用符' => ["<p onclick='alert(1)'>文</p>"],
            '等号の前後の空白' => ['<p onclick = "alert(1)" >文</p>'],
            '斜線で区切る' => ['<img/src="x"/onerror=alert(1)>'],
            '改行で区切る' => ["<img\nsrc=x\nonerror=alert(1)>"],
            '属性の重なり' => ['<a href="/products" href="javascript:alert(1)" onclick="alert(1)">文</a>'],
            '閉じない' => ['<p onclick="alert(1)">文'],
        ];
    }

    #[DataProvider('spellings')]
    public function test_spelling_variations_do_not_slip_through(string $input): void
    {
        $html = SectionHtml::clean($input)->html;

        $this->assertStringNotContainsStringIgnoringCase('alert', $html);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $html);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $html);
        $this->assertStringNotContainsStringIgnoringCase('<script', $html);
    }

    /**
     * リンクは店の中の道筋か https だけ。文字の参照で隠した javascript: も、解析器が戻した値で見る
     *
     * @return array<string, array{string}>
     */
    public static function badLinks(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            '大文字' => ['JaVaScRiPt:alert(1)'],
            '10 進の参照' => ['&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)'],
            '16 進の参照' => ['&#x6A;&#x61;&#x76;&#x61;&#x73;&#x63;&#x72;&#x69;&#x70;&#x74;&#x3A;alert(1)'],
            '0 を足した参照' => ['&#0000106&#0000097&#0000118&#0000097&#0000115&#0000099&#0000114&#0000105&#0000112&#0000116&#0000058alert(1)'],
            '名前の参照のコロン' => ['javascript&colon;alert(1)'],
            'タブを挟む' => ['jav&#x09;ascript:alert(1)'],
            '改行を挟む' => ['jav&#x0A;ascript:alert(1)'],
            '前に空白' => ['  javascript:alert(1)'],
            '前に制御文字' => ['&#x01;javascript:alert(1)'],
            'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'vbscript' => ['vbscript:msgbox(1)'],
            'http' => ['http://evil.example/'],
            '別のホスト（//）' => ['//evil.example/'],
            '別のホスト（/\\）' => ['/\\evil.example/'],
            '利用者名つき' => ['https://shop.example@evil.example/'],
            'mailto' => ['mailto:a@example.com'],
        ];
    }

    #[DataProvider('badLinks')]
    public function test_links_go_only_inside_the_shop_or_to_https(string $href): void
    {
        $clean = SectionHtml::clean('<a href="'.$href.'">押す</a>');

        $this->assertSame('<a rel="noopener noreferrer">押す</a>', $clean->html);
        $this->assertCount(1, $clean->removed);
        $this->assertStringStartsWith('リンク先「', $clean->removed[0]);
    }

    public function test_good_links_stay_and_always_carry_rel(): void
    {
        $this->assertSame('<a href="/products?sort&#61;new#top" rel="noopener noreferrer">a</a>', SectionHtml::clean('<a href="/products?sort=new#top">a</a>')->html);
        $this->assertSame('<a href="https://example.com/path" rel="noopener noreferrer">a</a>', SectionHtml::clean('<a href="https://example.com/path" rel="opener">a</a>')->html);
        // 前に空白のある行き先は、読み方が分かれうるので残さない（symfony の決まり）
        $this->assertSame('<a rel="noopener noreferrer">a</a>', SectionHtml::clean('<a href=" /pages/about">a</a>')->html);
    }

    /**
     * 写真は店の写真の置き場の道筋だけ。外の URL・ほかの道筋の写真は要素ごと外す。ID は小文字にそろえる
     */
    public function test_only_photos_from_the_shop_photo_store_remain(): void
    {
        $this->assertSame('<img src="'.self::MEDIA.'" alt="写真" />', SectionHtml::clean('<img src="'.self::MEDIA.'" alt="写真">')->html);
        $this->assertSame('<img src="'.self::MEDIA.'" />', SectionHtml::clean('<img src="'.strtoupper(self::MEDIA).'">')->html);
        $this->assertSame('<img src="/media/shop/01k8z3x5y7a9b1c3d5e7f9g1h3/medium" />', SectionHtml::clean('<img src="/media/shop/01k8z3x5y7a9b1c3d5e7f9g1h3/medium">')->html);

        foreach ([
            'https://evil.example/a.png',
            '//evil.example/a.png',
            'http://localhost/media/shop/01k8z3x5y7a9b1c3d5e7f9g1h3/large',
            'data:image/png;base64,iVBORw0KGgo=',
            '/media/shop/01k8z3x5y7a9b1c3d5e7f9g1h3/original',
            '/media/shop/01k8z3x5y7a9b1c3d5e7f9g1h3/large?x=1',
            '/media/listings/01k8z3x5y7a9b1c3d5e7f9g1h3/large',
            '/media/shop/../../admin/large',
            'javascript:alert(1)',
            '',
        ] as $src) {
            $clean = SectionHtml::clean('<p>前</p><img src="'.$src.'" alt="外の写真"><p>後</p>');
            $this->assertSame('<p>前</p><p>後</p>', $clean->html, $src);
            $this->assertCount(1, $clean->removed, $src);
            $this->assertStringStartsWith('写真', $clean->removed[0], $src);
        }

        // src の無い写真も残さない
        $this->assertSame('', SectionHtml::clean('<img alt="x">')->html);
    }

    public function test_only_the_core_classes_remain(): void
    {
        $clean = SectionHtml::clean('<div class="sugar-cols-2 fixed inset-0 z-50 sugar-note sugar-cols-2">a</div><p class="sr-only hidden">b</p>');

        $this->assertSame('<div class="sugar-cols-2 sugar-note">a</div><p>b</p>', $clean->html);
        $this->assertSame(['class「fixed inset-0 z-50」', 'class「sr-only hidden」'], $clean->removed);
    }

    public function test_numbers_in_attributes_are_bounded(): void
    {
        $this->assertSame('<table><tbody><tr><td>a</td></tr></tbody></table>', SectionHtml::clean('<table><tr><td colspan="99999" rowspan="-1">a</td></tr></table>')->html);
        $this->assertSame('<img src="'.self::MEDIA.'" width="10" />', SectionHtml::clean('<img src="'.self::MEDIA.'" width="10" height="5000">')->html);
    }

    /**
     * 文はエスケープしたまま（文字の参照で書いた < は、タグにならない）
     */
    public function test_text_stays_escaped(): void
    {
        $clean = SectionHtml::clean('<p>&lt;script&gt;alert(1)&lt;/script&gt; 1 &lt; 2 &amp; "引用"</p>');

        $this->assertStringContainsString('&lt;script&gt;', $clean->html);
        $this->assertStringNotContainsString('<script', $clean->html);
        $this->assertSame([], $clean->removed);
    }

    public function test_unwrapped_tags_keep_their_text(): void
    {
        $clean = SectionHtml::clean('<h1>大見出し</h1><section><p>中</p></section><font color="red">赤</font>');

        $this->assertSame('大見出し<p>中</p>赤', $clean->html);
        $this->assertContains('<h1>（タグだけ取り除き、中の文は残します）', $clean->removed);
    }

    /**
     * 壊れた HTML も落ちずに、ブラウザと同じ読み方で整える
     *
     * @return array<string, array{string, string}>
     */
    public static function broken(): array
    {
        return [
            '閉じない' => ['<p><b>太字', '<p><b>太字</b></p>'],
            '余った閉じタグ' => ['</div><p>文</p></span>', '<p>文</p>'],
            '入れ違い' => ['<b><i>a</b>b</i>', '<b><i>a</i></b><i>b</i>'],
            '属性の途中で終わる' => ['<p class="sugar-note>文', ''],
            'タグの途中に <' => ['<p <script>alert(1)</script>文</p>', '<p>alert(1)文</p>'],
            'コメント' => ['<!-- <script>alert(1)</script> --><p>文</p>', '<p>文</p>'],
            // HTML の本文では CDATA は最初の > までの注釈になり、残りはただの文（タグにならない）
            'CDATA' => ['<![CDATA[<script>alert(1)</script>]]><p>文</p>', 'alert(1)]]&gt;<p>文</p>'],
            '表の外の文' => ['<table><tr><td>a</td></tr>外</table>', '外<table><tbody><tr><td>a</td></tr></tbody></table>'],
        ];
    }

    #[DataProvider('broken')]
    public function test_broken_html_is_read_the_way_browsers_read_it(string $input, string $expected): void
    {
        $clean = SectionHtml::clean($input);

        $this->assertNull($clean->refused);
        $this->assertSame($expected, $clean->html);
    }

    public function test_size_and_nesting_have_limits(): void
    {
        // 2 万字まで
        $this->assertNull(SectionHtml::clean('<p>'.str_repeat('あ', SectionHtml::MAX_CHARS - 7).'</p>')->refused);
        $this->assertSame('HTML は 20000 字までです（今は 20001 字）。', SectionHtml::clean('<p>'.str_repeat('あ', SectionHtml::MAX_CHARS - 6).'</p>')->refused);
        // 解析の前の歯止め: 打った字数がしまう形の上限（8 万字）を越えたら、読まずに断る
        $this->assertStringContainsString('80000 字まで', (string) SectionHtml::clean(str_repeat('a', SectionHtml::MAX_STORED_CHARS + 1))->refused);

        // 入れ子は 20 段まで（越えたら何も残さない）
        $this->assertNull(SectionHtml::clean(str_repeat('<div>', 20).'文'.str_repeat('</div>', 20))->refused);
        $deep = SectionHtml::clean(str_repeat('<div>', 21).'文'.str_repeat('</div>', 21));
        $this->assertNotNull($deep->refused);
        $this->assertSame('', $deep->html);
        // 閉じないままの深い入れ子（タグだけ外すものも数える）
        $this->assertNotNull(SectionHtml::clean(str_repeat('<span>', 5000))->refused);
        $this->assertNotNull(SectionHtml::clean(str_repeat('<section>', 30).'文')->refused);
    }

    public function test_broken_text_and_control_characters_are_refused(): void
    {
        $this->assertNotNull(SectionHtml::clean("<p>\xC3\x28</p>")->refused);
        $this->assertNotNull(SectionHtml::clean("<p>a\0b</p>")->refused);
        $this->assertNotNull(SectionHtml::clean("<p>a\x1Bb</p>")->refused);
        // 改行とタブは通す
        $this->assertSame("<p>a\n\tb</p>", SectionHtml::clean("<p>a\r\n\tb</p>")->html);
        $this->assertSame('', SectionHtml::clean("  \n ")->html);
    }

    /**
     * 整えた結果をもう 1 度整えても変わらない（保存した値を描くときに通しても形が崩れない・知らせも出ない）
     */
    public function test_cleaning_twice_changes_nothing(): void
    {
        $once = SectionHtml::clean('<p onclick="x">1 + 1 = 2 ＜全角＞ a@b `x`</p><a href="https://example.com/?a=b&c=d">a</a><img src="'.self::MEDIA.'" alt="&quot;引用&quot;">');
        $twice = SectionHtml::clean($once->html);

        $this->assertSame($once->html, $twice->html);
        $this->assertSame([], $twice->removed);
    }

    /**
     * 整えると記号が文字の参照（" → &#34;、＜ → &#xFF1C;）になって 5〜8 倍に長くなる。字数が 2 万字の中でも、
     * しまう形（整えた後）が 8 万字を越えるものは保存で断る（描くときに黙って消えないように）
     *
     * @return array<string, array{string}>
     */
    public static function growing(): array
    {
        return [
            '" の連続' => [str_repeat('"', SectionHtml::MAX_CHARS)],
            '全角の記号の連続' => [str_repeat('＜', SectionHtml::MAX_CHARS)],
            '日本語の長い文に記号が混ざる' => ['<p>'.str_repeat('値＋段＝', 4900).'</p>'],
            '+ と = と @ と ` の連続' => ['<p>'.str_repeat('+=@`', 4990).'</p>'],
        ];
    }

    #[DataProvider('growing')]
    public function test_html_that_grows_past_the_stored_limit_is_refused(string $input): void
    {
        $this->assertLessThanOrEqual(SectionHtml::MAX_CHARS, mb_strlen($input));

        $clean = SectionHtml::clean($input);

        $this->assertSame('', $clean->html);
        $this->assertStringStartsWith('ショップに表示する形に変換すると 80000 字を超えます（今は ', (string) $clean->refused);
        $this->assertStringContainsString('&#34;', (string) $clean->refused);
        $this->assertNull(SectionHtml::render($input));
    }

    /**
     * しまう形の上限ちょうどは通り、1 字越えると断る（字数はどちらも 2 万字の中）。保存した形はそのまま保存し直せる
     */
    public function test_the_stored_limit_is_exact_and_the_stored_form_can_be_saved_again(): void
    {
        // <p> と </p> で 7 字、" は 1 つ 5 字（&#34;）
        $exact = '<p>'.str_repeat('"', 15000).str_repeat('a', 4993).'</p>';
        $this->assertSame(SectionHtml::MAX_CHARS, mb_strlen($exact));
        $clean = SectionHtml::clean($exact);
        $this->assertNull($clean->refused);
        $this->assertSame(SectionHtml::MAX_STORED_CHARS, mb_strlen($clean->html));

        $over = SectionHtml::clean('<p>'.str_repeat('"', 15001).str_repeat('a', 4989).'</p>');
        $this->assertStringStartsWith('ショップに表示する形に変換すると 80000 字を超えます（今は 80001 字）。', (string) $over->refused);

        // 保存した形（8 万字。打った形の 4 倍）を、そのままもう 1 度保存しても断らない（管理画面は保存した形のまま送り返す）
        $again = SectionHtml::clean($clean->html);
        $this->assertNull($again->refused);
        $this->assertSame($clean->html, $again->html);

        // 字数はショップに出す形で数える（文字の参照は 1 字。核が足す rel と、<br> を <br /> と書く分も数える）
        $this->assertNull(SectionHtml::clean(str_repeat('&#34;', 16000))->refused);
        $this->assertSame('HTML は 20000 字までです（今は 20002 字）。', SectionHtml::clean(str_repeat('a', SectionHtml::MAX_CHARS - 4).'<br>')->refused);
    }

    /**
     * 保存で通ったものは、描くときも必ず描ける（描くときと保存で同じ決まり。黙って消える道を作らない）
     *
     * @return array<string, array{string}>
     */
    public static function storable(): array
    {
        return [
            '記号が多いが上限の中' => ['<p>'.str_repeat('"', 15000).str_repeat('a', 4993).'</p>'],
            '全角の記号が多い' => ['<p>'.str_repeat('＜あ', 4000).'</p>'],
            '日本語と記号' => ['<h2>値段</h2><p>'.str_repeat('1＋1＝2 です。メールは a@b へ。', 600).'</p>'],
            'リンクが多い（rel が足される）' => [str_repeat('<a href="/products">商品</a>', 300)],
            '写真と表' => [str_repeat('<figure><img src="'.self::MEDIA.'" alt="&quot;写真&quot;"><figcaption>説明</figcaption></figure><table><tr><td colspan=2>a</td></tr></table>', 80)],
            'pre の先頭の改行' => ["<pre>\n\n<b>x</b></pre><pre>\n\n\n文</pre>"],
            '外すものが多い' => [str_repeat('<script>x</script><p onclick="x">文</p>', 400)],
        ];
    }

    #[DataProvider('storable')]
    public function test_what_can_be_stored_can_always_be_drawn(string $input): void
    {
        $clean = SectionHtml::clean($input);
        $this->assertNull($clean->refused);
        $this->assertNotSame('', $clean->html);

        $rendered = SectionHtml::render($clean->html);
        $this->assertNotNull($rendered);
        $this->assertSame($clean->html, $rendered->toHtml());
    }

    /**
     * pre の先頭の改行: HTML の決まりで、<pre> のすぐ後の改行 1 つは読むときに捨てられる。整えるたびに 1 つずつ減らないよう、
     * 整えた形は読み直しても同じ中身になる（2 回・3 回整えても同じ形）
     *
     * @return array<string, array{string, string}>
     */
    public static function preformatted(): array
    {
        return [
            '改行 2 つ' => ["<pre>\n\n<b>x</b></pre>", "<pre>\n\n<b>x</b></pre>"],
            '改行 3 つと class' => ["<pre class=\"sugar-small\">\n\n\nx</pre>", "<pre class=\"sugar-small\">\n\n\nx</pre>"],
            '改行 1 つは捨てる（ブラウザと同じ）' => ["<pre>\nx</pre>", '<pre>x</pre>'],
            '改行なし' => ['<pre>x</pre>', '<pre>x</pre>'],
            'タグだけ外す要素の中' => ["<pre><section>\n\nx</section></pre>", "<pre>\n\n\nx</pre>"],
            '中身ごと消す要素の後' => ["<pre><script>1</script>\n\ny</pre>", "<pre>\n\n\ny</pre>"],
            '外の写真の後' => ["<pre><img src=\"https://evil.example/a.png\">\n\nz</pre>", "<pre>\n\n\nz</pre>"],
            '並んだ pre' => ["<div><pre>\n\na</pre><pre>\n\nb</pre></div>", "<div><pre>\n\na</pre><pre>\n\nb</pre></div>"],
            '文の中の <pre> の字' => ["<p>&lt;pre&gt;\n\nx</p>", "<p>&lt;pre&gt;\n\nx</p>"],
        ];
    }

    #[DataProvider('preformatted')]
    public function test_cleaning_pre_is_stable(string $input, string $expected): void
    {
        $once = SectionHtml::clean($input);
        $this->assertSame($expected, $once->html);

        $twice = SectionHtml::clean($once->html);
        $this->assertSame($once->html, $twice->html);
        $this->assertSame([], $twice->removed);
        $this->assertSame($once->html, SectionHtml::clean($twice->html)->html);

        // 外したものが無ければ、ショップで読んだ中身（pre の中の改行の数）は打った中身と同じ
        if ($once->removed === []) {
            $read = static fn (string $html): string => (string) HTMLDocument::createFromString('<!DOCTYPE html><body>'.$html.'</body>', LIBXML_NOERROR, 'UTF-8')->body?->textContent;
            $this->assertSame($read($input), $read($once->html));
        }
    }

    /**
     * 整えた HTML の中の店の写真の ID（保存のときにあるかを確かめるため。重ならない・出てきた順・小文字）
     */
    public function test_the_photo_ids_inside_the_html(): void
    {
        $other = '01k8z3x5y7a9b1c3d5e7f9g1h4';
        $html = SectionHtml::clean('<p>文<img src="'.self::MEDIA.'"></p><img src="/MEDIA/SHOP/'.strtoupper($other).'/medium"><img src="'.self::MEDIA.'"><p>/media/shop/01k8z3x5y7a9b1c3d5e7f9g1h5/large</p>')->html;

        $this->assertSame(['01k8z3x5y7a9b1c3d5e7f9g1h3', $other], SectionHtml::mediaIds($html));
        $this->assertSame([], SectionHtml::mediaIds(''));
        $this->assertSame([], SectionHtml::mediaIds('<p>写真なし</p>'));
    }

    public function test_render_gives_html_to_print_or_nothing(): void
    {
        $rendered = SectionHtml::render('<p>文</p><script>alert(1)</script>');
        $this->assertInstanceOf(HtmlString::class, $rendered);
        $this->assertSame('<p>文</p>', $rendered->toHtml());

        $this->assertNull(SectionHtml::render(null));
        $this->assertNull(SectionHtml::render(['<p>文</p>']));
        $this->assertNull(SectionHtml::render('<script>alert(1)</script>'));
        $this->assertNull(SectionHtml::render(str_repeat('<div>', 21)));
        // 保存した値は文字の参照で長くなるが、字数は参照を 1 字と数えるので、保存と同じ上限で描ける
        $this->assertNotNull(SectionHtml::render('<p>'.str_repeat('&#43;', 5000).'</p>'));
        // 保存で断る大きさは、描くときも何も出さない（同じ決まり）
        $this->assertNull(SectionHtml::render('<p>'.str_repeat('a', SectionHtml::MAX_CHARS).'</p>'));
        $this->assertNull(SectionHtml::render(str_repeat('&#34;', 16001)));
    }

    public function test_the_tree_for_the_editor_holds_only_the_cleaned_elements(): void
    {
        $this->assertSame([
            ['t' => 'p', 'a' => ['class' => 'sugar-note'], 'c' => ['文', ['t' => 'strong', 'a' => [], 'c' => ['太']]]],
            ['t' => 'img', 'a' => ['src' => self::MEDIA, 'alt' => 'x'], 'c' => []],
        ], SectionHtml::tree(SectionHtml::clean('<p class="sugar-note" onclick="x">文<strong>太</strong></p><img src="'.self::MEDIA.'" alt="x">')->html));
        $this->assertSame([], SectionHtml::tree(''));
    }

    /**
     * 核の区画「自由な HTML」「見たまま書ける文」: 欄は html・richtext で 2 万字まで。見本は整えても変わらない形
     */
    public function test_the_two_core_sections_and_their_examples(): void
    {
        foreach (['basic_html' => ['html', FieldType::Html], 'basic_richtext' => ['body', FieldType::Richtext]] as $type => [$field, $kind]) {
            $spec = CoreSections::section($type);
            $this->assertNotNull($spec, $type);
            $this->assertSame(['narrow', 'wide'], array_keys($spec->patterns));
            $this->assertSame($kind, $spec->fields[$field]->type);
            $this->assertSame(SectionHtml::MAX_CHARS, $spec->fields[$field]->max);
            $this->assertSame(SectionHtml::CLASSES, $spec->fields[$field]->toArray()['classes']);

            $example = $spec->example['values'][$field];
            $clean = SectionHtml::clean($example);
            $this->assertSame($example, $clean->html, $type);
            $this->assertSame([], $clean->removed, $type);
        }
    }

    /**
     * テーマは HTML の欄を宣言できない（核の区画だけ。繰り返しの組・ブロックの中も）
     */
    public function test_a_theme_cannot_declare_an_html_field(): void
    {
        foreach ([
            ['hero' => ['label' => 'ヒーロー', 'fields' => ['body' => ['type' => 'html', 'label' => 'HTML']]]],
            ['hero' => ['label' => 'ヒーロー', 'fields' => ['body' => ['type' => 'text', 'label' => '文']], 'items' => ['label' => '組', 'max' => 3, 'fields' => ['x' => ['type' => 'richtext', 'label' => '文']]]]],
            ['hero' => ['label' => 'ヒーロー', 'fields' => [], 'blocks' => ['b' => ['label' => 'B', 'fields' => ['x' => ['type' => 'html', 'label' => 'HTML']]]]]],
        ] as $sections) {
            try {
                SectionSchema::fromManifest($sections);
                $this->fail('テーマの HTML の欄を断る');
            } catch (InvalidSectionSchemaException $e) {
                $this->assertStringContainsString('本体のセクションだけ', $e->getMessage());
            }
        }

        // 核の区画の宣言（fromArray）は使える
        $this->assertSame(FieldType::Html, SectionSchema::fromArray(['x' => ['label' => 'X', 'fields' => ['h' => ['type' => 'html', 'label' => 'H']]]])->section('x')?->fields['h']->type);
    }

    /**
     * 見本の HTML に店の写真を書けない（ほかの欄と同じく、店のデータを見本に入れない）
     */
    public function test_an_example_cannot_carry_a_shop_photo(): void
    {
        $this->expectException(InvalidSectionSchemaException::class);

        SectionSchema::fromArray(['x' => ['label' => 'X', 'fields' => ['h' => ['type' => 'html', 'label' => 'H']], 'example' => ['h' => '<img src="'.self::MEDIA.'">']]]);
    }
}
