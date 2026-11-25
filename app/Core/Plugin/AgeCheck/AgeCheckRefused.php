<?php

declare(strict_types=1);

namespace App\Core\Plugin\AgeCheck;

use RuntimeException;

/**
 * 年齢の確かめが足りないので注文を確定しない（ADR 0077）。文はお客さんに見せてよい形
 */
final class AgeCheckRefused extends RuntimeException {}
