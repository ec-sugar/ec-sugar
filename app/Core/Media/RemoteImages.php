<?php

declare(strict_types=1);

namespace App\Core\Media;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * 店の人が書いた外の URL から写真を 1 枚取る（商品の CSV の写真の列）。サーバーから外への要求を悪用させない（SSRF よけ）。
 *
 * - https だけ。利用者名・合言葉つき、443 以外のポート、点の無い名前・localhost・.local などの内向きの名前は断る
 * - 名前を引いた住所が 1 つでも内向き（127/8・10/8・172.16/12・192.168/16・169.254/16・100.64/10・::1・fc00::/7・fe80::/10・
 *   IPv4 を埋めた IPv6 など。下の BLOCKED）なら断る。つなぐのは確かめた住所に固定する（CURLOPT_RESOLVE。確かめた後に DNS を
 *   引き直させて中へ向ける〔DNS rebinding〕ことをさせない）。つないだ相手の住所も、答えの後に確かめ直す
 * - リダイレクトは自分でたどり、行き先ごとに同じ確かめをやり直す（3 回まで）。プロキシの環境変数は使わない（プロキシの先で名前を引かせない）
 * - 本番の出口の代理（config の egress.proxy。ADR 0102）があるときは、住所を固定せずに代理を通す。名前を引いて内向きの住所を断り、
 *   確かめた住所につなぐのは代理（docker/egress/proxy.php）。名前を引ける形（見張りの形）ではここでも内向きを断る（止める形のアプリは外の名前を引けない）。代理は許す一覧に無い取得元を、
 *   見張りの形では通して記録し、止める形では断る（店の人の書いた取得元は env の EC_SUGAR_EGRESS_ALLOW に足す）
 * - 時間切れ: つなぐ 5 秒・リダイレクトを含めた全体 15 秒。大きさ: 10 MB（Content-Length・curl の上限・受け取った量の 3 つで）
 * - 画像の形: Content-Type が画像（か、形の知らせが無い・octet-stream）で、中身も JPEG・PNG・WebP として読めるものだけ。
 *   ここでは確かめるだけで、入れるときは ImageProcessor で作り直す（そのままは置かない）
 */
final class RemoteImages
{
    public const int MAX_BYTES = ImageProcessor::MAX_BYTES;

    public const int MAX_URL = 2048;

    private const int MAX_REDIRECTS = 3;

    private const int TIMEOUT = 15;

    private const int CONNECT_TIMEOUT = 5;

    /** 外へつないではいけない住所の範囲（filter_var の FILTER_FLAG_GLOBAL_RANGE に加えて、明示で） */
    private const array BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32', '2001:db8::/32', '2002::/16',
        'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** 内向きの名前の終わり */
    private const array INTERNAL_SUFFIXES = ['.localhost', '.local', '.internal', '.intranet', '.lan', '.home.arpa', '.corp', '.test', '.invalid'];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * URL の形だけを確かめる（名前は引かない。CSV の確かめで使う）。駄目なら理由
     */
    public static function problemOf(string $url): ?string
    {
        try {
            self::target($url);

            return null;
        } catch (RemoteImageRefused $e) {
            return $e->getMessage();
        }
    }

    /**
     * 取りに行って中身を返す（画像として読めるものだけ）
     *
     * @throws RemoteImageRefused 取れない（理由の文つき）
     */
    public function fetch(string $url): string
    {
        $deadline = microtime(true) + self::TIMEOUT;

        $proxy = self::egressProxy();

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $literal] = self::target($url);
            // 代理を通すときは住所を固定しない（代理が引いて確かめ、確かめた住所につなぐ）。名前を引ける形（見張りの形）なら、ここでも
            // 内向きの住所を断る（二重の守り）。止める形のアプリは外の名前を引けないので、引けなければ代理に任せる
            if ($proxy !== null && $literal === null) {
                $this->refuseInternal($host);
            }

            $ip = $proxy !== null ? null : ($literal ?? $this->vettedAddress($host));
            $remaining = $deadline - microtime(true);

            if ($remaining < 1) {
                throw new RemoteImageRefused('時間内に取れませんでした');
            }

            $response = $this->request($url, $host, $ip, $remaining, $proxy);
            $connected = $response->handlerStats()['primary_ip'] ?? null;

            // つないだ相手が確かめた住所でなければ使わない（固定が効かない仕組みに替わっても、中の住所の答えを使わない）。
            // 代理を通したときにつないだ相手は代理（内側の住所）なので見ない
            if ($ip !== null && is_string($connected) && $connected !== '' && (! self::isPublic($connected) || ! self::sameAddress($connected, $ip))) {
                throw new RemoteImageRefused('内部の IP アドレスには接続しません');
            }

            if ($response->redirect()) {
                $location = trim($response->header('Location'));

                if ($location === '') {
                    throw new RemoteImageRefused('リダイレクトの行き先がありません');
                }

                $url = self::absolute($url, $location);

                continue;
            }

            return self::imageOf($response);
        }

        throw new RemoteImageRefused('リダイレクトが多すぎます');
    }

    /**
     * 外の住所か（内向き・予約・特別な用途の範囲でない）
     */
    public static function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if (! is_string($packed) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // IPv4 を埋めた IPv6（::ffff:127.0.0.1 など）は、中の IPv4 が何でも断る（外の写真の置き場がこの書き方を使うことは無い）
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        foreach (self::BLOCKED as $range) {
            if (self::within($packed, $range)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 行き先の名前と、名前が住所そのものならその住所。形が駄目なら断る
     *
     * @return array{0: string, 1: string|null}
     *
     * @throws RemoteImageRefused
     */
    private static function target(string $url): array
    {
        if (strlen($url) > self::MAX_URL || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new RemoteImageRefused('URL の形が違います（空白や改行を入れず '.number_format(self::MAX_URL).' 文字まで）');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            throw new RemoteImageRefused('https の URL だけを取りに行きます');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RemoteImageRefused('利用者名やパスワードの入った URL は使えません');
        }

        if (isset($parts['port']) && $parts['port'] !== 443) {
            throw new RemoteImageRefused('443 番以外のポートへは取りに行きません');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        // 終わりの点（example.com.）は断る。名前を確かめて固定する鍵（CURLOPT_RESOLVE）と、curl がつなぐ名前が食い違い、固定が効かずにもう一度名前を引かれる
        if (str_ends_with($host, '.')) {
            throw new RemoteImageRefused('名前の終わりに点の付いた URL は使えません');
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $ip = substr($host, 1, -1);

            return self::isPublic($ip) ? [$host, $ip] : throw new RemoteImageRefused('内向きの住所へは取りに行きません');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::isPublic($host) ? [$host, $host] : throw new RemoteImageRefused('内向きの住所へは取りに行きません');
        }

        if ($host === '' || ! str_contains($host, '.') || $host === 'localhost' || preg_match('/\A[a-z0-9.-]+\z/', $host) !== 1
            || array_filter(self::INTERNAL_SUFFIXES, static fn (string $suffix): bool => str_ends_with($host, $suffix)) !== []
            // 終わりが数の名前（0x7f.1・2130706433・127.1 のような、住所を別の書き方にしたもの。本当の名前の終わりは数にならない）
            || preg_match('/(\A|\.)(0x[0-9a-f]*|[0-9]+)\z/', $host) === 1) {
            throw new RemoteImageRefused('外のサイトの名前でない行き先へは取りに行きません');
        }

        return [$host, null];
    }

    /**
     * 名前を引き、全部が外の住所なら 1 つを返す（1 つでも内向きなら断る）
     *
     * @throws RemoteImageRefused
     */
    private function vettedAddress(string $host): string
    {
        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            throw new RemoteImageRefused('名前を引けませんでした（'.$host.'）');
        }

        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                throw new RemoteImageRefused('内向きの住所へは取りに行きません（'.$host.'）');
            }
        }

        // IPv4 があればそちらに（IPv6 の道の無いサーバーでもつながるように）
        $v4 = array_values(array_filter($ips, static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));

        return $v4[0] ?? $ips[0];
    }

    /**
     * 確かめた住所に固定して 1 回だけ要求する（リダイレクトはたどらない）
     *
     * @throws RemoteImageRefused
     */
    private function request(string $url, string $host, ?string $ip, float $remaining, ?string $proxy = null): Response
    {
        // 名前はこの住所につなぐ（TLS の名前の確かめは元の名前のまま）。住所そのものの URL は固定しない。
        // curl の選択肢は Guzzle が許すものだけ（CURLOPT_RESOLVE）。https だけ・プロキシなし・大きさの上限は Guzzle の選択肢で。
        // 出口の代理を通すとき（$ip が null）は固定しない（名前は代理が引いて確かめる）
        $curl = $ip === null || str_starts_with($host, '[') || $host === $ip ? [] : [CURLOPT_RESOLVE => ["{$host}:443:".(str_contains($ip, ':') ? "[{$ip}]" : $ip)]];

        try {
            return Http::timeout(max(1, (int) floor($remaining)))->connectTimeout(min(self::CONNECT_TIMEOUT, max(1, (int) floor($remaining))))
                ->withoutRedirecting()
                ->withOptions([
                    'curl' => $curl,
                    'protocols' => ['https'],
                    'proxy' => $proxy ?? '',
                    // 圧縮した応答を自分で解かない（解いた後の大きさは progress の数に入らず、小さな転送で上限を超える本文を作れる）。
                    // 圧縮しないでと頼み（Accept-Encoding: identity）、それでも圧縮して来た応答は imageOf が断る
                    'decode_content' => false,
                    // 受け取りが上限を超えたら打ち切る（Content-Length の無い応答も。真を返すと curl が止める）
                    'progress' => static fn (int $total, int $received): bool => $total > self::MAX_BYTES || $received > self::MAX_BYTES,
                ])
                ->withUserAgent('EC-SUGAR product import')
                ->withHeaders(['Accept-Encoding' => 'identity'])
                ->accept('image/jpeg, image/png, image/webp, image/*;q=0.8')
                ->get($url);
        } catch (ConnectionException|RequestException|GuzzleException $e) {
            throw new RemoteImageRefused($proxy === null
                ? '接続できませんでした（時間切れか、大きすぎるか、相手が応答しない）'
                : '接続できませんでした（時間切れか、大きすぎるか、相手が応答しないか、外への通信を許していない取得元）', 0, $e);
        }
    }

    /**
     * 名前を引けたら、1 つでも内向きの住所なら断る（引けなければ何もしない。代理を通すときの二重の守り）
     *
     * @throws RemoteImageRefused
     */
    private function refuseInternal(string $host): void
    {
        foreach ($this->resolver->resolve($host) as $ip) {
            if (! self::isPublic($ip)) {
                throw new RemoteImageRefused('内向きの住所へは取りに行きません（'.$host.'）');
            }
        }
    }

    /**
     * 本番の出口の代理（無ければ null。開発の形とテスト）
     */
    private static function egressProxy(): ?string
    {
        $proxy = config('egress.proxy');

        return is_string($proxy) && preg_match('#\Ahttps?://[^\s@]+\z#', $proxy) === 1 ? $proxy : null;
    }

    /**
     * @throws RemoteImageRefused
     */
    private static function imageOf(Response $response): string
    {
        if (! $response->successful()) {
            throw new RemoteImageRefused("取得元の応答が {$response->status()} でした");
        }

        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

        if ($type !== '' && $type !== 'application/octet-stream' && ! str_starts_with($type, 'image/')) {
            throw new RemoteImageRefused("画像ではありません（{$type}）");
        }

        // 圧縮したままの本文は画像として読めず、解けば上限を超えうるので受けない（request で解かないようにしてある）
        $encoding = strtolower(trim($response->header('Content-Encoding')));

        if ($encoding !== '' && $encoding !== 'identity') {
            throw new RemoteImageRefused("圧縮した応答は受けません（{$encoding}）");
        }

        $length = $response->header('Content-Length');

        if ($length !== '' && ctype_digit($length) && (int) $length > self::MAX_BYTES) {
            throw new RemoteImageRefused(self::tooLarge());
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_BYTES) {
            throw new RemoteImageRefused(self::tooLarge());
        }

        $info = $body === '' ? false : @getimagesizefromstring($body);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new RemoteImageRefused('画像として読めません（JPEG・PNG・WebP だけ）');
        }

        return $body;
    }

    /**
     * リダイレクトの行き先（相対の道筋も、元の URL を基に絶対の URL にする）。形の確かめは次の回の target がする
     */
    private static function absolute(string $base, string $location): string
    {
        if (preg_match('#\A[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = 'https://'.($parts['host'] ?? '');

        if (str_starts_with($location, '//')) {
            return 'https:'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = (string) ($parts['path'] ?? '/');

        return $origin.substr($path, 0, (int) strrpos($path, '/') + 1).$location;
    }

    private static function within(string $packed, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $base = (string) inet_pton($network);

        if (strlen($base) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (substr($packed, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }

    private static function sameAddress(string $a, string $b): bool
    {
        return @inet_pton($a) === @inet_pton($b);
    }

    private static function tooLarge(): string
    {
        return '大きすぎます（1 枚 '.intdiv(self::MAX_BYTES, 1024 * 1024).' MB まで）';
    }
}
