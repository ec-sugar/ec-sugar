<?php

declare(strict_types=1);

namespace App\Admin\Agent;

use InvalidArgumentException;

/**
 * 道具の引数が違う（エージェントに見せてよい文だけを持つ。ADR 0100）。ライブラリの中の例外の文は外へ返さない
 */
class ToolInputException extends InvalidArgumentException {}
