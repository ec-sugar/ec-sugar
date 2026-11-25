<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Media\HostResolver;
use App\Core\Media\RemoteImageRefused;
use App\Core\Media\RemoteImages;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MakesImages;
use Tests\TestCase;

/**
 * 店の人が書いた外の URL から写真を取る部品。サーバーから外への要求の悪用（SSRF）を断る。
 * 外への要求は Http::fake、名前を引くのは偽物で、本物の外へは出ない
 */
final class RemoteImagesTest extends TestCase
{
    use MakesImages;

    /** @var array<string, list<string>> */
    private array $dns = [];

    /** @var list<string> */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $test = $this;
        $this->app->instance(HostResolver::class, new class($test) extends HostResolver
        {
            public function __construct(private readonly RemoteImagesTest $test) {}

            public function resolve(string $host): array
            {
                return $this->test->answer($host);
            }
        });
    }

    /**
     * @return list<string>
     */
    public function answer(string $host): array
    {
        $this->asked[] = $host;

        return $this->dns[$host] ?? ['93.184.216.34'];
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function addresses(): iterable
    {
        yield '外の IPv4' => ['93.184.216.34', true];
        yield '外の IPv6' => ['2606:4700:4700::1111', true];
        yield '自分（127/8）' => ['127.0.0.1', false];
        yield '自分の別の書き方' => ['127.10.20.30', false];
        yield '何もない（0.0.0.0）' => ['0.0.0.0', false];
        yield '内側の網 10/8' => ['10.1.2.3', false];
        yield '内側の網 172.16/12' => ['172.30.255.226', false];
        yield '内側の網 192.168/16' => ['192.168.5.6', false];
        yield 'クラウドの設定の口 169.254/16' => ['169.254.169.254', false];
        yield 'CGNAT 100.64/10' => ['100.100.1.1', false];
        yield '試験用 198.18/15' => ['198.18.0.1', false];
        yield 'マルチキャスト' => ['224.0.0.1', false];
        yield 'IPv6 の自分' => ['::1', false];
        yield 'IPv6 の内側 fc00::/7' => ['fd12:3456::1', false];
        yield 'IPv6 のリンクローカル fe80::/10' => ['fe80::1', false];
        yield 'IPv4 を埋めた IPv6' => ['::ffff:127.0.0.1', false];
        yield 'IPv4 を埋めた IPv6（外の住所でも断る）' => ['::ffff:93.184.216.34', false];
        yield 'NAT64' => ['64:ff9b::7f00:1', false];
        yield '6to4' => ['2002:7f00:1::1', false];
        yield '住所でない' => ['example.com', false];
    }

    #[DataProvider('addresses')]
    public function test_only_public_addresses_are_allowed(string $ip, bool $public): void
    {
        $this->assertSame($public, RemoteImages::isPublic($ip));
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function urls(): iterable
    {
        yield 'https の外の URL' => ['https://cdn.example.com/a.jpg?w=800', null];
        yield '443 番を書いた URL' => ['https://cdn.example.com:443/a.jpg', null];
        yield 'http' => ['http://cdn.example.com/a.jpg', 'https の URL'];
        yield 'ftp' => ['ftp://cdn.example.com/a.jpg', 'https の URL'];
        yield 'file' => ['file:///etc/passwd', 'https の URL'];
        yield '利用者名つき' => ['https://user:pass@cdn.example.com/a.jpg', '利用者名'];
        yield 'ほかのポート' => ['https://cdn.example.com:8443/a.jpg', 'ポート'];
        yield 'localhost' => ['https://localhost/a.jpg', '外のサイトの名前でない'];
        yield '点の無い名前（コンテナの名前）' => ['https://db/a.jpg', '外のサイトの名前でない'];
        yield '.internal' => ['https://metadata.google.internal/a.jpg', '外のサイトの名前でない'];
        yield '住所そのもの（内側）' => ['https://10.0.0.1/a.jpg', '内向き'];
        yield 'IPv6 の住所そのもの（内側）' => ['https://[::1]/a.jpg', '内向き'];
        yield '住所の別の書き方（16 進）' => ['https://0x7f.0.0.1/a.jpg', '外のサイトの名前でない'];
        yield '住所の別の書き方（10 進 1 つ）' => ['https://2130706433/a.jpg', '外のサイトの名前でない'];
        // 終わりの点: 名前を固定する鍵と curl のつなぐ名前が食い違い、固定が効かない（もう一度名前を引かれる）
        yield '名前の終わりの点' => ['https://cdn.example.com./a.jpg', '終わりに点'];
        yield '空白入り' => ['https://cdn.example.com/a b.jpg', '形が違います'];
        yield '長すぎる' => ['https://cdn.example.com/'.str_repeat('a', 2100), '形が違います'];
    }

    #[DataProvider('urls')]
    public function test_url_shapes_are_checked_before_any_lookup(string $url, ?string $problem): void
    {
        $found = RemoteImages::problemOf($url);

        if ($problem === null) {
            $this->assertNull($found);
        } else {
            $this->assertIsString($found);
            $this->assertStringContainsString($problem, $found);
        }

        $this->assertSame([], $this->asked, '形の確認では名前を引かない');
    }

    public function test_a_name_with_any_internal_address_is_refused_before_connecting(): void
    {
        $this->dns = ['mixed.example.com' => ['93.184.216.34', '10.0.0.8'], 'none.example.com' => []];
        Http::fake();

        foreach (['https://mixed.example.com/a.jpg' => '内向き', 'https://none.example.com/a.jpg' => '名前を引けませんでした'] as $url => $message) {
            try {
                app(RemoteImages::class)->fetch($url);
                $this->fail("{$url} を取りに行った");
            } catch (RemoteImageRefused $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_redirects_are_followed_by_hand_and_each_destination_is_checked_again(): void
    {
        $jpeg = $this->jpeg(80, 60);
        $this->dns = ['inside.example.com' => ['192.168.0.9']];
        Http::fake([
            // 相対の行き先は元の URL を基に
            'https://cdn.example.com/start.jpg' => Http::response('', 302, ['Location' => '/images/next.jpg']),
            'https://cdn.example.com/images/next.jpg' => Http::response('', 302, ['Location' => 'https://img.example.net/final.jpg']),
            'https://img.example.net/final.jpg' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
            'https://cdn.example.com/inward.jpg' => Http::response('', 302, ['Location' => 'https://inside.example.com/a.jpg']),
            'https://cdn.example.com/loop.jpg' => Http::response('', 302, ['Location' => 'https://cdn.example.com/loop.jpg']),
            'https://cdn.example.com/downgrade.jpg' => Http::response('', 302, ['Location' => 'http://cdn.example.com/a.jpg']),
            'https://cdn.example.com/large.jpg' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) (RemoteImages::MAX_BYTES + 1)]),
            // 圧縮した応答（小さな転送で、解くと上限を超える本文を作れる）は解かずに断る
            'https://cdn.example.com/gzip.jpg' => Http::response((string) gzencode(str_repeat("\0", 1024)), 200, ['Content-Type' => 'image/jpeg', 'Content-Encoding' => 'gzip']),
        ]);
        $remote = app(RemoteImages::class);

        $this->assertSame($jpeg, $remote->fetch('https://cdn.example.com/start.jpg'));
        $this->assertSame(['cdn.example.com', 'cdn.example.com', 'img.example.net'], $this->asked, '行き先ごとに名前を引き直して確認する');

        foreach ([
            'https://cdn.example.com/inward.jpg' => '内向き',
            'https://cdn.example.com/loop.jpg' => 'リダイレクトが多すぎます',
            'https://cdn.example.com/downgrade.jpg' => 'https の URL',
            'https://cdn.example.com/large.jpg' => '大きすぎます',
            'https://cdn.example.com/gzip.jpg' => '圧縮した応答',
        ] as $url => $message) {
            try {
                $remote->fetch($url);
                $this->fail("{$url} を受け取った");
            } catch (RemoteImageRefused $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'inside.example.com') || str_starts_with($request->url(), 'http://'));
        // 圧縮しないでと頼む
        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Accept-Encoding', 'identity'));
        Http::assertNotSent(static fn (Request $request): bool => ! $request->hasHeader('Accept-Encoding', 'identity'));
    }

    /**
     * 本番の出口の代理（ADR 0102）があるときは、住所を固定せずに代理を通す（名前を引いて内向きを断り、確かめた住所につなぐのは代理）。
     * 名前を引ける形（見張りの形）ではここでも内向きを断る（二重の守り）。引けない（止める形）なら代理に任せる。
     * URL の形の確かめ・リダイレクトの行き先ごとの確かめはここでも続ける
     */
    public function test_with_the_egress_proxy_names_are_left_to_the_proxy(): void
    {
        config(['egress.proxy' => 'http://egress:3128']);
        $jpeg = $this->jpeg(80, 60);
        $options = [];
        Http::fake(static function (Request $request, array $sent) use ($jpeg, &$options) {
            $options[] = ['url' => $request->url(), 'proxy' => $sent['proxy'] ?? null, 'curl' => $sent['curl'] ?? null];

            return match ($request->url()) {
                'https://cdn.example.com/start.jpg' => Http::response('', 302, ['Location' => 'https://img.example.net/final.jpg']),
                'https://img.example.net/final.jpg' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
                'https://cdn.example.com/inward.jpg' => Http::response('', 302, ['Location' => 'https://localhost/a.jpg']),
                default => Http::response('', 404),
            };
        });
        $remote = app(RemoteImages::class);

        $this->dns = ['cdn.example.com' => [], 'img.example.net' => ['93.184.216.34'], 'inner.example.com' => ['93.184.216.34', '10.0.0.8']];

        $this->assertSame($jpeg, $remote->fetch('https://cdn.example.com/start.jpg'));
        $this->assertSame(['cdn.example.com', 'img.example.net'], $this->asked, '引ければ自分でも確認、引けなくても代理に任せる');
        $this->assertSame([
            ['url' => 'https://cdn.example.com/start.jpg', 'proxy' => 'http://egress:3128', 'curl' => []],
            ['url' => 'https://img.example.net/final.jpg', 'proxy' => 'http://egress:3128', 'curl' => []],
        ], $options, '代理を通し、住所を固定しない（名前は代理が引く）');

        // 形の確かめは代理の前に（内向きの名前・住所の直書きの内向き・http）
        foreach (['https://cdn.example.com/inward.jpg' => '外のサイトの名前でない', 'https://10.0.0.8/a.jpg' => '内向き', 'http://cdn.example.com/a.jpg' => 'https の URL', 'https://inner.example.com/a.jpg' => '内向き'] as $url => $message) {
            try {
                $remote->fetch($url);
                $this->fail("{$url} を受け取った");
            } catch (RemoteImageRefused $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->assertNotContains('https://localhost/a.jpg', array_column($options, 'url'));
        $this->assertNotContains('https://inner.example.com/a.jpg', array_column($options, 'url'), '内向きに引ける名前は代理へも送らない');
    }
}
