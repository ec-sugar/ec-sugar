<?php

declare(strict_types=1);

namespace EcSugar\PluginLine;

/**
 * LINE をつないだ結果（ADR 0070）
 */
enum LinkOutcome
{
    // つないだ
    case Linked;
    // もうこの会員につないである
    case Already;
    // この LINE はほかの会員につないである（1 つの LINE は 1 人だけ）
    case LineTaken;
    // この会員にはほかの LINE をつないである（1 人に 1 つだけ）
    case AccountTaken;
    // 会員でない・止めた・まとめられた台帳
    case NotMember;
}
