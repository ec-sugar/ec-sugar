<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 守りを核の部品の外で書いていないかを、コード（app/ と plugins/*\/src）から静的に探す（ADR 0081）。
 * 新しい機能が「用意された守りを呼び忘れる」「同じ守りを自分で書いて間違える」のを、人の目でなく機械で止める。
 *
 * - 回数の上限を数えるのは核の Throttle だけ。tooManyAttempts で見てから hit で数えると、同時の要求が上限を越える。
 *   数えそのものは AtomicRateLimiter（Laravel の RateLimiter と差し替え。AppServiceProvider）
 * - 署名つきの URL を作る・確かめるのは核の SecretLinks だけ（行き先の宣言・期限の上限・店の ID を部品が守る）
 * - 合い言葉を問い合わせに載せる道筋も SecretLinks::path で作る（行き先の宣言を確かめる）
 * - 参照元の決まり（Referrer-Policy）を書くのは防御ヘッダの部品だけ（秘密の道筋は経路の宣言で核が掛ける）
 */
final class SharedGuardsTest extends TestCase
{
    /**
     * 守りの部品と、その部品だけが使ってよい書き方
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const array RULES = [
        'tooManyAttempts' => ['/tooManyAttempts\(/', ['app/Core/RateLimit/Throttle.php']],
        'RateLimiter で数える' => ['/RateLimiter::(?:hit|increment|decrement|attempt)\(/', ['app/Core/RateLimit/Throttle.php']],
        'RateLimiter を直に持つ' => ['/^use Illuminate\\\\Cache\\\\RateLimiter\b/m', ['app/Core/RateLimit/Throttle.php', 'app/Core/RateLimit/AtomicRateLimiter.php', 'app/Providers/AppServiceProvider.php']],
        '署名つきの URL' => ['/\b(?:temporarySignedRoute|signedRoute|hasValidSignature|hasValidRelativeSignature|hasValidSignatureWhileIgnoring|hasCorrectSignature)\(/', ['app/Storefront/Access/SecretLinks.php']],
        'トークンを載せたパス' => ["/\\broute\\((?:(?!;).)*?'(?:token|signature|secret)'\\s*=>/s", ['app/Storefront/Access/SecretLinks.php']],
        '参照元の決まり' => ['/Referrer-Policy/', ['app/Http/Middleware/SecurityHeaders.php', 'app/Admin/Http/Middleware/AdminSecurityHeaders.php', 'app/Storefront/Http/Middleware/StorefrontSecurityHeaders.php']],
        // メールのヘッダーの改行落とし（同じ式が 28 か所に写っていて、1 か所でも緩めばヘッダーの注入になる）
        'メールのヘッダーの 1 行化' => ['/preg_replace\(\s*\x27\/\[\\\\r\\\\n\\\\t\]\+\/\x27/', ['app/Core/Mail/MailHeaderText.php']],
    ];

    public function test_guards_are_written_only_inside_their_shared_parts(): void
    {
        $found = [];

        foreach ($this->files() as $relative => $code) {
            array_push($found, ...self::violations($relative, $code));
        }

        $this->assertSame([], $found, '守りは核の部品（Throttle・SecretLinks・防御ヘッダ）を通す。docs/development/conventions.md の「セキュリティの段階」');
    }

    public function test_the_scan_catches_guards_written_by_hand(): void
    {
        // わざと部品の外で書いたコード（このテスト自体が効いていることの確かめ）
        $code = <<<'PHP'
            <?php
            use Illuminate\Cache\RateLimiter;
            if (RateLimiter::tooManyAttempts($key, 5)) { return; }
            RateLimiter::hit($key, 60);
            $url = URL::temporarySignedRoute('storefront.x', now()->addDay(), []);
            $ok = $request->hasValidRelativeSignature();
            $path = route('storefront.y', ['id' => $id,
                'token' => $token], false);
            $response->headers->set('Referrer-Policy', 'no-referrer');
            $subject = trim((string) preg_replace('/[\r\n\t]+/', ' ', $shopName));
            PHP;

        $found = implode("\n", self::violations('plugins/plugin-probe/src/Probe.php', $code));

        foreach (array_keys(self::RULES) as $rule) {
            $this->assertStringContainsString("plugins/plugin-probe/src/Probe.php: {$rule}", $found);
        }

        // 部品そのものの中は数えない・ふつうの道筋は数えない・説明（コメント）は数えない
        $this->assertSame([], self::violations('app/Core/RateLimit/Throttle.php', "<?php\nRateLimiter::increment(\$key);\n"));
        $this->assertSame([], self::violations('app/Core/Mail/MailHeaderText.php', "<?php\nreturn trim((string) preg_replace('/[\\r\\n\\t]+/', ' ', \$text));\n"));
        // ほかの制御文字の正規表現は数えない（CSV・ファイル名・エージェントの名前で別の集合を使っている）
        $this->assertSame([], self::violations('app/X.php', "<?php\n\$v = preg_replace('/[\\p{Cc}\\p{Cf}]+/u', ' ', \$v);\n"));
        $this->assertSame([], self::violations('app/X.php', "<?php\n\$a = route('storefront.product', ['listingId' => \$id], false);\n\$b = ['token' => \$t];\n// Referrer-Policy は核が掛ける\n"));
    }

    /**
     * 受付の段階の要求の大きさ（で入口を 1m に下げた）: 入口の nginx は本文をふだん 1m までにし、それより大きな本文を受けるのは
     * 決めた経路（管理画面の API の 12m・商品の CSV と写真の ZIP の 100m）だけで、それぞれの上限を越えない。
     * 新しく大きな本文を受ける経路を足す時は、ここの一覧と nginx の両方に理由つきで書く。
     * 分け方: ここは「どの場所が大きな本文を受けてよいか」（開発と本番の両方）。値の並びと、大きな口が管理画面のホストだけに開くことは docker/check-compose.sh が見る
     */
    public function test_only_the_listed_places_take_large_request_bodies(): void
    {
        $mb = 1024 * 1024;
        $large = [
            'location /api/ {' => 12 * $mb,
            'location ~ ^/api/tenants/[a-z0-9][a-z0-9_-]*/listings-csv/check$ {' => 100 * $mb,
        ];
        $root = dirname(__DIR__, 2);

        foreach (['docker/nginx/default.conf', 'docker/nginx/prod.conf'] as $file) {
            $lines = file("{$root}/{$file}", FILE_IGNORE_NEW_LINES);
            $this->assertIsArray($lines);
            $location = null;
            $serverLimit = false;

            foreach ($lines as $line) {
                $line = trim($line);

                if (str_starts_with($line, 'server ') || $line === 'server{') {
                    $location = null;
                }

                if (str_starts_with($line, 'location ')) {
                    $location = $line;
                }

                if (preg_match('/\Aclient_max_body_size\s+(\d+)([km]?);/i', $line, $m) !== 1) {
                    continue;
                }

                $bytes = (int) $m[1] * ['' => 1, 'k' => 1024, 'm' => $mb][strtolower($m[2])];
                $serverLimit = $serverLimit || ($location === null && $bytes === $mb);

                if ($bytes > $mb) {
                    $this->assertArrayHasKey((string) $location, $large, "{$file}: 決めた経路の外で大きな本文を受けている（{$line}）");
                    $this->assertLessThanOrEqual($large[(string) $location], $bytes, "{$file}: {$location} の本文の上限が決めた大きさを越えている（{$line}）");
                }
            }

            $this->assertTrue($serverLimit, "{$file}: サーバー全体の本文の上限（1m）が無い");
        }
    }

    /**
     * @return list<string>
     */
    private static function violations(string $relative, string $code): array
    {
        $found = [];
        $code = self::withoutComments($code);

        foreach (self::RULES as $rule => [$pattern, $allowed]) {
            if (! in_array($relative, $allowed, true) && preg_match($pattern, $code) === 1) {
                $found[] = "{$relative}: {$rule}";
            }
        }

        return $found;
    }

    /**
     * コメントを除いたコード（説明に書いた言葉で引っかけない）
     */
    private static function withoutComments(string $code): string
    {
        $kept = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    }

    /**
     * @return array<string, string> [リポジトリからの道筋 => 中身]
     */
    private function files(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach (['app', 'plugins'] as $top) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$top}", \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                $path = $file instanceof \SplFileInfo ? $file->getPathname() : '';
                $relative = substr($path, strlen($root) + 1);

                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php' && ! str_contains($path, '/vendor/') && ! str_contains($path, '/node_modules/')
                    && ($top === 'app' || preg_match('#\Aplugins/[^/]+/src/#', $relative) === 1)) {
                    $files[$relative] = (string) file_get_contents($path);
                }
            }
        }

        ksort($files);
        $this->assertNotEmpty($files);

        return $files;
    }
}
