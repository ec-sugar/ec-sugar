<?php

declare(strict_types=1);

namespace App\Core\Section;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Symfony\Component\HtmlSanitizer\Parser\ParserInterface;

/**
 * 区画の HTML の解析と下見（SectionHtml から、1 回の整えごとに 1 つ作る）。
 * symfony/html-sanitizer の解析器（NativeParser）と同じく PHP の HTML5 の解析器で body の中として読み、整える前に木を下見する:
 *
 * - 入れ子の深さを数え、SectionHtml::MAX_DEPTH を越えたら何も返さない（tooDeep。整え方は木を再帰でたどるので、深すぎる木を渡さない）
 * - 店の写真の置き場でない写真（img）は要素ごと外す（symfony の決まりは属性だけを外すので、src の無い img が残らないように）
 * - 外すもの（要素・属性・class・リンク先・写真）を、管理画面に知らせる一覧に書く（決まりは SectionHtml の表と SectionHtmlAttributes で、
 *   整えるのは symfony。ここは知らせるためだけに同じ表を読む）
 *
 * 解析はいつも同じ包み方（<!DOCTYPE html><body>…</body>）で、symfony の NativeParser と同じ木になる
 */
final class SectionHtmlParser implements ParserInterface
{
    public bool $tooDeep = false;

    /** @var array<string, true> */
    private array $removed = [];

    public function parse(string $html, string $context = 'body'): ?Node
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><body>'.$html.'</body>', LIBXML_NOERROR, 'UTF-8');
        $body = $document->body;

        if ($body === null) {
            return null;
        }

        // 再帰でなく、自分で積んだ並びでたどる（深い木でも PHP の呼び出しを積まない）
        $stack = [[$body, 0]];
        while ($stack !== []) {
            [$node, $depth] = array_pop($stack);

            foreach (iterator_to_array($node->childNodes) as $child) {
                if (! $child instanceof Element) {
                    continue;
                }

                if ($depth + 1 > SectionHtml::MAX_DEPTH) {
                    $this->tooDeep = true;

                    return null;
                }

                if ($this->visit($child)) {
                    $stack[] = [$child, $depth + 1];
                }
            }
        }

        return $body->hasChildNodes() ? $body : null;
    }

    /**
     * 外したものの一覧（重ならない・SectionHtml::MAX_REPORTS 個まで）
     *
     * @return list<string>
     */
    public function removed(): array
    {
        return array_keys($this->removed);
    }

    /**
     * 要素を 1 つ下見する。中をたどるなら true
     */
    private function visit(Element $element): bool
    {
        // symfony と同じく、要素の名前を小文字にして表と比べる（svg・math の中の要素は外側ごと消えるので、ここまで来ない）
        $name = strtolower($element->nodeName);
        $action = SectionHtml::action($name);

        if ($action === 'drop') {
            $this->report("<{$name}>");

            return false;
        }

        if ($action === 'unwrap') {
            $this->report("<{$name}>（タグだけ取り除き、中の文は残します）");

            return true;
        }

        $allowed = [...SectionHtml::ELEMENTS[$name], 'class'];
        $rules = new SectionHtmlAttributes;

        if ($name === 'img' && SectionHtmlAttributes::mediaSrc($element->getAttribute('src') ?? '') === null) {
            $src = $element->getAttribute('src');
            $this->report($src === null || $src === '' ? '写真（アドレスの無いもの）' : '写真「'.$this->short($src).'」（ショップの「写真」にある写真だけ使えます）');
            $element->remove();

            return false;
        }

        foreach ($element->attributes as $attribute) {
            $key = strtolower($attribute->name);

            if (! in_array($key, $allowed, true)) {
                $this->report(match (true) {
                    str_starts_with($key, 'on') => "{$key} の属性（スクリプト）",
                    default => "{$key} の属性",
                });

                continue;
            }

            $value = $rules->sanitizeAttribute($name, $key, $attribute->value, SectionHtml::config());

            if ($key === 'class') {
                $kept = $value === null ? [] : explode(' ', $value);
                $dropped = array_diff(preg_split('/\s+/', trim($attribute->value), -1, PREG_SPLIT_NO_EMPTY) ?: [], $kept);

                if ($dropped !== []) {
                    $this->report('class「'.$this->short(implode(' ', $dropped)).'」');
                }
            } elseif ($value === null) {
                $this->report(match ($key) {
                    'href' => 'リンク先「'.$this->short($attribute->value).'」（ショップ内のページか、https:// で始まる URL だけ使えます）',
                    default => "{$key} の値「".$this->short($attribute->value).'」',
                });
            }
        }

        return true;
    }

    private function report(string $what): void
    {
        if (count($this->removed) < SectionHtml::MAX_REPORTS) {
            $this->removed[$what] = true;
        }
    }

    private function short(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_strlen($value) > 60 ? mb_substr($value, 0, 60).'…' : $value;
    }
}
