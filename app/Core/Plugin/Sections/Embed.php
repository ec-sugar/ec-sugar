<?php

declare(strict_types=1);

namespace App\Core\Plugin\Sections;

/**
 * App の区画が外から埋め込むもの（ADR 0040）。App の区画の係（AppSectionHandler）が欄の値から作り、核が確かめてから描く:
 *   - src は、その区画が宣言した frame-src の宛先に合う https の URL だけ（合わなければ区画を出さない）
 *   - link は、その区画が宣言した links の宛先に合う https の URL だけ（合わなければリンクを出さない）
 *   - allow は、その区画が宣言した allow の中からだけ（宣言に無い言葉は落とす。宣言より広げられない）
 *   - 枠（iframe）を作るのは核の部品（<x-storefront.embed>）だけ。ショップの HTML に最初から枠を描く（自動で再生しない URL にする）
 */
final readonly class Embed
{
    /**
     * @param  string  $src  枠の URL（ショップの HTML に最初から描く。自動で再生しない形）
     * @param  string  $title  枠の名前（読み上げ。例: 「動画: …」「地図: …」）
     * @param  string|null  $link  外のサイトで開くリンク（枠の下に「別のタブで開く」として出す）
     * @param  list<string>|null  $allow  この枠に付ける allow（例: 自動で再生するときだけ autoplay）。宣言の allow に無い言葉は核が落とす。null なら宣言のまま
     */
    public function __construct(
        public string $src,
        public string $title,
        public ?string $link = null,
        public ?array $allow = null,
    ) {}
}
