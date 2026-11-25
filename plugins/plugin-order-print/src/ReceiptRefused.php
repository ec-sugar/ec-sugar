<?php

declare(strict_types=1);

namespace EcSugar\PluginOrderPrint;

use RuntimeException;

/**
 * 領収書を出せない注文（払い済みでない・取消・返金・0 円）
 */
final class ReceiptRefused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('この注文は、お支払いが済んでいないか取り消されているため、領収書を発行できません。');
    }
}
