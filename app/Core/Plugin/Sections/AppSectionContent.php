<?php

declare(strict_types=1);

namespace App\Core\Plugin\Sections;

/**
 * 店のデータを並べる App の区画（ADR 0072。お知らせ・ブログの「新着のお知らせ」など）。区画の係（AppSectionHandler）が、これも実装したときだけ使う。
 * 外から埋め込む区画（embed）と違い、枠も外への送信も無く、App が自分の表から読んだ「出してよいもの」だけを返す。
 *
 * 核は描く前に content を呼び、返った値を $section->app->content に入れる（App の Blade と、描き直すテーマの部品が読む）。
 * null は「出すものが無い」（ショップでは区画を出さない。編集の画面のプレビューでは出す）。係が落ちてもページは止めない（その区画を出さないだけ）。
 * 返すのは画面に出す形（文・URL の道筋・写真〔ShopPhoto〕など）だけで、モデルや個人情報を渡さない。呼ぶのは使っている店の文脈の中だけ
 */
interface AppSectionContent
{
    /**
     * @param  string  $type  区画の種類（app_…）
     * @param  array<string, mixed>  $values  保存した欄の値
     */
    public function content(string $type, array $values): ?object;
}
