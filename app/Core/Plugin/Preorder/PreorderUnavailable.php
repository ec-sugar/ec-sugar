<?php

declare(strict_types=1);

namespace App\Core\Plugin\Preorder;

use RuntimeException;

/**
 * 予約を受けられない（上限に届いた・受付を終えた・発売日になった・予約販売の App を使うのをやめた。ADR 0073）。文はお客さんに見せてよいもの
 */
final class PreorderUnavailable extends RuntimeException
{
    public function __construct(string $message = '予約の受付が終了した商品があります。カートを確認してください。')
    {
        parent::__construct($message);
    }
}
