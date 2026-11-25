<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Section\SectionHtml;
use App\Core\Section\SectionValues;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 区画の HTML の整え方に、よく知られた XSS の書き方をまとめて流す。
 * 書き方は OWASP の「XSS Filter Evasion Cheat Sheet」の代表的なものと、解析の読み違いを突く書き方（mXSS）。
 * 結果をブラウザと同じ解析器で読み直し、残っているのが決まった要素・属性だけで、スクリプトに届く値が無いことを形で確かめる
 */
final class SectionHtmlXssTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function vectors(): array
    {
        $list = [
            '<SCRIPT SRC=https://xss.rocks/xss.js></SCRIPT>',
            'javascript:/*--></title></style></textarea></script></xmp><svg/onload=\'+/"/+/onmouseover=1/+/[*/[]/+alert(1)//\'>',
            '<IMG SRC="javascript:alert(\'XSS\');">',
            '<IMG SRC=javascript:alert(\'XSS\')>',
            '<IMG SRC=JaVaScRiPt:alert(\'XSS\')>',
            '<IMG SRC=`javascript:alert("RSnake says, \'XSS\'")`>',
            '<a onmouseover="alert(document.cookie)">xxs link</a>',
            '<a onmouseover=alert(document.cookie)>xxs link</a>',
            '<IMG """><SCRIPT>alert("XSS")</SCRIPT>"\\>',
            '<IMG SRC=javascript:alert(String.fromCharCode(88,83,83))>',
            '<IMG SRC=# onmouseover="alert(\'xxs\')">',
            '<IMG SRC= onmouseover="alert(\'xxs\')">',
            '<IMG onmouseover="alert(\'xxs\')">',
            '<IMG SRC=/ onerror="alert(String.fromCharCode(88,83,83))"></img>',
            '<img src=x onerror="&#0000106&#0000097&#0000118&#0000097&#0000115&#0000099&#0000114&#0000105&#0000112&#0000116&#0000058&#0000097&#0000108&#0000101&#0000114&#0000116&#0000040&#0000039&#0000088&#0000083&#0000083&#0000039&#0000041">',
            '<IMG SRC=&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;&#97;&#108;&#101;&#114;&#116;&#40;&#39;&#88;&#83;&#83;&#39;&#41;>',
            '<IMG SRC=&#x6A&#x61&#x76&#x61&#x73&#x63&#x72&#x69&#x70&#x74&#x3A&#x61&#x6C&#x65&#x72&#x74&#x28&#x27&#x58&#x53&#x53&#x27&#x29>',
            "<IMG SRC=\"jav\tascript:alert('XSS');\">",
            '<IMG SRC="jav&#x09;ascript:alert(\'XSS\');">',
            '<IMG SRC="jav&#x0A;ascript:alert(\'XSS\');">',
            '<IMG SRC="jav&#x0D;ascript:alert(\'XSS\');">',
            '<IMG SRC=" &#14;  javascript:alert(\'XSS\');">',
            '<SCRIPT/XSS SRC="https://xss.rocks/xss.js"></SCRIPT>',
            '<BODY onload!#$%&()*~+-_.,:;?@[/|\\]^`=alert("XSS")>',
            '<<SCRIPT>alert("XSS");//\\<</SCRIPT>',
            '<SCRIPT SRC=https://xss.rocks/xss.js?< B >',
            '<SCRIPT SRC=//xss.rocks/.j>',
            '<IMG SRC="`<javascript:alert>`(\'XSS\')"',
            '<iframe src=https://xss.rocks/scriptlet.html <',
            '</TITLE><SCRIPT>alert("XSS");</SCRIPT>',
            '<INPUT TYPE="IMAGE" SRC="javascript:alert(\'XSS\');">',
            '<BODY BACKGROUND="javascript:alert(\'XSS\')">',
            '<IMG DYNSRC="javascript:alert(\'XSS\')">',
            '<IMG LOWSRC="javascript:alert(\'XSS\')">',
            '<STYLE>li {list-style-image: url("javascript:alert(\'XSS\')");}</STYLE><UL><LI>XSS</br>',
            '<svg/onload=alert(\'XSS\')>',
            '<BODY ONLOAD=alert(\'XSS\')>',
            '<BGSOUND SRC="javascript:alert(\'XSS\');">',
            '<BR SIZE="&{alert(\'XSS\')}">',
            '<LINK REL="stylesheet" HREF="javascript:alert(\'XSS\');">',
            '<STYLE>@import\'https://xss.rocks/xss.css\';</STYLE>',
            '<META HTTP-EQUIV="Link" Content="<https://xss.rocks/xss.css>; REL=stylesheet">',
            '<STYLE>BODY{-moz-binding:url("https://xss.rocks/xssmoz.xml#xss")}</STYLE>',
            '<IMG STYLE="xss:expr/*XSS*/ession(alert(\'XSS\'))">',
            '<META HTTP-EQUIV="refresh" CONTENT="0;url=javascript:alert(\'XSS\');">',
            '<META HTTP-EQUIV="refresh" CONTENT="0;url=data:text/html base64,PHNjcmlwdD5hbGVydCgnWFNTJyk8L3NjcmlwdD4K">',
            '<IFRAME SRC="javascript:alert(\'XSS\');"></IFRAME>',
            '<FRAMESET><FRAME SRC="javascript:alert(\'XSS\');"></FRAMESET>',
            '<TABLE BACKGROUND="javascript:alert(\'XSS\')">',
            '<TABLE><TD BACKGROUND="javascript:alert(\'XSS\')">',
            '<DIV STYLE="background-image: url(javascript:alert(\'XSS\'))">',
            '<DIV STYLE="width: expression(alert(\'XSS\'));">',
            '<BASE HREF="javascript:alert(\'XSS\');//">',
            '<OBJECT TYPE="text/x-scriptlet" DATA="https://xss.rocks/scriptlet.html"></OBJECT>',
            '<EMBED SRC="data:image/svg+xml;base64,PHN2ZyB4bWxuczpzdmc9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48c2NyaXB0PmFsZXJ0KDEpPC9zY3JpcHQ+PC9zdmc+" type="image/svg+xml" AllowScriptAccess="always"></EMBED>',
            '<SCRIPT a=">" SRC="httx://xss.rocks/xss.js"></SCRIPT>',
            '<A HREF="http://66.102.7.147/">XSS</A>',
            "<A HREF=\"h\ntt\tp://6\t6.000146.0x7.147/\">XSS</A>",
            '<A HREF="//www.google.com/">XSS</A>',
            '<A HREF="javascript:document.location=\'https://www.google.com/\'">XSS</A>',
            '<a href="javas&#99;ript:alert(1)">x</a>',
            '<a href="javascript&colon;alert(1)">x</a>',
            '<a href="&#x6A;avascript:alert(1)">x</a>',
            '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
            // 解析の読み違いを突く書き方（mXSS）
            '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>',
            '<svg><style><img src=x onerror=alert(1)></style></svg>',
            '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
            '<form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>',
            '<svg></p><style><a id="</style><img src=1 onerror=alert(1)>">',
            '<math><mi><table><mi><svg><style><img src=x onerror=alert(1)>',
            '<template><script>alert(1)</script></template><textarea><img src=x onerror=alert(1)></textarea>',
            '<xmp><img src=x onerror=alert(1)></xmp><plaintext><img src=x onerror=alert(1)>',
            '<title><img src=x onerror=alert(1)></title><noembed><img src=x onerror=alert(1)></noembed>',
            // 今の書き方
            '<details open ontoggle=alert(1)>',
            '<video><source onerror="alert(1)"></video>',
            '<audio src=x onerror=alert(1)>',
            '<img src="x` `<script>javascript:alert(1)</script>"` `>',
            '<a href="/products" class="fixed inset-0 z-50">押させる</a>',
            '<div style="position:fixed;inset:0" onclick="alert(1)">覆う</div>',
            '<p id="cart-form" name="x">id と name で画面の要素を取り違えさせる</p>',
            '<a href="/x" ping="https://evil.example/">ping</a>',
            '<img src="/media/shop/01k8z3x5y7a9b1c3d5e7f9g1h3/large" onload="alert(1)" usemap="#m">',
            '<isindex action="javascript:alert(1)" type=image>',
            '<marquee onstart=alert(1)>',
            '<object data="javascript:alert(1)">',
            '<a xlink:href="javascript:alert(1)">x</a>',
            '<x onclick=alert(1)>知らない要素</x>',
        ];

        $out = [];
        foreach ($list as $i => $vector) {
            $out[sprintf('%02d %s', $i + 1, mb_strimwidth($vector, 0, 50, '…'))] = [$vector];
        }

        return $out;
    }

    #[DataProvider('vectors')]
    public function test_known_xss_vectors_leave_nothing_that_reaches_a_script(string $vector): void
    {
        $clean = SectionHtml::clean($vector);
        $this->assertSafe($clean->html);

        // 描くときの整え（保存した値にもう 1 度）も同じ
        $this->assertSafe((string) SectionHtml::render($clean->html)?->toHtml());
        $this->assertSafe((string) SectionHtml::render($vector)?->toHtml());
    }

    private function assertSafe(string $html): void
    {
        foreach (['<script', '<iframe', '<svg', '<math', '<style', '<object', '<embed', '<form', '<input', '<base', '<meta', '<link', '<template', '<noscript'] as $tag) {
            $this->assertStringNotContainsStringIgnoringCase($tag, $html);
        }

        $document = HTMLDocument::createFromString('<!DOCTYPE html><body>'.$html.'</body>', LIBXML_NOERROR);
        $this->assertNotNull($document->body);

        foreach ($document->body->querySelectorAll('*') as $element) {
            $name = strtolower($element->nodeName);
            $this->assertArrayHasKey($name, SectionHtml::ELEMENTS, "残してよい要素: {$name}（{$html}）");

            foreach ($element->attributes as $attribute) {
                $key = strtolower($attribute->name);
                $this->assertContains($key, [...SectionHtml::ELEMENTS[$name], 'class'], "残してよい属性: {$name}.{$key}（{$html}）");

                // スクリプトに届く値（javascript: など）を、制御文字と空白を除いてから探す
                $flat = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $attribute->value));
                foreach (['javascript:', 'vbscript:', 'data:', 'expression('] as $danger) {
                    $this->assertStringNotContainsString($danger, $flat, "{$name}.{$key}（{$html}）");
                }
            }

            if ($name === 'a' && $element->hasAttribute('href')) {
                $this->assertNotNull(SectionValues::safeLink($element->getAttribute('href')), $html);
                $this->assertSame('noopener noreferrer', $element->getAttribute('rel'));
            }

            if ($name === 'img') {
                $this->assertMatchesRegularExpression(SectionHtml::MEDIA_SRC, (string) $element->getAttribute('src'), $html);
            }

            if ($element->hasAttribute('class')) {
                foreach (explode(' ', (string) $element->getAttribute('class')) as $class) {
                    $this->assertArrayHasKey($class, SectionHtml::CLASSES, $html);
                }
            }
        }
    }
}
