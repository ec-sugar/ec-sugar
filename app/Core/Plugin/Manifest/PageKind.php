<?php

declare(strict_types=1);

namespace App\Core\Plugin\Manifest;

/**
 * ショップの画面の種類（ADR 0034）。どの画面がどの種類かは核が決め、App は「どの種類で読みたいか」を宣言するだけ。
 * 個人情報の画面（注文の入力と確認・ご注文の状況・エラー・核の同意と公表の画面、知らない画面）には、App が何と言っても差し込まない
 */
enum PageKind: string
{
    case General = 'general';   // トップ・商品の一覧と詳細・カート・特商法の表記
    case Purchase = 'purchase'; // 購入の完了（注文の番号・金額・通貨・品の ID と数だけを渡す）
    case Private = 'private';   // 個人情報の画面（差し込まない。マニフェストにも書けない）

    /**
     * App が宣言できる種類（個人情報の画面は選べない）
     *
     * @return list<string>
     */
    public static function declarable(): array
    {
        return [self::General->value, self::Purchase->value];
    }
}
