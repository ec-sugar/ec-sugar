<?php

declare(strict_types=1);

namespace EcSugar\PluginReviews;

/**
 * お客さんがショップで書いたレビューの欄を確かめる（星・題・本文・表示名）。HTML として扱わない（出すときはいつもエスケープ）が、
 * 見た目と中身をずらす文字（制御文字・文字の向きを変える印・幅の無い空白）は断る。長さの上限は DB の制約と同じ。
 * 表示名にメールアドレスの形（@）は入れさせない（うっかり公開しないように）。空なら「購入者」
 */
final readonly class ReviewInput
{
    public const int TITLE_MAX = 50;

    public const int BODY_MAX = 1000;

    public const int NAME_MAX = 20;

    public const string DEFAULT_NAME = '購入者';

    // 1 行の欄で断る文字: 制御文字（改行を含む）・文字の向きを変える印（LRM・RLM・ALM・埋め込み・上書き・分離）・幅の無い空白と BOM。
    // 絵文字をつなぐ幅の無い接続子（U+200D）はつないだ絵文字に要るので通す
    private const string LINE_UNSAFE = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u';

    // 本文で断る文字: 改行（\n）のほかは 1 行の欄と同じ
    private const string BLOCK_UNSAFE = '/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u';

    private function __construct(
        public int $rating,
        public string $title,
        public string $body,
        public string $displayName,
    ) {}

    /**
     * 確かめて形をそろえる（前後の空白を落とし、改行は \n に）。合わなければ欄の名前 => 一言
     *
     * @return self|array<string, string>
     */
    public static function from(mixed $rating, mixed $title, mixed $body, mixed $displayName): self|array
    {
        $errors = [];
        $stars = is_string($rating) || is_int($rating) ? filter_var($rating, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]) : false;

        if (! is_int($stars)) {
            $errors['rating'] = '星を 1〜5 から選んでください。';
        }

        $title = self::text($title);
        $body = self::text($body);
        $name = self::text($displayName);
        $name = $name === '' ? self::DEFAULT_NAME : $name;

        $errors += array_filter([
            'title' => self::problem($title, self::TITLE_MAX, false, 'タイトル'),
            'body' => self::problem($body, self::BODY_MAX, true, '本文'),
            'display_name' => self::problem($name, self::NAME_MAX, false, '表示名') ?? (str_contains((string) $name, '@') || str_contains((string) $name, '＠') ? '表示名にメールアドレスは使えません。' : null),
        ]);

        if ($errors !== [] || ! is_int($stars) || ! is_string($title) || ! is_string($body) || ! is_string($name)) {
            return $errors === [] ? ['body' => '文字で入力してください。'] : $errors;
        }

        return new self($stars, $title, $body, $name);
    }

    /**
     * 前後の空白を落とし、改行を \n にそろえる。文字でない・壊れた文字は null（後で断る）
     */
    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return '';
        }

        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        // 前後の空白は全角の空白も落とす（trim の文字の並びはバイトで比べるので、多バイトの文字は正規表現で）
        return (string) preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', str_replace(["\r\n", "\r"], "\n", $value));
    }

    private static function problem(?string $value, int $max, bool $multiline, string $label): ?string
    {
        return match (true) {
            $value === null => '文字で入力してください。',
            $value === '' => "{$label}を入力してください。",
            mb_strlen($value) > $max => "{$label}は {$max} 文字以内で入力してください。",
            preg_match($multiline ? self::BLOCK_UNSAFE : self::LINE_UNSAFE, $value) !== 0 => $multiline ? "{$label}に使えない文字が入っています。" : "{$label}に改行や使えない文字が入っています。",
            default => null,
        };
    }
}
