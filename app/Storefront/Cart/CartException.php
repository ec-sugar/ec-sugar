<?php

declare(strict_types=1);

namespace App\Storefront\Cart;

use RuntimeException;

/**
 * カートに入れられない（お客さんに見せる理由つき）
 */
final class CartException extends RuntimeException {}
