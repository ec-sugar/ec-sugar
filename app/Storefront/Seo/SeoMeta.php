<?php

declare(strict_types=1);

namespace App\Storefront\Seo;

use Illuminate\Support\HtmlString;

/**
 * 1 つの画面の検索と共有の見え方（ADR 0061。テーマ API の一部）。核の部品 <x-storefront::seo> が <head> に描く。
 * テーマは中を組み立てず、部品に渡すだけ。URL はどれも絶対の URL
 *
 * - title が null の画面（検索に載せない画面）は、テーマの題（@yield('title')）をそのまま使う
 * - index が偽なら <meta name="robots" content="noindex">。canonical・OGP・JSON-LD は出さない
 */
final readonly class SeoMeta
{
    /** 題の区切り（「商品名 | 店の名前」） */
    public const string SEPARATOR = ' | ';

    /** 検索に載せない画面の robots */
    public const string NO_INDEX = 'noindex, nofollow';

    /** JSON をスクリプトの要素に入れても壊れない書き方（< > & ' " を \uXXXX に） */
    private const int JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * @param  string|null  $title  <title>（店の名前つき）。null ならテーマの題
     * @param  string|null  $shareTitle  og:title（店の名前を付けない題）
     * @param  array{url: string, width: int, height: int, alt: string|null}|null  $image  共有の写真
     * @param  list<array<string, mixed>>  $data  JSON-LD（schema.org の形の配列）
     */
    public function __construct(
        public bool $index,
        public string $siteName,
        public ?string $title = null,
        public ?string $shareTitle = null,
        public ?string $description = null,
        public ?string $canonical = null,
        public string $type = 'website',
        public ?array $image = null,
        public array $data = [],
    ) {}

    /**
     * 検索に載せない画面（題と説明はテーマのまま）
     */
    public static function hidden(string $siteName): self
    {
        return new self(false, $siteName);
    }

    /**
     * 準備中のショップなど、載せる画面でも検索には載せない時の写し（題と説明はそのまま）
     */
    public function withoutIndex(): self
    {
        return new self(false, $this->siteName, $this->title, $this->shareTitle, $this->description, $this->canonical, $this->type, $this->image, $this->data);
    }

    public function robots(): ?string
    {
        return $this->index ? null : self::NO_INDEX;
    }

    /**
     * X のカードの種類（写真があれば大きい写真）
     */
    public function twitterCard(): string
    {
        return $this->image === null ? 'summary' : 'summary_large_image';
    }

    /**
     * JSON-LD の <script type="application/ld+json">（載せる画面で、データがある時だけ）。実行されないデータの塊なので CSP の script-src に掛からない。
     * 中身は JSON の HTML 用の書き方で、店の人が書いた文に </script> や " があっても要素の外へ出ない。Blade の部品にスクリプトの要素を書かないよう、ここで作る
     */
    public function jsonLd(): ?HtmlString
    {
        if (! $this->index || $this->data === []) {
            return null;
        }

        $blocks = array_map(static fn (array $item): string => '<script type="application/ld+json">'.json_encode($item, self::JSON_FLAGS).'</script>', $this->data);

        return new HtmlString(implode("\n    ", $blocks));
    }

    /**
     * 文を 1 行にそろえ、長ければ切る（自動の説明。末尾に「…」）
     */
    public static function excerpt(?string $text, int $limit): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)).'…' : $text;
    }
}
