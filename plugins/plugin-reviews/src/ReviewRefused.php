<?php

declare(strict_types=1);

namespace EcSugar\PluginReviews;

use RuntimeException;

/**
 * お客さんがレビューを書けない（発送の前・注文の商品でない・もう書いた）。文はそのままお客さんに見せる（内部の事情を含めない）
 */
final class ReviewRefused extends RuntimeException {}
