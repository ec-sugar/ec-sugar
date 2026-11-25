<?php

declare(strict_types=1);

namespace App\Admin\Agent;

/**
 * 反映の途中で決済代行に届かない・答えが分からない（ADR 0101）。返金の記録が残っていれば、核の見回りが同じ冪等キーで続きを進めるので、
 * 案は failed にせず「確かめ中」にする。文は見せてよい（核の PaymentUnavailableException の文）
 */
final class AgentWriteUncertain extends ToolInputException {}
