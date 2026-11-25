<?php

declare(strict_types=1);

namespace App\Storefront\View;

use App\Core\Plugin\Sections\Embed;

/**
 * App の区画（ADR 0040）を描くときに、核が決めたこと。App の Blade は $section->app を核の部品 <x-storefront.embed> に渡すだけで、
 * 枠（iframe）を描くかを自分では決めない。
 *
 *   embed    埋め込むもの（App の係が作り、核が宣言の宛先に合うと確かめた URL。読めない・設定が足りないときは null）
 *   live     ショップで出してよい（区画に同意の種類が無いか、お客さんが全部に同意している。動画・地図は同意の種類が無いので誰にでも。
 *            編集の画面のプレビューでは偽）
 *   preview  編集の画面のプレビュー・試し見（外へは何も読み込まない。枠の代わりに無地の箱）
 *   ready    App の入れるべき設定がそろっている
 *   frames   この区画を出す画面の CSP の frame-src に足す宛先（宣言の frame-src）
 *   sandbox・allow  枠に付ける許し（宣言の中から。核の決めた言葉だけ）
 *   embeds   外から埋め込む区画か（宣言に embed がある）。埋め込まない区画（App「おすすめの区画」の商品の並びなど。ADR 0071）は、
 *            埋め込むものが無くても、出してよく設定がそろえば出す
 *   content  店のデータを並べる区画（ADR 0072。新着のお知らせなど）で、App の係が返した出してよいもの（AppSectionContent。無ければ null）
 */
final readonly class AppSectionView
{
    /**
     * @param  list<string>  $frames
     * @param  list<string>  $sandbox
     * @param  list<string>  $allow
     */
    public function __construct(
        public string $plugin,
        public string $title,
        public ?Embed $embed,
        public bool $live,
        public bool $preview,
        public bool $ready,
        public array $frames = [],
        public array $sandbox = [],
        public array $allow = [],
        public bool $embeds = true,
        public ?object $content = null,
    ) {}

    /**
     * ページに出すか。ショップでは、設定がそろって埋め込むもの（か並べるもの）があり、出してよいときだけ（空の箱を出さない）。編集の画面のプレビューでは出す（「入れると出ます」）。
     * 埋め込まない区画は、出してよく設定がそろえば出す（中身が空のときに何も描かないのは App の Blade が決める）。
     * 店のデータを並べる係を持つ区画（ADR 0072）は embeds を真にして渡すので、並べるものが無ければ出さない
     */
    public function visible(): bool
    {
        return $this->preview || ($this->live && $this->ready && ($this->embed !== null || $this->content !== null || ! $this->embeds));
    }

    /**
     * この区画が外へつなぐか（ショップで、埋め込むものがある）。CSP の frame-src を足すのはこのときだけ
     */
    public function connects(): bool
    {
        return ! $this->preview && $this->live && $this->ready && $this->embed !== null && $this->frames !== [];
    }
}
