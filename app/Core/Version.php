<?php

declare(strict_types=1);

namespace App\Core;

/**
 * 核のバージョン。プラグインとテーマは、対応する核のバージョンの範囲をマニフェスト（composer.json の extra.ec-sugar.core）に書く（CONCEPT 6.2・7.3）。
 * 拡張点やテーマ API の約束を変えたら上げる
 */
final class Version
{
    public const string CORE = '1.0.0';
}
