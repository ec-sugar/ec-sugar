<?php

declare(strict_types=1);

namespace App\Core\Plugin\AdminPage;

/**
 * App の画面（ADR 0051）の値の形。核の部品が描くので、HTML・任意の URL・画像は無い。
 *
 * - text: 200 文字までの文
 * - number・money: 整数（money は円）
 * - date・datetime: 日付と日時（CarbonInterface）
 * - status: ['label' => 文, 'tone' => good|warn|bad|muted]
 * - link: 核の画面へのリンク ['label' => 文, 'kind' => customer|transaction|listing, 'id' => ULID]（URL は核の画面が作る）
 *
 * どの形も null は「—」（値が無い）
 */
enum CellType: string
{
    case Text = 'text';
    case Number = 'number';
    case Money = 'money';
    case Date = 'date';
    case DateTime = 'datetime';
    case Status = 'status';
    case Link = 'link';

    public const int TEXT_MAX = 200;

    public const array TONES = ['good', 'warn', 'bad', 'muted'];

    public const array LINK_KINDS = ['customer', 'transaction', 'listing'];
}
