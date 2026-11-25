<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 自動の更新の時計は、答えの AUTO_UPDATE=yes の時だけ入れる。既定は公開版の形（CHANNEL=release。人の鍵で署名したタグと CI の署名が通るものだけを
 * 取り込む。ADR 0099）だけ yes で、開発の形（CHANNEL=source）は本番もステージングも no（枝の先を人の署名なしに root で取り込むので、
 * 取得元が乗っ取られた時に全部のサーバーへ届く。高）。開発の形の自動の更新は、答えで入れても早送りのコミットだけ（deploy/ec-sugar）
 */
final class InstallerAutoUpdateTest extends TestCase
{
    public function test_the_update_timer_is_enabled_only_when_auto_update_is_yes(): void
    {
        $lines = file(dirname(__DIR__, 2).'/deploy/install.sh') ?: [];
        $enables = array_keys(array_filter($lines, static fn (string $line): bool => preg_match('/systemctl\s+enable\b[^#]*ec-sugar-update\.timer/', $line) === 1));

        $this->assertNotSame([], $enables, '自動の更新の時計を入れる行が見つからない');

        foreach ($enables as $index) {
            // いちばん近い上の if が AUTO_UPDATE = yes を見ている
            $condition = null;
            for ($i = $index - 1; $i >= 0; $i--) {
                if (preg_match('/^\s*if\s/', $lines[$i]) === 1) {
                    $condition = $lines[$i];
                    break;
                }
            }

            $this->assertNotNull($condition, 'install.sh:'.($index + 1).': 自動の更新の時計を条件なしで入れている');
            $this->assertMatchesRegularExpression('/ec_answer AUTO_UPDATE\)"\s*=\s*yes/', (string) $condition, 'install.sh:'.($index + 1).': AUTO_UPDATE=yes の時だけ入れる');
        }
    }

    public function test_auto_update_defaults_to_yes_only_on_the_release_channel(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root.'/deploy/install.sh');

        $this->assertMatchesRegularExpression('/default_answer AUTO_UPDATE "\$\(\[ "\$\(ec_channel\)" = release \] && echo yes \|\| echo no\)"/', $script);
        $this->assertDoesNotMatchRegularExpression('/default_answer AUTO_UPDATE[^\n]*staging/', $script, 'ステージングでも開発の形の自動の更新を既定で入れない');
        $keys = ' '.preg_replace('/.*EC_ANSWER_KEYS="([^"]*)".*/s', '$1', $script).' ';
        foreach (['AUTO_UPDATE', 'CHANNEL', 'TRUST_SIGNERS_FILE', 'TRUST_FINGERPRINTS'] as $key) {
            $this->assertStringContainsString(" {$key} ", $keys, "答えの鍵の一覧に {$key} がある（答えのファイルで渡せる）");
        }
        // 開発の形の自動の更新は、答えで入れた時だけ動き、早送りのコミットだけを取り込む
        $tool = (string) file_get_contents($root.'/deploy/ec-sugar');
        $this->assertMatchesRegularExpression('/"\$auto" -eq 1 \] && \[ "\$channel" = source \] && \[ "\$\(ec_answer AUTO_UPDATE \|\| true\)" != yes \]/', $tool);
        $this->assertMatchesRegularExpression('/if ! ec_git merge-base --is-ancestor "\$cur" "\$target"; then/', $tool);
        // 更新の形の既定は木で決まる: 公開用の木にだけ deploy/channel.default（release。release/public/ から書き出す）があり、開発用の木には無い（source）
        if (is_dir($root.'/release/public')) {
            $this->assertSame("release\n", (string) file_get_contents($root.'/release/public/deploy/channel.default'));
            $this->assertFileDoesNotExist($root.'/deploy/channel.default', '開発用の木は開発の形（今の本番を公開版の形に替えない）');
        } else {
            $this->assertSame("release\n", (string) file_get_contents($root.'/deploy/channel.default'));
        }
        // 入れない時は、前に入れていた時計も止める
        $this->assertMatchesRegularExpression('/systemctl disable --now ec-sugar-update\.timer/', $script);
    }
}
