<?php

declare(strict_types=1);

namespace App\Core\Plugin\Sections;

/**
 * App の区画の係（ADR 0040）。外のものを埋め込む区画（マニフェストの sections.{種類}.embed）を宣言した App は、
 * PluginDefinition の sectionHandler にこれを渡す（無ければ登録で止める）。
 *
 * 核は、欄の値を宣言（核の 7 種類の欄）どおりに確かめた後で problems を呼び、断る理由があれば保存しない。
 * 描くときは embed を呼び、返った URL が宣言した宛先に合うかを核がもう一度確かめる（合わなければ出さない）。
 * どちらも値を変えない・DB に触らない・外へつながない（決まった形の URL を作るだけ）
 */
interface AppSectionHandler
{
    /**
     * 欄の値をもう一段確かめる（保存のとき）。断るときは欄の名前 => 店の人に見せる理由（1 行の文）
     *
     * @param  string  $type  区画の種類（app_…）
     * @param  array<string, mixed>  $values  宣言どおりに確かめた欄の値（文は string|null）
     * @return array<string, string>
     */
    public function problems(string $type, array $values): array;

    /**
     * 埋め込むもの（描くとき）。値が空・読めない・設定が足りないときは null（核は区画を出さない。編集の画面では「入れると出ます」）
     *
     * @param  array<string, mixed>  $values  保存した欄の値
     * @param  array<string, bool|string>  $settings  App の設定のうち、ショップに渡してよい値（秘密の項目は入らない）
     */
    public function embed(string $type, array $values, array $settings): ?Embed;
}
