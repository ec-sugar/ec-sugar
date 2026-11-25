<?php

declare(strict_types=1);

namespace App\Admin\Demo;

use App\Core\Sample\SampleSet;

/**
 * デモの店 1 つの中身（ADR 0086）。商品・カテゴリ・テーマの中身・送料・特商法の表記・写真の出どころは、
 * 入れたときのサンプル（SampleSet）と同じ形で書き、同じ仕組み（SampleInstaller）で入れる。そのほかの App の設定などはここに書く。
 *
 * 値の中の名前（`listing:<商品の名前>`・`category:<slug>`・`media:<写真の名前>`）は、入れる時に ID に替える。
 * 日付は、作る日から数えた日数（`+30d`）か、その日の時刻（`+30d 19:00`）で書く（毎晩作り直すので、いつ見ても先の日付になる）。
 *
 * App が入っていない木（公開用の 1.0 には定期便・予約・チケット・抽選販売の App が無い。ADR 0098）では、その App の設定・商品の欄・
 * App の画面の記録・その App の話の記事（app）を作らず飛ばす。その App の商品の扱い（kind）の商品も作らない（BaseDemo）
 *
 * @phpstan-type Post array{title: string, slug: string, excerpt: string, body: list<array<string, mixed>>, app?: string}
 * @phpstan-type Coupon array<string, mixed>
 */
interface DemoDefinition extends SampleSet
{
    /** 店の扱い（コマンドの引数。sake・coffee・wagashi・livehouse） */
    public function kind(): string;

    /**
     * 店の名前の候補（はじめのものを既定に使う。どれも架空の店）
     *
     * @return list<string>
     */
    public function names(): array;

    /** 紹介のページに出す「この店のこの困りごとを、こう解いている」の一文 */
    public function pitch(): string;

    /** 紹介のページの文（店の架空の成り立ち） */
    public function story(): string;

    /**
     * 使う App と設定（App の名前 => 設定の値。空なら使うだけ）
     *
     * @return array<string, array<string, bool|string>>
     */
    public function apps(): array;

    /**
     * 商品の画面の App の欄（商品の名前 => App の名前 => 欄の値）
     *
     * @return array<string, array<string, array<string, bool|string>>>
     */
    public function panels(): array;

    /**
     * App の画面で足す記録（App の名前 => 記録の値の並び。ラッピングの種類など）
     *
     * @return array<string, list<array<string, bool|string>>>
     */
    public function forms(): array;

    /**
     * ダウンロード販売の商品に置くファイル（商品の名前 => [ファイルの名前, 作り方]）。作り方は `wav`（無音の見本の音）か `txt:<文>`
     *
     * @return array<string, list<array{0: string, 1: string}>>
     */
    public function files(): array;

    /**
     * クーポンと自動の割引（CouponService::create の値。target_ids は `listing:…`・`category:…` で書く）
     *
     * @return list<Coupon>
     */
    public function coupons(): array;

    /**
     * お知らせの記事（App「お知らせ・ブログ」。2〜3 本）。app はその記事が話す App（入っていなければ作らない）
     *
     * @return list<Post>
     */
    public function posts(): array;

    /**
     * 店の設定（どれも任意）: points（PointSettings）・delivery（DeliveryDates）・direct（DirectMethodSettings）・
     * pickup（店頭で受け取る商品の名前の並び。送料 0 円の配送の方法「店頭でお受け取り」に結ぶ）・order_terms（ShopSettingsService::setOrderTerms）
     *
     * @return array<string, mixed>
     */
    public function settings(): array;
}
