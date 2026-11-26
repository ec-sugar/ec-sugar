<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 使用状況の共有（ADR 0113）の形を、インストーラーと運用のスクリプトの文から確かめる。
 * 動かして確かめるのは docker/check-usage.sh（偽の curl・systemctl で。CI の check-deploy から）。
 *
 * - 週 1 回の timer を有効にするのは、共有する設定（USAGE_SHARE=yes）の時と `ec-sugar usage on` の時だけ
 * - 端末も設定ファイルの値も無ければ no（聞いていないのに送らない）。前から入っているサーバーには聞かない（勝手に on にしない）
 * - 送る項目は id・version・event・os・os_version・arch・shops の 7 つだけ（shops は店の数だけ。名前・ドメイン・ID は読まない）
 * - 送れなくても、インストールと timer を落とさない。送るのは https だけ
 */
final class InstallerUsageSharingTest extends TestCase
{
    public function test_the_usage_timer_is_enabled_only_when_sharing_is_on(): void
    {
        $enables = 0;
        foreach (['deploy/install.sh', 'deploy/lib/common.sh', 'deploy/ec-sugar'] as $file) {
            $lines = $this->lines($file);
            foreach ($lines as $index => $line) {
                if (preg_match('/systemctl\s+enable\b[^#]*ec-sugar-usage\.timer/', $line) !== 1) {
                    continue;
                }
                $enables++;
                $where = $file.':'.($index + 1);
                $function = $this->enclosingFunction($lines, $index);
                if ($function === 'usage_on') {
                    // 利用者が sudo ec-sugar usage on で選んだ時（同じ関数で設定ファイルを yes にする）
                    $body = $this->functionBody($file, 'usage_on');
                    $this->assertMatchesRegularExpression('/ec_kv_set "\$EC_ANSWERS" USAGE_SHARE yes/', $body, $where.': usage on は設定ファイルを yes にしてから有効にする');

                    continue;
                }
                // ほかは、いちばん近い上の if が共有する設定を見ている
                $condition = null;
                for ($i = $index - 1; $i >= 0; $i--) {
                    if (preg_match('/^\s*if\s/', $lines[$i]) === 1) {
                        $condition = $lines[$i];
                        break;
                    }
                }
                $this->assertNotNull($condition, $where.': timer を条件なしで有効にしている');
                $this->assertMatchesRegularExpression('/^\s*if ec_usage_enabled; then/', (string) $condition, $where.': 共有する設定の時だけ有効にする');
            }
        }
        $this->assertSame(2, $enables, 'timer を有効にする所は、unit を入れる所と usage on の 2 つだけ');

        // 共有する設定は yes ちょうど（大文字や空は off）
        $this->assertStringContainsString('ec_usage_enabled() { [ "$(ec_answer USAGE_SHARE 2> /dev/null || true)" = yes ]; }', $this->read('deploy/lib/common.sh'));
        // 共有しない設定では止めておく（前から入っているサーバーは、更新しても動き出さない）
        $this->assertMatchesRegularExpression('/else\s+systemctl disable --now ec-sugar-usage\.timer/', $this->functionBody('deploy/lib/common.sh', 'ec_install_usage_units'));
        $this->assertMatchesRegularExpression('/systemctl disable --now ec-sugar-usage\.timer/', $this->functionBody('deploy/ec-sugar', 'usage_off'));
        $this->assertMatchesRegularExpression('/ec_usage_id_drop/', $this->functionBody('deploy/ec-sugar', 'usage_off'), 'off にしたら ID を消す');
    }

    public function test_without_a_terminal_or_an_answer_the_installer_does_not_share(): void
    {
        $script = $this->read('deploy/install.sh');
        $keys = ' '.preg_replace('/.*\nEC_ANSWER_KEYS="([^"]*)".*/s', '$1', $script).' ';
        $this->assertStringContainsString(' USAGE_SHARE ', $keys, '設定ファイルの USAGE_SHARE を読む');

        $body = $this->functionBody('deploy/install.sh', 'ask_usage_share');
        // 前から入っているサーバーには聞かない（設定ファイルに無ければ off のまま）
        $installed = strpos($body, 'installed && return 0');
        $tty = strpos($body, 'if ! has_tty; then');
        $this->assertNotFalse($installed);
        $this->assertNotFalse($tty);
        $this->assertLessThan($tty, $installed, '入っているかを、端末を見る前に見る');
        // 端末が無ければ止めずに no を書く（ほかの項目の ask は止まる。この質問だけ違う）
        $this->assertMatchesRegularExpression('/if ! has_tty; then\s+ec_kv_set "\$EC_ANSWERS" USAGE_SHARE no\s/', $body);
        $this->assertStringNotContainsString('ec_die "設定ファイルに', $body);
        $this->assertStringNotContainsString('ask USAGE_SHARE', $script, 'USAGE_SHARE は、端末が無いと止まる ask で聞かない');
        // 既定は「はい」。質問の文に送るもの・送らないもの・止め方を書く
        $this->assertStringContainsString('read -r -p "   > [Y/n] "', $body);
        $this->assertMatchesRegularExpression('/"" \| \[Yy\] \| \[Yy\]\[Ee\]\[Ss\]\) val=yes/', $body);
        foreach (['送るもの:', '送らないもの:', '止め方:', 'sudo ec-sugar usage off'] as $phrase) {
            $this->assertStringContainsString($phrase, $body, "質問の文に「{$phrase}」");
        }
        // 入れた時の 1 回は、仕上げが通った後に。メールアドレスは集めず、メルマガの登録ページを案内する
        $this->assertMatchesRegularExpression('/\n    finish\n    send_install_usage\n    news_note\n\}/', $this->functionBody('deploy/install.sh', 'main'));
        $this->assertStringNotContainsString('read', $this->functionBody('deploy/install.sh', 'news_note'));
        $this->assertStringContainsString('EC_NEWS_URL=https://official.ec-sugar.jp/p/newsletter', $script);
    }

    public function test_only_the_decided_fields_are_sent(): void
    {
        $body = $this->functionBody('deploy/lib/common.sh', 'ec_usage_payload');
        $this->assertSame(1, preg_match("/printf '(\\{[^']*\\})'/", $body, $template), '送る JSON の雛形が 1 つ');
        preg_match_all('/"([a-z_]+)":"?%s"?/', $template[1], $fields);
        $this->assertSame(['id', 'version', 'event', 'os', 'os_version', 'arch', 'shops'], $fields[1]);
        // 雛形に固定の値も、決めた項目のほかも無い。店の数だけは引用符なし（数か null）
        $this->assertSame(7, substr_count($template[1], '%s'));
        $this->assertStringContainsString('"shops":%s}', $template[1]);
        $this->assertMatchesRegularExpression('/\[\[ "\$shops" =~ \^\(null\|0\|\[1-9\]\[0-9\]\{0,5\}\)\$ \]\] \|\| shops=null/', $body, '店の数は数か null のほかを入れない');

        // 店の数は中央の DB の tenants の行の数だけを読む（名前・ドメイン・ID を読まない）。上限で切り、読めなければ null
        $shops = $this->functionBody('deploy/lib/common.sh', 'ec_usage_shops');
        preg_match_all("/-Atc '([^']*)'/", $shops, $sql);
        $this->assertSame(['SELECT count(*) FROM tenants'], $sql[1]);
        $this->assertStringContainsString('printf null', $shops);
        $this->assertStringContainsString('[ "$n" -le "$EC_USAGE_MAX_SHOPS" ] || n="$EC_USAGE_MAX_SHOPS"', $shops);
        $this->assertStringContainsString('EC_USAGE_MAX_SHOPS=100000', $this->read('deploy/lib/common.sh'));
        $this->assertSame(1, preg_match('/case "\$event" in install \| weekly\) ;; \*\) return 1 ;; esac/', $body), 'event は install と weekly だけ');

        // 値はどれも決まった形に絞る（形が違えば unknown）。ホスト名・設定の値は読まない
        $lib = $this->read('deploy/lib/common.sh');
        $section = substr($lib, (int) strpos($lib, '# ---------- 使用状況の共有'), (int) strpos($lib, '# ---------- ホストの防火壁') - (int) strpos($lib, '# ---------- 使用状況の共有'));
        $this->assertNotSame('', $section);
        foreach (['hostname', 'ADMIN_HOST', 'SHOP_', 'OPERATOR_EMAIL', 'EC_ENV_FILE', 'ec_env_get', 'ip addr', 'ip -4'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $section, "使用状況の共有の部品が {$forbidden} を読まない");
        }
        foreach (['ec_usage_os', 'ec_usage_arch', 'ec_usage_version'] as $function) {
            $this->assertStringContainsString('printf unknown', $this->functionBody('deploy/lib/common.sh', $function), "{$function} は形が違えば unknown");
        }
        // ID は乱数から（ホストの名前・時刻から作らない）
        $this->assertStringContainsString('/dev/urandom', $this->functionBody('deploy/lib/common.sh', 'ec_usage_new_id'));
    }

    public function test_a_failed_send_never_breaks_the_installer_or_the_timer(): void
    {
        $send = $this->functionBody('deploy/lib/common.sh', 'ec_usage_send');
        // curl の失敗を握り、どの道も 0 で終わる。止める（ec_die）・失敗を返す道が無い
        $this->assertMatchesRegularExpression('/--data-binary @- "\$url" 2> \/dev\/null\)" \|\| true/', $send);
        $this->assertStringNotContainsString('ec_die', $send);
        $this->assertDoesNotMatchRegularExpression('/return [1-9]/', $send);
        $this->assertMatchesRegularExpression('/\n    return 0\n\}$/', $send);
        // https だけ・転送を追わない・10 秒で切る・root の ~/.curlrc を読まない
        $this->assertStringContainsString("curl -q -sS --proto '=https'", $send);
        $this->assertStringContainsString('--max-time "$EC_USAGE_TIMEOUT"', $send);
        $this->assertDoesNotMatchRegularExpression('/\s(-L|--location)\b/', $send);
        $lib = $this->read('deploy/lib/common.sh');
        $this->assertStringContainsString('EC_USAGE_TIMEOUT=10', $lib);
        $this->assertStringContainsString('EC_USAGE_URL_DEFAULT=https://ec-sugar.jp/usage/v1', $lib);
        $this->assertStringContainsString('ec_usage_valid_url() { [[ "$1" =~ ^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~/-]*)?$ ]]; }', $lib);
        // 共有しない設定では何もしない。隔離した複製環境からは送らない
        $this->assertMatchesRegularExpression('/^\s+ec_usage_enabled \|\| return 0\n\s+! ec_is_isolated \|\| return 0\n/m', $send);

        // インストーラーは送った結果で止まらない。timer の unit は週 1 回で、送れなくても失敗の扱いにならない
        $this->assertStringNotContainsString('ec_die', $this->functionBody('deploy/install.sh', 'send_install_usage'));
        $service = $this->read('deploy/systemd/ec-sugar-usage.service');
        $this->assertStringContainsString("ExecStart=/usr/local/bin/ec-sugar usage send\n", $service);
        $timer = $this->read('deploy/systemd/ec-sugar-usage.timer');
        foreach (["OnCalendar=weekly\n", "Persistent=true\n", "WantedBy=timers.target\n"] as $line) {
            $this->assertStringContainsString($line, $timer);
        }
        $this->assertMatchesRegularExpression('/^RandomizedDelaySec=\S+$/m', $timer);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    /** @return list<string> */
    private function lines(string $path): array
    {
        return file(dirname(__DIR__, 2).'/'.$path) ?: [];
    }

    /** 関数 $name の本体（「名前() {」の行から、行の頭の「}」まで） */
    private function functionBody(string $path, string $name): string
    {
        $script = $this->read($path);
        $this->assertSame(1, preg_match('/^'.preg_quote($name, '/').'\(\) \{\n.*?\n\}$/ms', $script, $match), "{$path} に {$name}() がある");

        return $match[0];
    }

    /** @param list<string> $lines */
    private function enclosingFunction(array $lines, int $index): ?string
    {
        for ($i = $index; $i >= 0; $i--) {
            if (preg_match('/^([a-z_]+)\(\) \{/', $lines[$i], $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }
}
