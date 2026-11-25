<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 店が送るメールのクラス（Mailable）は、どれもメールの記録の札（RecordsMail）を付ける（ADR 0095）。
 * 付け忘れると、送ったことも送れなかったことも店の人に見えない。App の種類の頭は App の名前（plugins/plugin-<名前>）。
 * 札を付けないのは運営へのメールだけ（店の記録でなく、運営のログインの守り・壊れた知らせ）
 */
final class MailRecordCoverageTest extends TestCase
{
    /** 店の文脈の外で運営へ送るメール（店の記録に入れない） */
    private const array OPERATOR_MAILS = [
        'app/Admin/Mail/OperationsAlertMail.php',
        'app/Admin/Mail/OperatorSecurityMail.php',
    ];

    public function test_every_shop_mail_carries_the_record_tag_with_its_app_name(): void
    {
        $root = dirname(__DIR__, 2);
        $missing = [];
        $wrongPrefix = [];
        $seen = 0;

        foreach (['app', 'plugins'] as $top) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$top}")) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = substr($file->getPathname(), strlen($root) + 1);
                $code = (string) file_get_contents($file->getPathname());

                if (str_contains($path, '/vendor/') || preg_match('/\bextends Mailable\b/', $code) !== 1 || in_array($path, self::OPERATOR_MAILS, true)) {
                    continue;
                }

                $seen++;

                if (! str_contains($code, 'use RecordsMail;') || ! str_contains($code, 'function recordKind(')) {
                    $missing[] = $path;

                    continue;
                }

                // App のメールの種類の頭は App の名前（画面が App の名前で出す）。核のメールは core
                $prefix = preg_match('#\Aplugins/plugin-([a-z0-9-]+)/#', $path, $m) === 1 ? $m[1] : 'core';

                if (preg_match_all("/'([a-z][a-z0-9-]*)\\./", substr($code, (int) strpos($code, 'function recordKind(')), $kinds) < 1
                    || array_diff(array_unique($kinds[1]), [$prefix]) !== []) {
                    $wrongPrefix[] = "{$path}（頭は {$prefix}）";
                }
            }
        }

        $this->assertGreaterThan(15, $seen, '店のメールのクラスを見つけられていない');
        $this->assertSame([], $missing, 'メールの記録の札（use RecordsMail と recordKind）が無いメール');
        $this->assertSame([], $wrongPrefix, '種類の頭が App の名前（核は core）でないメール');

        foreach (self::OPERATOR_MAILS as $path) {
            $this->assertFileExists("{$root}/{$path}", '要らなくなった例外は一覧から消す');
        }
    }
}
