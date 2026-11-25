<?php

declare(strict_types=1);

namespace EcSugar\PluginContact;

use RuntimeException;

/**
 * お問い合わせを今は受け付けられない（回数の上限など）。文はそのままお客さんに出す
 */
final class ContactRefused extends RuntimeException {}
