<?php

declare(strict_types=1);

namespace EcSugar\PluginVideo;

use App\Core\Plugin\Sections\AppSectionHandler;
use App\Core\Plugin\Sections\Embed;

/**
 * 動画の区画の係（ADR 0040）。店の人が貼った URL から、YouTube か Vimeo の動画の ID を取り出す。決まった形の URL だけを受け、ほかは断る。
 * 埋め込む URL は、取り出した ID だけから組み立てる（貼った URL の残りは使わない。別のホスト・javascript: などは形の確かめで断る）。
 *
 * 受ける形（https だけ）:
 *   YouTube  www.youtube.com・youtube.com・m.youtube.com の /watch?v=ID・/shorts/ID・/embed/ID・/live/ID、youtu.be/ID、
 *            www.youtube-nocookie.com/embed/ID（ID は英数字・_・- の 11 文字）
 *   Vimeo    vimeo.com・www.vimeo.com の /数字 と /数字/限定公開の印、player.vimeo.com/video/数字（?h=限定公開の印）
 * 埋め込み: YouTube はプライバシー強化モード（www.youtube-nocookie.com/embed/ID）、Vimeo は追跡しない設定（dnt=1）。
 * 店の人が選べる: 自動で再生する（音を消すが付く）・音を消す・操作の表示を出さない・繰り返す（欄は核の選ぶ欄。値は決まった選択肢だけ）
 *   YouTube  autoplay=1・mute=1・controls=0・loop=1 と playlist=ID
 *   Vimeo    autoplay=1・muted=1・controls=0・loop=1
 */
final class VideoSections implements AppSectionHandler
{
    public const string TYPE = 'app_video';

    private const int MAX = 200;

    /** URL に使える文字（引用符・山かっこ・空白・バックスラッシュを通さない） */
    private const string URL = '#\Ahttps://[A-Za-z0-9\-._~:/?\#\[\]@!$&()*+,;=%]+\z#';

    private const string YOUTUBE_ID = '/\A[A-Za-z0-9_-]{11}\z/';

    public function problems(string $type, array $values): array
    {
        $url = $values['url'] ?? null;

        if (is_string($url) && self::parse($url) === null) {
            return ['url' => 'YouTube か Vimeo の動画のページの URL を貼ってください（例: https://www.youtube.com/watch?v=… ・ https://vimeo.com/…）'];
        }

        return [];
    }

    public function embed(string $type, array $values, array $settings): ?Embed
    {
        $video = is_string($values['url'] ?? null) ? self::parse($values['url']) : null;

        if ($video === null) {
            return null;
        }

        $heading = is_string($values['heading'] ?? null) ? $values['heading'] : null;
        $title = $heading === null ? '動画' : '動画: '.$heading;

        // 店の人の選んだもの（選択肢の値だけを見る。値そのものは URL に入れない）。自動で再生するなら音を消す（ブラウザは音ありの自動の再生を止める）。
        // 保存した値は変えず、描くときに付ける
        $autoplay = ($values['autoplay'] ?? null) === 'on';
        $mute = $autoplay || ($values['mute'] ?? null) === 'on';
        $hideControls = ($values['controls'] ?? null) === 'hide';
        $loop = ($values['loop'] ?? null) === 'on';
        // 自動で再生するときだけ、枠の allow に autoplay を付ける（宣言の allow の中から。核が重なりだけを使う）
        $allow = $autoplay ? ['autoplay', 'encrypted-media', 'fullscreen', 'picture-in-picture'] : ['encrypted-media', 'fullscreen', 'picture-in-picture'];

        if ($video['service'] === 'youtube') {
            // YouTube のループは、同じ動画を playlist にも書く（1 本だけの繰り返し）
            $query = self::query(['rel' => '0', 'playsinline' => '1', 'autoplay' => $autoplay ? '1' : null, 'mute' => $mute ? '1' : null,
                'controls' => $hideControls ? '0' : null, 'loop' => $loop ? '1' : null, 'playlist' => $loop ? $video['id'] : null]);

            return new Embed("https://www.youtube-nocookie.com/embed/{$video['id']}?{$query}", $title, "https://www.youtube.com/watch?v={$video['id']}", $allow);
        }

        // Vimeo の controls=0 は有料のプランの動画でだけ効く（欄の説明に書いた）
        $query = self::query(['dnt' => '1', 'h' => $video['hash'], 'autoplay' => $autoplay ? '1' : null, 'muted' => $mute ? '1' : null,
            'controls' => $hideControls ? '0' : null, 'loop' => $loop ? '1' : null]);

        return new Embed("https://player.vimeo.com/video/{$video['id']}?{$query}", $title, "https://vimeo.com/{$video['id']}".($video['hash'] === null ? '' : "/{$video['hash']}"), $allow);
    }

    /**
     * URL の値の並び（null は書かない）。値は確かめた ID と決まった文字だけ
     *
     * @param  array<string, string|null>  $pairs
     */
    private static function query(array $pairs): string
    {
        return http_build_query(array_filter($pairs, static fn (?string $v): bool => $v !== null), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * URL から動画を取り出す。決まった形でなければ null
     *
     * @return array{service: 'youtube'|'vimeo', id: string, hash: string|null}|null
     */
    public static function parse(string $url): ?array
    {
        if (strlen($url) > self::MAX || preg_match(self::URL, $url) !== 1) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';
        parse_str($parts['query'] ?? '', $query);

        return match ($host) {
            'www.youtube.com', 'youtube.com', 'm.youtube.com' => self::youtube($path === '/watch' ? ($query['v'] ?? null) : self::segment($path, ['shorts', 'embed', 'live'])),
            'www.youtube-nocookie.com' => self::youtube(self::segment($path, ['embed'])),
            'youtu.be' => self::youtube(preg_match('#\A/([^/]+)/?\z#', $path, $m) === 1 ? $m[1] : null),
            'vimeo.com', 'www.vimeo.com' => preg_match('#\A/([0-9]{1,12})(?:/([0-9a-f]{6,20}))?/?\z#', $path, $m) === 1 ? self::vimeo($m[1], $m[2] ?? null) : null,
            'player.vimeo.com' => preg_match('#\A/video/([0-9]{1,12})/?\z#', $path, $m) === 1 ? self::vimeo($m[1], $query['h'] ?? null) : null,
            default => null,
        };
    }

    /**
     * /{前の語}/{ID} の ID
     *
     * @param  list<string>  $prefixes
     */
    private static function segment(string $path, array $prefixes): ?string
    {
        return preg_match('#\A/('.implode('|', $prefixes).')/([^/]+)/?\z#', $path, $m) === 1 ? $m[2] : null;
    }

    /**
     * @return array{service: 'youtube', id: string, hash: null}|null
     */
    private static function youtube(mixed $id): ?array
    {
        return is_string($id) && preg_match(self::YOUTUBE_ID, $id) === 1 ? ['service' => 'youtube', 'id' => $id, 'hash' => null] : null;
    }

    /**
     * @return array{service: 'vimeo', id: string, hash: string|null}|null
     */
    private static function vimeo(string $id, mixed $hash): ?array
    {
        if ($hash !== null && (! is_string($hash) || preg_match('/\A[0-9a-f]{6,20}\z/', $hash) !== 1)) {
            return null;
        }

        // 0 で始まる番号・0 は動画の番号にならない
        return preg_match('/\A[1-9][0-9]*\z/', $id) === 1 ? ['service' => 'vimeo', 'id' => $id, 'hash' => $hash] : null;
    }
}
