<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads;

use RuntimeException;

/**
 * 今はダウンロードできない（期限切れ・回数を使い切った・止めた・注文の取消と返金・ファイルを取り下げた）。文はお客さんに出す決まった言葉
 */
final class DownloadRefused extends RuntimeException {}
