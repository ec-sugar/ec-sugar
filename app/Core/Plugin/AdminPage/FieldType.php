<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

/**
 * App の画面の入力の欄の形（ADR 0051 の「作って決めたこと」）。核が決めた形だけで、App は足せない。
 *
 * - text: 1 行の文（改行と制御文字は断る）。長さの上限は App が決める（TEXT_MAX まで）
 * - textarea: 何行かの文（改行はよい。ほかの制御文字は断る。改行は \n にそろえる）。TEXTAREA_MAX まで
 * - toggle: 切り替え（真偽）
 * - choice: App が出した選択肢から 1 つ（OPTIONS_MAX 個まで。店の商品から選ぶ、など）
 * - media: ショップの写真から 1 枚（値は写真の ID。核が店の写真にあるかを確かめる。ADR 0072、App「お知らせ・ブログ」）
 * - blocks: 商品の説明と同じブロックの並び（ADR 0032 の 6 種類。核の ProductDescription が確かめる。係との間は確かめた JSON の文字。ADR 0072）
 * - datetime: 日時（係との間は UTC の ISO 8601 の文字 Y-m-d\TH:i:s\Z。画面は手元の時刻で入れる。ADR 0072）
 *
 * 文は HTML として扱わない（管理画面もショップも文字として出す）。なので App の設定の文（SettingSpec）のような「< や " を断る」決まりは無い
 */
enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Toggle = 'toggle';
    case Choice = 'choice';
    case Media = 'media';
    case Blocks = 'blocks';
    case DateTime = 'datetime';

    public const int TEXT_MAX = 200;

    public const int TEXTAREA_MAX = 5000;

    public const int OPTIONS_MAX = 500;
}
