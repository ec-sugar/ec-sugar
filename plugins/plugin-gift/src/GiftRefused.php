<?php

declare(strict_types=1);

namespace EcSugar\PluginGift;

use RuntimeException;

/**
 * 贈り物の操作を断った（受け取り待ちでない・期限が過ぎた・取り消しの途中など）。文はそのまま画面に出してよい（個人情報を入れない）
 */
final class GiftRefused extends RuntimeException {}
