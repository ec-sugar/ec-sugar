<?php

declare(strict_types=1);

namespace App\Core\Plugin\Sections;

use App\Core\Plugin\Manifest\CspSources;

/**
 * App の区画が外から埋め込むときの決まり（マニフェストの sections.{種類}.embed。ADR 0040）。核が登録のときに確かめる:
 *   frame-src  枠に読み込んでよい宛先（CSP の宛先の形。ADR 0034 と同じ確かめ）。その区画を出す画面の CSP の frame-src にだけ足す
 *   links      外のサイトで開くリンクの宛先（同じ形）
 *   sandbox    枠の sandbox に付ける許し（核が決めた SANDBOX の中から。上の階の画面を動かす・フォームを送る許しは付けられない）
 *   allow      枠の allow に付ける機能（核が決めた ALLOW の中から。カメラ・マイク・位置・支払いなどは付けられない）
 */
final readonly class EmbedRules
{
    /** 枠の sandbox に付けてよい許し（ほかは断る） */
    public const array SANDBOX = ['allow-scripts', 'allow-same-origin', 'allow-presentation', 'allow-popups', 'allow-popups-to-escape-sandbox'];

    /** 枠の allow に付けてよい機能（ほかは断る） */
    public const array ALLOW = ['autoplay', 'encrypted-media', 'fullscreen', 'picture-in-picture', 'clipboard-write', 'web-share'];

    /**
     * @param  list<string>  $frames
     * @param  list<string>  $links
     * @param  list<string>  $sandbox
     * @param  list<string>  $allow
     */
    public function __construct(
        public array $frames,
        public array $links,
        public array $sandbox,
        public array $allow,
    ) {}

    /**
     * 枠に読み込んでよい URL か（宣言した frame-src の宛先のどれかに合う https の URL）
     */
    public function allowsFrame(string $url): bool
    {
        return CspSources::matchesAny($this->frames, $url);
    }

    /**
     * 外のサイトで開くリンクにしてよい URL か
     */
    public function allowsLink(string $url): bool
    {
        return CspSources::matchesAny($this->links, $url);
    }
}
