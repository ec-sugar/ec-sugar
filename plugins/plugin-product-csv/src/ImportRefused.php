<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv;

use App\Core\UserFacingException;
use DomainException;

/**
 * 取り込みを今は進められない（ほかの取り込みを反映している・確かめが済んでいない・止まった取り込みでない）。文はそのまま店の人に見せる（409）
 */
final class ImportRefused extends DomainException implements UserFacingException
{
    public function status(): int
    {
        return 409;
    }
}
