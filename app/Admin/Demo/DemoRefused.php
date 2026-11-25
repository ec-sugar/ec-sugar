<?php

declare(strict_types=1);

namespace App\Admin\Demo;

use RuntimeException;

/**
 * デモの店を作る・戻すのを断った（印の無い店・別の扱い・スクリプトを流し終えていない。ADR 0086）
 */
final class DemoRefused extends RuntimeException {}
