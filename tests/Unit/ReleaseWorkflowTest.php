<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 公開用のリポジトリの CI の形（ADR 0099）。配る道（release.yml）とふだんの検査（ci.yml）が、
 * 外部の action を SHA で固定し、権限を最小にし、タグの形を確かめ、外の写し（fork）のプルリクエストに権限と秘密を渡さないかを見張る。
 *
 * 開発用の木では release/public/.github/workflows/（書き出しで根に重なる）、公開の木では .github/workflows/ を読む
 * （開発用の .github/ は公開に出ない別の CI なので見ない）。YAML の部品は入れていないので、決まった書き方を行で読む。DB もアプリも使わない
 */
final class ReleaseWorkflowTest extends TestCase
{
    /** 署名の身元（入れる側が確かめる値。ADR 0099 の約束） */
    private const IDENTITY = 'https://github.com/ec-sugar/ec-sugar/.github/workflows/release.yml@refs/tags/';

    private const ISSUER = 'https://token.actions.githubusercontent.com';

    /** release.yml の仕事ごとに許す権限（これより多くも少なくもしない） */
    private const RELEASE_PERMISSIONS = [
        'tag' => ['contents' => 'read'],
        'tests' => ['contents' => 'read'],
        'images' => ['contents' => 'read', 'packages' => 'write', 'id-token' => 'write'],
        'notes' => ['contents' => 'write'],
    ];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(__DIR__, 2);
        $this->dir = is_dir("{$root}/release/public/.github/workflows")
            ? "{$root}/release/public/.github/workflows"
            : "{$root}/.github/workflows";
    }

    public function test_the_public_workflows_are_the_release_and_the_checks(): void
    {
        $names = array_map('basename', glob("{$this->dir}/*.yml") ?: []);
        sort($names);
        $this->assertSame(['ci.yml', 'release.yml'], $names);
    }

    public function test_every_action_is_pinned_to_a_commit_sha_with_the_version_noted(): void
    {
        foreach ($this->workflows() as $name => $yaml) {
            $count = 0;
            foreach (explode("\n", $yaml) as $i => $line) {
                if (! preg_match('/^\s*(?:-\s+)?uses:\s*(\S+)(.*)$/', $line, $m)) {
                    continue;
                }
                $count++;
                $where = "{$name}:".($i + 1);
                if (str_starts_with($m[1], './.github/workflows/')) {
                    continue; // 同じリポジトリの中の workflow（同じコミットのものが動く）
                }
                $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.\/-]+@[0-9a-f]{40}$/', $m[1], "{$where}: action はコミットの SHA で固定する（タグの名前で使わない）");
                $this->assertMatchesRegularExpression('/^\s+#\s*v?\d+\.\d+\.\d+/', $m[2], "{$where}: SHA の横に何のバージョンかを注記する");
            }
            $this->assertGreaterThan(0, $count, "{$name} に action が無い（読み方が崩れた）");

            // 取り出し（checkout）の認証を作業の場所に残さない
            $this->assertSame(
                substr_count($this->code($yaml), 'uses: actions/checkout@'),
                substr_count($this->code($yaml), 'persist-credentials: false'),
                "{$name}: actions/checkout には persist-credentials: false を付ける",
            );
        }

        // cosign も版を固定して入れる（入れる action が既定で入れる版に任せない）
        $this->assertMatchesRegularExpression('/^\s+cosign-release:\s*v\d+\.\d+\.\d+\s*$/m', $this->workflows()['release.yml']);
    }

    public function test_the_release_grants_each_job_only_the_permissions_it_needs(): void
    {
        $yaml = $this->workflows()['release.yml'];
        $this->assertMatchesRegularExpression('/^permissions:\s*\{\}\s*$/m', $this->top($yaml), '既定は何も許さない');
        $this->assertDoesNotMatchRegularExpression('/write-all|read-all/', $yaml);

        $jobs = $this->jobs($yaml);
        $this->assertSame(array_keys(self::RELEASE_PERMISSIONS), array_keys($jobs));
        foreach (self::RELEASE_PERMISSIONS as $job => $expected) {
            $this->assertSame($expected, $this->permissions($jobs[$job]), "release.yml の仕事 {$job} の権限");
        }
        // 写し（fork）では署名もリリースもしない
        foreach (['images', 'notes'] as $job) {
            $this->assertStringContainsString("if: github.repository == 'ec-sugar/ec-sugar'", $jobs[$job], "仕事 {$job}");
        }
    }

    public function test_the_release_runs_only_on_semver_tags_and_stops_on_other_shapes(): void
    {
        $yaml = $this->workflows()['release.yml'];
        $on = $this->block($this->top($yaml), 'on');
        $this->assertSame("on:\n  push:\n    tags: ['v*.*.*']", trim($on), 'タグの push だけで動く（ブランチ・手動の実行・プルリクエストからは配らない）');

        // 形の確かめの正規表現を取り出して、入れてよいタグと断るタグで試す
        $this->assertSame(1, preg_match("/^\s+semver='([^']+)'$/m", $yaml, $m), 'タグの形を確認する手順がある');
        $semver = '/'.str_replace('/', '\/', $m[1]).'/';
        foreach (['v0.1.0', 'v1.2.3', 'v10.20.30', 'v1.0.0-rc.1', 'v1.0.0-alpha', 'v1.0.0-0.3.7', 'v1.0.0-x-y.1'] as $ok) {
            $this->assertSame(1, preg_match($semver, $ok), "{$ok} は通す");
        }
        foreach (['1.2.3', 'v1.2', 'v1.2.3.4', 'v01.2.3', 'v1.02.3', 'v1.2.3-', 'v1.2.3-01', 'v1.2.3+build.1', 'v1.2.3-rc..1', 'v1.2.3 ', 'vv1.2.3', 'v1.2.3-rc_1'] as $ng) {
            $this->assertSame(0, preg_match($semver, $ng), "{$ng} は断る");
        }
        $this->assertStringContainsString('if ! [[ "$tag" =~ $semver ]]', $yaml, '形が違えば止める');
    }

    public function test_the_images_are_signed_by_digest_with_the_promised_identity(): void
    {
        $images = $this->jobs($this->workflows()['release.yml'])['images'];

        $this->assertStringContainsString('APP_IMAGE: ghcr.io/ec-sugar/ec-sugar', $this->workflows()['release.yml']);
        $this->assertStringContainsString('CADDY_IMAGE: ghcr.io/ec-sugar/ec-sugar-caddy', $this->workflows()['release.yml']);
        // 署名はタグでなく digest に付ける
        $this->assertStringContainsString('APP_REF: ${{ env.APP_IMAGE }}@${{ steps.digests.outputs.app }}', $images);
        $this->assertStringContainsString('CADDY_REF: ${{ env.CADDY_IMAGE }}@${{ steps.digests.outputs.caddy }}', $images);
        $this->assertStringContainsString('cosign sign --yes "$ref"', $images);
        // 付けた後に、入れる側と同じ身元で確かめ直す
        $this->assertStringContainsString('identity="'.self::IDENTITY.'${TAG}"', $images);
        $this->assertStringContainsString('--certificate-oidc-issuer '.self::ISSUER, $images);
        $this->assertStringContainsString('--certificate-github-workflow-sha "$GITHUB_SHA"', $images);
        // 身元はこのファイルの名前で決まる
        $this->assertFileExists("{$this->dir}/release.yml");
        // 署名の前に、置いたイメージそのもので書き込みの境界を確かめる
        $this->assertLessThan(strpos($images, 'cosign sign'), strpos($images, 'bash docker/check-image.sh "$APP_REF"'));
    }

    public function test_the_workflows_give_forked_pull_requests_no_write_access_and_no_secrets(): void
    {
        foreach ($this->workflows() as $name => $yaml) {
            // 外のコードに書ける権限と秘密を渡す引き金を使わない
            $this->assertDoesNotMatchRegularExpression('/\b(pull_request_target|workflow_run)\b/', $this->code($yaml), $name);
            // GitHub の走らせ役だけ（外のコードを自前の機械で動かさない）
            preg_match_all('/^\s+runs-on:\s*(.+)$/m', $yaml, $m);
            foreach ($m[1] as $runsOn) {
                $this->assertSame('ubuntu-24.04', trim($runsOn), "{$name}: runs-on");
            }
        }

        $ci = $this->workflows()['ci.yml'];
        $this->assertMatchesRegularExpression('/^permissions:\n  contents: read\n/m', $this->top($ci));
        $this->assertDoesNotMatchRegularExpression('/:\s*write\b/', $ci, 'ふだんの検査はどこにも書かない');
        $this->assertStringNotContainsString('secrets.', $ci, 'ふだんの検査は秘密を使わない');
        $this->assertMatchesRegularExpression('/^  pull_request:\s*$/m', $this->block($this->top($ci), 'on'));
        $this->assertMatchesRegularExpression('/^  workflow_call:\s*$/m', $this->block($this->top($ci), 'on'), '配る道が配る前に呼ぶ');
    }

    public function test_the_checks_run_the_tree_independent_scripts_like_the_development_ci(): void
    {
        // ふだんの検査は、PHP の検査（check）に加えて、秘密の検査・木によらないスクリプト・管理画面の依存と組み立てを流す（e2e は 1.0 の後）
        $jobs = $this->jobs($this->workflows()['ci.yml']);
        $this->assertSame(['check', 'secrets', 'scripts', 'front'], array_keys($jobs));
        foreach ($jobs as $name => $job) {
            $this->assertSame(['contents' => 'read'], $this->permissions($job), "ci.yml の仕事 {$name} の権限");
        }

        // gitleaks は全部の履歴を、公式のイメージをバージョンと digest で固定して（action はライセンスの秘密を求めるので使わない）
        $this->assertStringContainsString('fetch-depth: 0', $jobs['secrets']);
        $this->assertMatchesRegularExpression('/ghcr\.io\/gitleaks\/gitleaks:v\d+\.\d+\.\d+@sha256:[0-9a-f]{64}\s/', $jobs['secrets']);
        $this->assertStringContainsString('--exit-code 1', $jobs['secrets']);

        foreach (['check-compose.sh', 'check-caddy-sni.sh', 'check-deploy.sh', 'check-release.sh'] as $script) {
            $this->assertStringContainsString("run: bash docker/{$script}", $jobs['scripts'], "scripts の仕事が {$script} を流す");
        }
        foreach (['npm ci --ignore-scripts', 'ops/dev/npm-audit.sh', 'npm audit signatures', 'run: npm run build'] as $step) {
            $this->assertStringContainsString($step, $jobs['front'], "front の仕事が {$step} を流す");
        }
    }

    /** @return array<string, string> ファイルの名前 => 中身 */
    private function workflows(): array
    {
        $out = [];
        foreach (['ci.yml', 'release.yml'] as $name) {
            $path = "{$this->dir}/{$name}";
            $this->assertFileExists($path);
            $out[$name] = str_replace("\r\n", "\n", (string) file_get_contents($path));
        }

        return $out;
    }

    /** 行まるごとの注記（#）を除いた中身（注記の中の言葉に引っかからないように。行の末尾の注記は残す） */
    private function code(string $yaml): string
    {
        return (string) preg_replace('/^\s*#.*\n/m', '', $yaml);
    }

    /** jobs: より前（名前・引き金・既定の権限） */
    private function top(string $yaml): string
    {
        $pos = strpos($yaml, "\njobs:\n");
        $this->assertNotFalse($pos, 'jobs: がある');

        return substr($yaml, 0, $pos + 1);
    }

    /**
     * jobs: の下の仕事ごとの中身（字下げ 2 の名前の行から次の名前の行の手前まで）
     *
     * @return array<string, string>
     */
    private function jobs(string $yaml): array
    {
        $pos = strpos($yaml, "\njobs:\n");
        $this->assertNotFalse($pos);
        $parts = preg_split('/^  ([A-Za-z0-9_-]+):\s*$/m', substr($yaml, $pos + 7), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $jobs = [];
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $jobs[$parts[$i]] = $parts[$i + 1];
        }

        return $jobs;
    }

    /** 字下げの無い「名前:」の行から、次の字下げの無い行の手前まで */
    private function block(string $text, string $key): string
    {
        $this->assertSame(1, preg_match('/^'.preg_quote($key, '/').':.*\n(?:(?: .*)?\n)*/m', $text, $m), "{$key}: がある");

        return $m[0];
    }

    /**
     * 仕事の permissions:（字下げ 4）の中身
     *
     * @return array<string, string>
     */
    private function permissions(string $job): array
    {
        if (preg_match('/^    permissions:\s*\{\}\s*$/m', $job)) {
            return [];
        }
        $this->assertSame(1, preg_match('/^    permissions:\s*\n((?:      [a-z-]+:\s*\S+\s*\n)+)/m', $job, $m), '仕事ごとに permissions: を書く');
        preg_match_all('/^      ([a-z-]+):\s*(\S+)/m', $m[1], $pairs, PREG_SET_ORDER);
        $out = [];
        foreach ($pairs as $pair) {
            $out[$pair[1]] = $pair[2];
        }

        return $out;
    }
}
