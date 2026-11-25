<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog;

use App\Core\Listing\Description\ProductDescription;
use App\Core\Plugin\Services\ContentAccess;
use App\Storefront\Members\Member;
use EcSugar\PluginBlog\Models\BlogPost;

/**
 * 有料の記事の「ここで切る」を決める**ただ 1 か所**。出口（記事の画面・一覧の抜き書き・Atom・サイトマップ・
 * 新着のお知らせの区画・プレビュー）はどれもここを通る。出口ごとに切り方を書かない（書き漏らしが事故になる）。
 *
 * 決めたこと:
 *
 * - 記事は「読める条件」として**鍵の一覧**（`<種類>:<名前>`）を持ち、**1 つでも持っていれば読める**（核の `ContentAccess::any()`）。
 *   答えるのは売る側の App で、ブログは売り方（買い切り・月額・会員ランク）を知らない
 * - **読める条件の無い記事は、今までどおり誰でも全部読める**
 * - 読める条件があり「ここから有料」（`paid_body`）が空でなければ、`body` が無料の分・`paid_body` が有料の分
 * - **読める条件があって「ここから有料」が空なら、本文の全部が有料**（題・抜き書き・一覧の写真だけを出す）。
 *   条件を付けたのに全部読めてしまう方が事故なので、閉じる側へ倒す
 * - 権利の無い人には**有料の分を組み立てない**（レスポンスに 1 バイトも入らない）。隠すのでも、CSS で消すのでもない
 * - 聞く相手は会員の台帳の ID（`Member::viewerId()`）。ログインしていない人には核が App に聞かない（鍵は台帳に結びつく）
 */
final class BlogPaywall
{
    /** 1 記事の読める条件の数（核は 1 回に 20 個まで聞く。それより少なくして、聞く回数を増やさない） */
    public const int MAX_KEYS = 10;

    public function __construct(private readonly ContentAccess $access) {}

    /**
     * その記事で、今の人に**送ってよいブロック**だけ
     *
     * @param  bool  $asAuthor  管理画面から出した署名つきのプレビュー（書いた本人）。有料の分も組み立てる
     */
    public function read(BlogPost $post, bool $asAuthor = false): BlogReading
    {
        [$free, $paid] = self::split($post);
        $keys = self::keysOf($post);

        if ($paid === []) {
            return new BlogReading($free, locked: false, keys: $keys, signedIn: false, hasFreePart: $free !== []);
        }

        // プレビューは署名が本人の証（管理画面の運用の段階の人だけが出せる URL）なので、会員には聞かない
        $viewer = $asAuthor ? null : Member::viewerId();

        if ($asAuthor || $this->access->any($viewer, $keys)) {
            return new BlogReading([...$free, ...$paid], locked: false, keys: $keys, signedIn: $viewer !== null, hasFreePart: $free !== []);
        }

        return new BlogReading($free, locked: true, keys: $keys, signedIn: $viewer !== null, hasFreePart: $free !== []);
    }

    /**
     * 誰にでも出してよい分（一覧の抜き書き・Atom の要約・検索と共有の説明・サイトマップの判断に使う）
     *
     * @return list<array<string, mixed>>
     */
    public static function free(BlogPost $post): array
    {
        return self::split($post)[0];
    }

    /**
     * 記事の「読める条件」。鍵の形は書く時（`BlogPosts`）と DB の CHECK が守り、核（`ContentAccess`）も App に聞く前にもう一度形を見る
     * （形の違う鍵では何も開かない）。ここでは重なりと数だけ押さえる
     *
     * @return list<string>
     */
    public static function keysOf(BlogPost $post): array
    {
        return array_slice(array_values(array_unique($post->access_keys)), 0, self::MAX_KEYS);
    }

    /**
     * 無料の分と有料の分（上の「決めたこと」のとおり）。崩れたブロックは核が読むときに捨てる
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private static function split(BlogPost $post): array
    {
        $body = ProductDescription::readBlocks($post->body);

        // 読める条件が無ければ有料の分は無い（DB の CHECK でも守るが、ここでも閉じる側へ倒す）
        if (self::keysOf($post) === []) {
            return [$body, []];
        }

        $paid = ProductDescription::readBlocks($post->paid_body);

        return $paid === [] ? [[], $body] : [$body, $paid];
    }
}
