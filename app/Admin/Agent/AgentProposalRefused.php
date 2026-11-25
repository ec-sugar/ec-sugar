<?php

declare(strict_types=1);

namespace App\Admin\Agent;

use App\Core\UserFacingException;
use RuntimeException;

/**
 * 案を承認・却下できない（もう決まった・期限が過ぎた・段階が足りない。ADR 0101）。文はそのまま管理画面に見せてよい
 */
final class AgentProposalRefused extends RuntimeException implements UserFacingException
{
    public function __construct(string $message, private readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
