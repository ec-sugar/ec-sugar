<?php

declare(strict_types=1);

namespace App\Core\Section;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * 区画の HTML の属性の値の決まり（SectionHtml の表で残すと決めた属性だけに掛かる）。
 * 値は解析器が文字の参照（&#106; など）を戻した後の値で見る（隠した javascript: もここで見える）。合わなければ null で属性ごと消す。
 *
 * - href: 店の中の道筋か https の URL（区画のリンクの欄と同じ決まり。SectionValues::safeLink）
 * - src: 店の写真の置き場（/media/shop/{ID}/large|medium）だけ
 * - class: SectionHtml::CLASSES の名前だけを残す（ほかの名前は外す。1 つも残らなければ属性ごと消す）
 * - width・height: 1〜4000 の整数。colspan・rowspan: 1〜20。start: -9999〜9999。scope: row・col・rowgroup・colgroup
 * - rel: いつも noopener noreferrer（SectionHtml の設定が上書きで付ける。ここでも同じ値にそろえる）
 * - alt・title: 文字のまま（書き出すときに symfony がエスケープする）
 */
final class SectionHtmlAttributes implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): array
    {
        return ['href', 'src', 'class', 'width', 'height', 'colspan', 'rowspan', 'start', 'scope', 'rel'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return match ($attribute) {
            'href' => SectionValues::safeLink($value),
            'src' => self::mediaSrc($value),
            'class' => self::classes($value),
            'width', 'height' => self::integer($value, 1, 4000),
            'colspan', 'rowspan' => self::integer($value, 1, 20),
            'start' => self::integer($value, -9999, 9999),
            'scope' => in_array(strtolower(trim($value)), ['row', 'col', 'rowgroup', 'colgroup'], true) ? strtolower(trim($value)) : null,
            'rel' => 'noopener noreferrer',
            default => $value,
        };
    }

    /**
     * 店の写真の置き場の道筋なら、小文字にそろえて返す
     */
    public static function mediaSrc(string $value): ?string
    {
        $src = strtolower(trim($value));

        return preg_match(SectionHtml::MEDIA_SRC, $src) === 1 ? $src : null;
    }

    private static function classes(string $value): ?string
    {
        $kept = array_values(array_unique(array_filter(
            preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn (string $name): bool => array_key_exists($name, SectionHtml::CLASSES),
        )));

        return $kept === [] ? null : implode(' ', $kept);
    }

    private static function integer(string $value, int $min, int $max): ?string
    {
        $text = trim($value);

        if (preg_match('/\A-?\d{1,5}\z/', $text) !== 1) {
            return null;
        }

        $number = (int) $text;

        return $number >= $min && $number <= $max ? (string) $number : null;
    }
}
