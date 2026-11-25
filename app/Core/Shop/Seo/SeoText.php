<?php

declare(strict_types=1);

namespace App\Core\Shop\Seo;

/**
 * 検索の結果に出す題と説明の決まり（ADR 0061）。商品・カテゴリ・自由なページ・トップで同じ。
 * 上限はサーバーと DB（列の長さと 1 行の制約。テナントの移行 2026_11_05_014000）、目安は画面に出すだけ（Google の結果に収まる長さ）
 */
final class SeoText
{
    public const int TITLE_MAX = 70;

    public const int DESCRIPTION_MAX = 200;

    /** 画面に出す目安（日本語の検索の結果に収まるおおよその文字数） */
    public const int TITLE_GUIDE = 30;

    public const int DESCRIPTION_GUIDE = 120;

    /** 改行・制御文字・向きを変える印（見た目と中身を食い違わせる）を断る */
    private const string ONE_LINE = 'not_regex:/[\p{Cc}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /**
     * Validator の規則（空は「自動」。呼ぶ側が clean で空を null にする）
     *
     * @return array{seo_title: list<string>, seo_description: list<string>}
     */
    public static function rules(): array
    {
        return [
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:'.self::TITLE_MAX, self::ONE_LINE],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:'.self::DESCRIPTION_MAX, self::ONE_LINE],
        ];
    }

    /**
     * 誤りの文の呼び名
     *
     * @return array{seo_title: string, seo_description: string}
     */
    public static function attributes(): array
    {
        return ['seo_title' => '検索結果に出すタイトル', 'seo_description' => '検索結果に出す説明'];
    }

    /**
     * 誤りの文（1 行でない時）
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'seo_title.not_regex' => '検索結果に出すタイトルは、改行や見えない文字の無い 1 行にしてください。',
            'seo_description.not_regex' => '検索結果に出す説明は、改行や見えない文字の無い 1 行にしてください。',
        ];
    }

    /**
     * 前後の空白を除き、空は null（自動）
     */
    public static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
