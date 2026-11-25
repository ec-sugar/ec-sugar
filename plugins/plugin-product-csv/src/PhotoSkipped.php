<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv;

use RuntimeException;

/**
 * CSV の写真の 1 枚を入れなかった（取れない・画像でない・10 枚を超えるなど）。メッセージはそのまま店の人に見せてよい文。
 * 商品の取り込みは止めない（その写真だけを飛ばし、行と理由を記録に残す）
 */
final class PhotoSkipped extends RuntimeException {}
