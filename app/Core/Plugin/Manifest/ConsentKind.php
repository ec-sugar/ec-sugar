<?php

declare(strict_types=1);

namespace App\Core\Plugin\Manifest;

/**
 * お客さんの同意の種類（ADR 0034 の 4）。核が決めた種類だけ。App はマニフェストで自分がどれに当たるかを書き、
 * 核は、お客さんが同意した種類の App だけを差し込む。
 * App の区画の外の埋め込み（動画・地図。ADR 0040）は同意の種類にしない（2026-10-30 に「動画・地図の表示」を外した）。誰にでも最初から出し、
 * 守りは CSP の frame-src・sandbox・宛先の確かめで掛け、送る内容は外部送信に関する公表に載せる。
 * 前に「embeds=1」を持った同意の Cookie は、知らない種類として読み飛ばす（ConsentCookie::read）
 */
enum ConsentKind: string
{
    case Analytics = 'analytics'; // アクセス解析
    case Ads = 'ads';             // 広告の計測

    public function label(): string
    {
        return match ($this) {
            self::Analytics => 'アクセス解析',
            self::Ads => '広告の計測',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Analytics => 'どのページがよく見られているかを数え、ショップを良くするために使います。',
            self::Ads => '広告から来たお客さんの数や購入を数え、広告の効果を確認するために使います。',
        };
    }
}
