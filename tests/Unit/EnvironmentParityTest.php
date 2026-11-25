<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\DevDoctor;

/**
 * テストを走らせている PHP が、本番のイメージ（docker/Dockerfile）と同じ形かを確かめる。
 * 拡張が足りないと、その拡張に頼る部分（例: テナントの切り替えで Redis の接頭辞を変える処理）が外れた形でテストが通ってしまう。
 * 拡張の一覧と版の決まりは、開発の環境の診断（`make doctor`）と同じもの（Tests\Support\DevDoctor）を使う。
 */
final class EnvironmentParityTest extends TestCase
{
    public function test_php_has_the_same_extensions_as_the_production_image(): void
    {
        $missing = DevDoctor::missingExtensions();

        $this->assertSame([], $missing, '本番のイメージと同じ拡張を入れる（手元の Mac: pecl install redis など。手順は CLAUDE.md の「開発コマンド」）');
    }

    public function test_php_is_not_older_than_the_version_composer_targets(): void
    {
        $target = DevDoctor::targetPhpVersion(dirname(__DIR__, 2));

        $this->assertNotSame('', $target, 'composer.json の config.platform.php が無い');
        $this->assertTrue(version_compare(PHP_VERSION, $target, '>='), "PHP {$target} 以上にする（今は ".PHP_VERSION.'。手元の Mac: brew upgrade php）');
    }

    public function test_the_extension_list_matches_the_dockerfile(): void
    {
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 2).'/docker/Dockerfile');

        foreach (DevDoctor::EXTENSIONS as $extension) {
            $this->assertMatchesRegularExpression('/(docker-php-ext-install|pecl install)[^\n]*\b'.$extension.'\b/', $dockerfile, "Dockerfile に {$extension} が無い（この一覧と Dockerfile をそろえる）");
        }
    }
}
