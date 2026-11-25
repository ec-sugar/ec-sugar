<?php

declare(strict_types=1);

namespace Tests\Unit;

use EcSugar\Egress\Counters;
use EcSugar\Egress\Policy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/docker/egress/proxy.php';

/**
 * 外への通信の出口の代理の決まり（ADR 0102。docker/egress/proxy.php の Policy と Counters）。網に触らない分をここで、
 * 網を通した確かめ（許した名前だけ届く・DNS で抜けない・メールは決めた宛先だけ）は docker/check-egress.sh で見る
 */
final class EgressPolicyTest extends TestCase
{
    public function test_the_allow_list_file_is_read_without_breaking_on_japanese_comments(): void
    {
        $list = Policy::parseList((string) file_get_contents(dirname(__DIR__, 2).'/docker/egress/allow.txt'), $skipped);

        // アプリが外へ出る所（app/・plugins/ の Http と Stripe の部品）。足したら allow.txt にも足す
        $this->assertSame(['api.stripe.com', 'api.line.me', 'hooks.slack.com', 'zipcloud.ibsnet.co.jp', 'unsplash.com', 'images.unsplash.com'], $list);
        $this->assertSame([], $skipped, 'コメントの日本語を項目と取り違えない（\R・\s は UTF-8 の途中のバイトに当たる）');
    }

    public function test_entries_are_normalised_and_bad_ones_are_skipped(): void
    {
        $list = Policy::parseList("API.Example.com. ,*.cdn.example.net\tbad_name 10.0.0.1 # コメント a.example.org\n*. *.\n*.com 10.0.0 1.2 host.123 *.co.jp", $skipped);

        $this->assertSame(['api.example.com', '*.cdn.example.net', '*.co.jp'], $list);
        // 住所とその欠けたもの・終わりが数の名前・ラベルが 1 つの *.（*.com）は捨てる
        $this->assertSame(['bad_name', '10.0.0.1', '*', '*', '*.com', '10.0.0', '1.2', 'host.123'], $skipped);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int, 3: string, 4: string}>
     */
    public static function decisions(): iterable
    {
        yield '一覧にある名前' => ['enforce', 'api.stripe.com', 443, 'allow', 'listed'];
        yield '一覧にある名前（見張り）' => ['watch', 'api.stripe.com', 443, 'allow', 'listed'];
        yield '下の名前（*.）' => ['enforce', 'a.cdn.example.net', 443, 'allow', 'listed'];
        yield '*. はその名前そのものを含まない' => ['enforce', 'cdn.example.net', 443, 'refuse', 'unlisted'];
        yield '似た名前（後ろが同じだけ）' => ['enforce', 'evilapi.stripe.com.attacker.example', 443, 'refuse', 'unlisted'];
        yield '一覧に無い名前' => ['enforce', 'attacker.example', 443, 'refuse', 'unlisted'];
        yield '一覧に無い名前（見張り）' => ['watch', 'attacker.example', 443, 'unlisted', 'unlisted'];
        yield '443 以外のポート' => ['enforce', 'api.stripe.com', 25, 'refuse', 'port'];
        yield '443 以外のポート（見張り）' => ['watch', 'api.stripe.com', 8443, 'unlisted', 'port'];
        yield '外の住所の直書き' => ['enforce', '1.1.1.1', 443, 'refuse', 'ip-literal'];
        yield '外の住所の直書き（見張り）' => ['watch', '1.1.1.1', 443, 'unlisted', 'ip-literal'];
        yield '内向きの住所（見張りでも断る）' => ['watch', '192.168.5.6', 443, 'refuse', 'internal-address'];
        yield 'クラウドの設定の口（見張りでも断る）' => ['watch', '169.254.169.254', 443, 'refuse', 'internal-address'];
        yield 'Docker の網（見張りでも断る）' => ['watch', '172.30.255.226', 443, 'refuse', 'internal-address'];
        yield '名前の形でない' => ['watch', 'bad_name', 443, 'refuse', 'bad-host'];
    }

    #[DataProvider('decisions')]
    public function test_decide(string $mode, string $host, int $port, string $decision, string $reason): void
    {
        $policy = new Policy(Policy::mode($mode), ['api.stripe.com', '*.cdn.example.net']);

        $this->assertSame([$decision, $reason], $policy->decide($host, $port));
    }

    public function test_unknown_modes_fall_back_to_watch(): void
    {
        $this->assertSame('enforce', Policy::mode(' Enforce '));
        $this->assertSame('watch', Policy::mode('watch'));
        $this->assertSame('watch', Policy::mode('block'), '知らない値は止めない方');
        $this->assertSame('watch', Policy::mode(''));
    }

    public function test_resolved_addresses_must_all_be_public_unless_the_name_is_allowed_inside(): void
    {
        $policy = new Policy('enforce', ['api.stripe.com', 'dest.check.test'], ['dest.check.test']);

        $this->assertTrue($policy->addressesOk('api.stripe.com', ['54.187.174.169']));
        $this->assertFalse($policy->addressesOk('api.stripe.com', ['54.187.174.169', '10.0.0.8']), '1 つでも内向きなら断る（DNS で中へ向けさせない）');
        $this->assertFalse($policy->addressesOk('api.stripe.com', ['127.0.0.1']));
        $this->assertFalse($policy->addressesOk('api.stripe.com', []), '引けなければ通さない');
        $this->assertTrue($policy->addressesOk('dest.check.test', ['172.20.0.5']), '確認の偽の宛先だけは内向きでもよい');
    }

    /**
     * @return iterable<string, array{0: string, 1: array{host: string, port: int}|null}>
     */
    public static function connects(): iterable
    {
        yield 'ふつう' => ["CONNECT api.stripe.com:443 HTTP/1.1\r\nHost: api.stripe.com:443", ['host' => 'api.stripe.com', 'port' => 443]];
        yield '大文字と終わりの点' => ['CONNECT API.Stripe.com.:443 HTTP/1.0', ['host' => 'api.stripe.com', 'port' => 443]];
        yield 'GET（平文の代理）' => ['GET http://example.com/ HTTP/1.1', null];
        yield 'IPv6 の直書き' => ['CONNECT [::1]:443 HTTP/1.1', null];
        yield '利用者名つき' => ['CONNECT user@api.stripe.com:443 HTTP/1.1', null];
        yield 'ポートなし' => ['CONNECT api.stripe.com HTTP/1.1', null];
        yield 'ポートが大きすぎる' => ['CONNECT api.stripe.com:70000 HTTP/1.1', null];
        yield '名前に空白' => ['CONNECT api stripe.com:443 HTTP/1.1', null];
        yield '下線' => ['CONNECT bad_name.example:443 HTTP/1.1', null];
    }

    /**
     * @param  array{host: string, port: int}|null  $expected
     */
    #[DataProvider('connects')]
    public function test_parse_connect(string $head, ?array $expected): void
    {
        $this->assertSame($expected, Policy::parseConnect($head));
    }

    /**
     * 名前を引いた後の決め（Proxy::handleConnect が使う道そのもの）: 内向きの住所へ引ける名前は、一覧にあっても見張りの形でも断る
     */
    public function test_route_checks_the_resolved_addresses_after_the_name(): void
    {
        $dns = ['api.stripe.com' => ['54.187.174.169', '54.187.174.170'], 'inside.example.com' => ['10.0.0.8'], 'mixed.example.com' => ['54.187.174.169', '192.168.5.6']];
        $resolve = static fn (string $host): array => $dns[$host] ?? [];

        foreach (['enforce', 'watch'] as $mode) {
            $policy = new Policy($mode, ['api.stripe.com', 'inside.example.com', 'mixed.example.com', 'none.example.com']);

            $this->assertSame(['allow', 'listed', ['54.187.174.169', '54.187.174.170']], $policy->route('api.stripe.com', 443, $resolve), '引いた順につなぐ');
            $this->assertSame(['refuse', 'internal-address', []], $policy->route('inside.example.com', 443, $resolve), "{$mode}: 内向きに引ける名前");
            $this->assertSame(['refuse', 'internal-address', []], $policy->route('mixed.example.com', 443, $resolve), "{$mode}: 1 つでも内向き");
            $this->assertSame(['failed', 'dns', []], $policy->route('none.example.com', 443, $resolve));
        }

        $this->assertSame(['refuse', 'unlisted', []], (new Policy('enforce', []))->route('attacker.example', 443, static fn (): array => throw new \LogicException('断る名前は引かない')));
        $this->assertSame(['unlisted', 'unlisted', ['93.184.216.34']], (new Policy('watch', []))->route('attacker.example', 443, static fn (): array => ['93.184.216.34']));
    }

    /**
     * TLS の ClientHello（握手の始め）。$sni が null なら名前の拡張を付けない
     */
    private static function hello(?string $sni): string
    {
        $extensions = '';

        if ($sni !== null) {
            $name = "\x00".pack('n', strlen($sni)).$sni;
            $list = pack('n', strlen($name)).$name;
            $extensions .= pack('n', 0).pack('n', strlen($list)).$list;
        }

        $extensions .= pack('n', 0x002B).pack('n', 3)."\x02\x03\x04";
        $body = "\x03\x03".str_repeat("\x01", 32)."\x00".pack('n', 2)."\x13\x01"."\x01\x00".pack('n', strlen($extensions)).$extensions;
        $handshake = "\x01".substr(pack('N', strlen($body)), 1).$body;

        return "\x16\x03\x01".pack('n', strlen($handshake)).$handshake;
    }

    public function test_the_tls_name_is_read_from_the_client_hello(): void
    {
        $hello = self::hello('API.Stripe.com');

        $this->assertSame(['tls', 'api.stripe.com'], Policy::clientHelloSni($hello));
        $this->assertSame(['tls', 'api.stripe.com'], Policy::clientHelloSni($hello.'続き'), '後ろに続くものは見ない');
        $this->assertSame(['more', null], Policy::clientHelloSni(substr($hello, 0, 20)), '途中まで');
        $this->assertSame(['more', null], Policy::clientHelloSni(''));
        $this->assertSame(['tls', null], Policy::clientHelloSni(self::hello(null)), '名前の無い握手');
        $this->assertSame(['not-tls', null], Policy::clientHelloSni("GET / HTTP/1.1\r\nHost: x\r\n\r\n"));
        $this->assertSame(['not-tls', null], Policy::clientHelloSni("\x16\x03\x01\x00\x05\x02\x00\x00\x01\x00"), 'ClientHello でない握手');
        $this->assertSame(['tls', ''], Policy::clientHelloSni(self::hello('bad_name')), '形の違う名前は空（CONNECT の名前と合わない）');
    }

    public function test_counters_keep_only_the_last_24_hours(): void
    {
        $counters = new Counters;
        $now = 1_800_000_000;
        $counters->add('allowed', $now - 25 * 3600);
        $counters->add('refused', $now - 3600);
        $counters->add('refused', $now);
        $counters->add('unknown', $now);

        $this->assertSame(['allowed' => 0, 'unlisted' => 0, 'refused' => 2, 'failed' => 0, 'smtp' => 0], $counters->last24($now));
        $this->assertSame(['allowed' => 0, 'unlisted' => 0, 'refused' => 1, 'failed' => 0, 'smtp' => 0], $counters->last24($now, 60), '直近 1 時間');
        $this->assertSame($now, $counters->lastAt('refused'));
        $this->assertNull($counters->lastAt('unlisted'));
    }
}
