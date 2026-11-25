<?php

declare(strict_types=1);

namespace App\Core\Plugin\Manifest;

/**
 * App がマニフェストに書く CSP の宛先の形の確かめ（ADR 0034 の 2）。足せるのは決まった 4 つの種類に、https のホストだけ。
 * 断る: 'unsafe-inline' など引用符の付く書き方（nonce・hash も）、data:・blob: などの仕組み、* だけ・https: だけ、http、IP の住所、
 * 1 語だけのホスト（localhost など）、*. の後ろが 1 語だけ（*.com）。*. のサブドメインは許す。大文字は断る（同じ宛先を 1 つの形に）
 */
final class CspSources
{
    /** 足せる CSP の種類 */
    public const array DIRECTIVES = ['script-src', 'connect-src', 'img-src', 'frame-src'];

    public const int MAX_PER_DIRECTIVE = 20;

    private const int MAX_LENGTH = 200;

    // ホスト名の 1 語（英小文字・数字・ハイフン、端はハイフンでない）と、最後の語（英字で始まる。数字だけの IP の住所を断る）
    private const string LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    private const string TLD = '[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /**
     * 断る理由（よければ null）
     */
    public static function problem(mixed $source): ?string
    {
        if (! is_string($source) || $source === '' || strlen($source) > self::MAX_LENGTH) {
            return '1〜'.self::MAX_LENGTH.' 文字の文字列';
        }

        if (preg_match('/[\s;,\'"]/', $source) === 1 || str_starts_with($source, "'")) {
            return "引用符・空白・; は書けない（'unsafe-inline'・'unsafe-eval'・nonce・hash は足せない）";
        }

        if ($source === '*' || preg_match('/\A[a-z][a-z0-9+.-]*:\z/i', $source) === 1) {
            return '* だけ・data: などの仕組みだけは書けない（https:// のホスト名を書く）';
        }

        if (! str_starts_with($source, 'https://')) {
            return 'https:// で始まる宛先だけ';
        }

        $host = (string) preg_replace('~\Ahttps://([^/:]*).*\z~s', '$1', $source);

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || str_starts_with($host, '[')) {
            return 'IP の住所は書けない（ホスト名を書く）';
        }

        $pattern = '~\Ahttps://(?:\*\.)?(?:'.self::LABEL.'\.)+'.self::TLD.'(?::[0-9]{1,5})?(?:/[A-Za-z0-9._\~/-]*)?\z~';

        if (preg_match($pattern, $source) !== 1) {
            return 'https://ホスト名（英小文字。*. のサブドメインは可、*. の後ろは 2 語以上）の形だけ';
        }

        return null;
    }

    /**
     * URL が宛先のどれかに合うか（App の区画の枠とリンク。ADR 0040）。考え方は CSP と核の Trusted Types の関所と同じ:
     * https だけ・*. はサブドメイン（元のドメインそのものは含まない）・ポートは書いた番号だけ（書かなければ 443）・
     * 道筋は / で終われば前方一致、そうでなければ同じ道筋だけ。利用者名やパスワードの付いた URL、空白・引用符・山かっこの入った URL、
     * 道筋に「.」「..」の区切りや符号化した点・斜線（%2e・%2f・%5c）のある URL は断る
     *
     * @param  list<string>  $sources
     */
    public static function matchesAny(array $sources, string $url): bool
    {
        if (strlen($url) > 2000 || preg_match('#\Ahttps://[A-Za-z0-9\-._~:/?\#\[\]@!$&()*+,;=%]+\z#', $url) !== 1) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? 443;
        $path = $parts['path'] ?? '/';

        // 道筋の「.」「..」の区切りと、符号化した点・斜線（%2e・%2f・%5c。大文字小文字とも）は断る。前方一致の宛先（/maps/embed/v1/）から
        // /maps/embed/v1/../../../url のように外へ出られないように（枠は CSP が畳んだ道筋で止めるが、外で開くリンクには CSP が掛からない）
        if (preg_match('~%(?:2e|2f|5c)~i', $path) === 1 || array_intersect(explode('/', $path), ['.', '..']) !== []) {
            return false;
        }

        foreach ($sources as $source) {
            if (self::problem($source) !== null || preg_match('~\Ahttps://(\*\.)?([^/:]+)(?::([0-9]{1,5}))?(/.*)?\z~', $source, $m) !== 1) {
                continue;
            }

            $wildcard = $m[1] !== '';
            $hostOk = $wildcard ? str_ends_with($host, '.'.$m[2]) : $host === $m[2];
            $portOk = $port === (($m[3] ?? '') === '' ? 443 : (int) $m[3]);
            $want = $m[4] ?? '';
            $pathOk = $want === '' || (str_ends_with($want, '/') ? str_starts_with($path, $want) : $path === $want);

            if ($hostOk && $portOk && $pathOk) {
                return true;
            }
        }

        return false;
    }
}
