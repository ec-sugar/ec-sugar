<?php

declare(strict_types=1);

/*
 * 外への通信の出口の代理（ADR 0102）。本番の compose の egress のサービスが、アプリと同じイメージの PHP で動かす
 * （docker-compose.prod.yml。このファイルと許す一覧 allow.txt は compose の置き場から読み取り専用で差し込む）。
 *
 * - https の代理（HTTP の CONNECT だけ。3128 番）: アプリ（app・queue・scheduler）は環境変数 HTTPS_PROXY でここを通る
 *   （Laravel の Http〔Guzzle〕・Stripe の部品〔libcurl〕のどちらも HTTPS_PROXY を読む）。行き先は名前とポート 443 だけで決め、
 *   許す一覧（allow.txt と env の EC_SUGAR_EGRESS_ALLOW）に無い名前・住所の直書き・443 以外のポートは、
 *     - 見張りの形（EC_SUGAR_EGRESS_MODE=watch。既定）: 通して記録する（数と名前。中身は暗号のままで見ない）
 *     - 止める形（enforce）: 403 で断って記録する
 *   名前は代理が引き、引いた住所が 1 つでも内向き（10/8・172.16/12・192.168/16・127/8 など）なら、どちらの形でも断る（代理を踏み台に
 *   家の LAN・ホスト・ほかのコンテナへ届かせない）。つなぐのは確かめた住所そのもの（引き直さない。DNS rebinding をさせない）。IPv4 だけ
 *   200 を返した後、TLS の握手の名前（SNI）が CONNECT の名前と違えば切る（許した名前の陰で別の名前へ握手させない）。TLS でない・名前の無い握手は止める形では切る
 * - メールの送り口の中継（EC_SUGAR_EGRESS_SMTP_HOST・_PORT。env の MAIL_HOST・MAIL_PORT から）: 止める形ではアプリから外へ出る道が無いので、
 *   MAIL_HOST の名前をこの代理の住所に向け（compose の extra_hosts）、同じポートで受けて MAIL_HOST へそのまま中継する（TLS はアプリと
 *   メールの送り口の間で閉じる。中身は見ない）。ほかの宛先へは中継しない
 * - 数: 通した・一覧に無いが通した・断った・つながらなかった・メールの中継の数を 1 時間ごとに数え、この 24 時間の合計を状態のファイル
 *   （EC_SUGAR_EGRESS_STATE。アプリと分け合うメモリの上の置き場）に 10 秒ごとに書く。アプリが運営の様子と知らせに出す（App\Admin\Services\EgressWatch）
 * - 記録（docker の記録に 1 行ずつ JSON）: 時刻・種類・決めたこと・名前とポート・理由だけ。要求の中身・道筋・ヘッダーは持たない（CONNECT には無い）
 *
 * 1 つの接続に 1 つの子の処理（pcntl_fork）。親は受けて子を作り、子の終わりの番号で数える（数は親だけが持つ）。子は同時に 128 まで。
 * 秘密を持たない（env ファイルを渡さない）・読み取り専用・権限を落として www-data で動かす（compose）
 */

namespace EcSugar\Egress;

/**
 * 行き先の決まり（名前・ポート・住所）。網に触らないので、PHP のテストで確かめる（tests/Unit/EgressPolicyTest.php）
 */
final class Policy
{
    public const string WATCH = 'watch';

    public const string ENFORCE = 'enforce';

    /** 代理が通すポート（https だけ） */
    public const int PORT = 443;

    /** 外とみなさない IPv4 の範囲（app/Core/Media/RemoteImages.php の BLOCKED の IPv4 と同じ） */
    public const array BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /**
     * @param  list<string>  $allow  許す名前（名前そのものか *.の後ろ）
     * @param  list<string>  $internal  内向きの住所へ引けても通してよい名前（確かめの台本の偽の宛先など。ふだんは空）
     */
    public function __construct(
        public readonly string $mode,
        private readonly array $allow,
        private readonly array $internal = [],
    ) {}

    /**
     * 形の名前を決める。知らない値は見張りの形（止めない方）に倒し、記録に残す（呼ぶ側）
     */
    public static function mode(string $value): string
    {
        return strtolower(trim($value)) === self::ENFORCE ? self::ENFORCE : self::WATCH;
    }

    /**
     * 一覧の文（ファイルか env）を名前の並びにする。# から後ろ・空の項目・形の違う項目は捨てる
     *
     * @param  list<string>|null  $skipped  捨てた項目（記録のため）
     *
     * @param-out list<string>  $skipped
     *
     * @return list<string>
     */
    public static function parseList(string $text, ?array &$skipped = null): array
    {
        $skipped = [];
        $names = [];

        // 区切りは改行・空白・タブ・カンマだけ（\R と \s は UTF-8 の文字の途中のバイト〔0x85・0xA0〕にも当たり、日本語のコメントを切ってしまう）
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
            $line = (string) preg_replace('/#.*/', '', $line);

            foreach (preg_split('/[ \t,]+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
                $item = rtrim(strtolower($item), '.');
                $wild = str_starts_with($item, '*.');
                $name = $wild ? substr($item, 2) : $item;
                $labels = explode('.', $name);

                // 住所やその欠けたもの（10.0.0・1.2.3.4）と、終わりが数の名前は名前でない。*. はラベルが 2 つ以上の名前の下だけ（*.com のような広すぎるものは捨てる）
                if (self::validHost($name) && preg_match('/\A[0-9]+\z/', end($labels)) !== 1 && (! $wild || count($labels) >= 2)) {
                    $names[] = $item;
                } else {
                    $skipped[] = $item;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * 名前の形（文字・数字・ハイフンの区切りが点でつながったもの。全体で 253 文字まで）か、IPv4 の住所
     */
    public static function validHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        return preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/', $host) === 1;
    }

    public static function isIpv4(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    public static function isPublicIpv4(string $ip): bool
    {
        if (! self::isIpv4($ip)) {
            return false;
        }

        $address = (int) ip2long($ip);

        foreach (self::BLOCKED_V4 as $range) {
            [$network, $bits] = explode('/', $range);
            // 範囲はどれも /4 から /24（/0 は無い）
            $mask = (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;

            if (($address & $mask) === (((int) ip2long($network)) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * CONNECT の 1 行目（CONNECT 名前:ポート HTTP/1.x）を読む。形が違えば null。名前は小文字にし、末尾の点を外す
     *
     * @return array{host: string, port: int}|null
     */
    public static function parseConnect(string $head): ?array
    {
        $first = strtok($head, "\r\n");

        if ($first === false || preg_match('/\ACONNECT ([^\s\/:\[\]@]{1,253}):([0-9]{1,5}) HTTP\/1\.[01]\z/', $first, $m) !== 1) {
            return null;
        }

        $port = (int) $m[2];
        $host = rtrim(strtolower($m[1]), '.');

        if ($port < 1 || $port > 65535 || ! self::validHost($host)) {
            return null;
        }

        return ['host' => $host, 'port' => $port];
    }

    /**
     * 一覧に載っているか（名前そのもの、か *.の後ろに続く下の名前）
     */
    public function listed(string $host): bool
    {
        return self::matches($this->allow, $host);
    }

    /**
     * 引いた住所で決める。内向きの住所へ引ける名前は、内向きを許した名前だけ。1 つも引けなければ通さない
     *
     * @param  list<string>  $ips
     */
    public function addressesOk(string $host, array $ips): bool
    {
        if ($ips === []) {
            return false;
        }

        if (self::matches($this->internal, $host)) {
            return true;
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIpv4($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 名前を引く前の決め。allow（一覧にある）・unlisted（一覧に無いが見張りの形なので通す）・refuse（断る）と理由
     *
     * @return array{0: 'allow'|'unlisted'|'refuse', 1: string}
     */
    public function decide(string $host, int $port): array
    {
        if (! self::validHost($host)) {
            return ['refuse', 'bad-host'];
        }

        // 住所の直書きは、内向きならどちらの形でも断る（踏み台にさせない）。外の住所は一覧に載せられないので「一覧に無い」と同じ
        if (self::isIpv4($host)) {
            if (! self::isPublicIpv4($host)) {
                return ['refuse', 'internal-address'];
            }

            return $this->mode === self::ENFORCE ? ['refuse', 'ip-literal'] : ['unlisted', 'ip-literal'];
        }

        $problem = match (true) {
            $port !== self::PORT => 'port',
            ! $this->listed($host) => 'unlisted',
            default => null,
        };

        if ($problem === null) {
            return ['allow', 'listed'];
        }

        return $this->mode === self::ENFORCE ? ['refuse', $problem] : ['unlisted', $problem];
    }

    /**
     * 行き先を決める（名前を引く前の決め → 名前を引く → 引いた住所の確かめ）。代理の本体（Proxy::handleConnect）はこれだけを使う。
     * 返すのは決めたこと・理由・つなぐ住所（引いた順。断る・引けない時は空）
     *
     * @param  callable(string): list<string>  $resolve  名前 → IPv4 の住所
     * @return array{0: 'allow'|'unlisted'|'refuse'|'failed', 1: string, 2: list<string>}
     */
    public function route(string $host, int $port, callable $resolve): array
    {
        [$decision, $reason] = $this->decide($host, $port);

        if ($decision === 'refuse') {
            return [$decision, $reason, []];
        }

        $ips = self::isIpv4($host) ? [$host] : $resolve($host);

        if ($ips === []) {
            return ['failed', 'dns', []];
        }

        if (! $this->addressesOk($host, $ips)) {
            return ['refuse', 'internal-address', []];
        }

        return [$decision, $reason, $ips];
    }

    /**
     * TLS の ClientHello から名前（SNI）を読む。CONNECT の名前と違う名前で握手させて、許した名前の陰で別の宛先へ行かせない（SNI での domain fronting）。
     * 返すのは more（まだ足りない）・tls（読めた。2 つ目に名前、無ければ null）・not-tls（TLS の握手でない）
     *
     * @return array{0: 'more'|'tls'|'not-tls', 1: string|null}
     */
    public static function clientHelloSni(string $bytes): array
    {
        if (strlen($bytes) < 5) {
            return [$bytes === '' || $bytes[0] === "\x16" ? 'more' : 'not-tls', null];
        }

        $record = unpack('Ctype/nversion/nlength', substr($bytes, 0, 5));

        if ($record === false || $record['type'] !== 0x16 || ($record['version'] >> 8) !== 3 || $record['length'] < 4) {
            return ['not-tls', null];
        }

        if (strlen($bytes) < 5 + $record['length']) {
            return ['more', null];
        }

        // ClientHello は最初の記録に収まる前提で読む（収まらなければ読めないものとして扱う）
        $hello = substr($bytes, 5, $record['length']);

        if (ord($hello[0]) !== 0x01) {
            return ['not-tls', null];
        }

        $at = 4 + 2 + 32;
        $take = static function (int $size) use ($hello, &$at): ?string {
            if ($at + $size > strlen($hello)) {
                return null;
            }

            $part = substr($hello, $at, $size);
            $at += $size;

            return $part;
        };
        $length = static fn (?string $raw): ?int => $raw === null ? null : (int) hexdec(bin2hex($raw));

        foreach ([1, 2, 1] as $size) {
            $skip = $length($take($size));

            if ($skip === null || $take($skip) === null) {
                return ['not-tls', null];
            }
        }

        $extensions = $length($take(2));
        $end = $extensions === null ? -1 : $at + $extensions;

        if ($extensions === null || $end > strlen($hello)) {
            // 拡張の無い ClientHello（名前も無い）
            return $extensions === null ? ['tls', null] : ['not-tls', null];
        }

        while ($at + 4 <= $end) {
            $type = $length($take(2));
            $size = (int) $length($take(2));
            $data = $take($size);

            if ($data === null) {
                return ['not-tls', null];
            }

            if ($type === 0 && strlen($data) >= 5 && ord($data[2]) === 0) {
                $nameLength = (int) hexdec(bin2hex(substr($data, 3, 2)));
                $name = rtrim(strtolower(substr($data, 5, $nameLength)), '.');

                return ['tls', self::validHost($name) ? $name : ''];
            }
        }

        return ['tls', null];
    }

    /**
     * @param  list<string>  $list
     */
    private static function matches(array $list, string $host): bool
    {
        $host = rtrim(strtolower($host), '.');

        foreach ($list as $entry) {
            if (str_starts_with($entry, '*.')) {
                if (str_ends_with($host, substr($entry, 1)) && strlen($host) > strlen($entry) - 1) {
                    return true;
                }
            } elseif ($entry === $host) {
                return true;
            }
        }

        return false;
    }
}

/**
 * 1 時間ごとの数（親の処理だけが持つ）。この 24 時間の合計を状態のファイルへ書く
 */
final class Counters
{
    public const array KINDS = ['allowed', 'unlisted', 'refused', 'failed', 'smtp'];

    /** @var array<int, array<string, int>> 分（UNIX 時刻 ÷ 60）→ 種類 → 数。24 時間分だけ持つ */
    private array $minutes = [];

    /** @var array<string, int|null> 種類 → 最後に数えた UNIX 時刻 */
    private array $last = [];

    public function add(string $kind, int $now): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            return;
        }

        $minute = intdiv($now, 60);
        $this->minutes[$minute][$kind] = ($this->minutes[$minute][$kind] ?? 0) + 1;
        $this->last[$kind] = $now;

        // 24 時間より前の分は捨てる
        foreach (array_keys($this->minutes) as $key) {
            if ($key <= $minute - 1440) {
                unset($this->minutes[$key]);
            }
        }
    }

    /**
     * この $minutes 分の合計（既定は 24 時間）
     *
     * @return array<string, int>
     */
    public function last24(int $now, int $minutes = 1440): array
    {
        $minute = intdiv($now, 60);
        $sum = array_fill_keys(self::KINDS, 0);

        foreach ($this->minutes as $key => $counts) {
            if ($key > $minute - $minutes) {
                foreach ($counts as $kind => $count) {
                    $sum[$kind] += $count;
                }
            }
        }

        return $sum;
    }

    public function lastAt(string $kind): ?int
    {
        return $this->last[$kind] ?? null;
    }
}

/**
 * 代理の本体（受け口・子の処理・中継・状態のファイル）
 */
final class Proxy
{
    /** 子の終わりの番号 → 数える種類（0 は数えない: ヘルスチェック） */
    private const array CODES = [10 => 'allowed', 11 => 'unlisted', 12 => 'refused', 13 => 'failed', 14 => 'smtp', 15 => 'failed'];

    private const int ALLOWED = 10;

    private const int UNLISTED = 11;

    private const int REFUSED = 12;

    private const int FAILED = 13;

    private const int SMTP_OK = 14;

    private const int SMTP_FAILED = 15;

    /** 同時に動かす子の数の上限（超えたらその接続はすぐ閉じて「つながらなかった」に数える） */
    private const int MAX_CHILDREN = 128;

    /** 要求の頭の上限（バイト）と待つ時間（秒） */
    private const int HEAD_MAX = 8192;

    private const int HEAD_TIMEOUT = 10;

    /** 行き先へつなぐのを待つ時間（1 つの住所ごと）・どちらも黙っていたら切る時間（秒）。流れている接続は長さで切らない */
    private const int CONNECT_TIMEOUT = 5;

    private const int IDLE_TIMEOUT = 120;

    /** 止める合図（SIGTERM）の後、受け口を閉じて、途中の接続（Stripe への要求など）が終わるのを待つ長さ（秒）。compose の stop_grace_period（90 秒）より短く */
    private const int DRAIN_SECONDS = 80;

    /** TLS の握手の始め（ClientHello）を待つ時間と上限（バイト） */
    private const int HELLO_TIMEOUT = 10;

    private const int HELLO_MAX = 16389;

    /** 状態のファイルを書く間（秒） */
    private const int STATE_EVERY = 10;

    private Counters $counters;

    private int $startedAt;

    private bool $stop = false;

    /**
     * @param  array{host: string, port: int}|null  $smtp  メールの送り口（中継しないなら null）
     */
    public function __construct(
        private readonly Policy $policy,
        private readonly int $port,
        private readonly ?array $smtp,
        private readonly string $statePath,
    ) {
        $this->counters = new Counters;
        $this->startedAt = time();
    }

    /**
     * 環境変数と許す一覧のファイルから作る。形の違う値は記録に残して、止めない側に倒す
     */
    public static function fromEnvironment(string $allowFile): self
    {
        $rawMode = (string) getenv('EC_SUGAR_EGRESS_MODE');
        $mode = Policy::mode($rawMode);

        if ($rawMode !== '' && strtolower(trim($rawMode)) !== $mode) {
            self::log(['event' => 'config', 'problem' => 'unknown-mode', 'mode' => $mode]);
        }

        $text = is_readable($allowFile) ? (string) file_get_contents($allowFile) : '';

        if ($text === '') {
            self::log(['event' => 'config', 'problem' => 'allow-file-missing']);
        }

        $allow = Policy::parseList($text."\n".(string) getenv('EC_SUGAR_EGRESS_ALLOW'), $skipped);
        $internal = Policy::parseList((string) getenv('EC_SUGAR_EGRESS_INTERNAL_OK'), $skippedInternal);

        foreach ([...$skipped, ...$skippedInternal] as $item) {
            // 形の違う項目の中身は出さない（長さだけ）
            self::log(['event' => 'config', 'problem' => 'bad-entry', 'length' => strlen($item)]);
        }

        $port = (int) (getenv('EC_SUGAR_EGRESS_PORT') ?: 3128);
        $smtpHost = rtrim(strtolower(trim((string) getenv('EC_SUGAR_EGRESS_SMTP_HOST'))), '.');
        $smtpPort = (int) getenv('EC_SUGAR_EGRESS_SMTP_PORT');
        $smtp = null;

        if ($smtpHost !== '' && Policy::validHost($smtpHost) && $smtpPort >= 1 && $smtpPort <= 65535 && $smtpPort !== $port) {
            $smtp = ['host' => $smtpHost, 'port' => $smtpPort];
        } elseif ($smtpHost !== '' || $smtpPort !== 0) {
            self::log(['event' => 'config', 'problem' => 'bad-smtp']);
        }

        $state = (string) (getenv('EC_SUGAR_EGRESS_STATE') ?: '/run/ec-sugar-egress/state.json');

        return new self(new Policy($mode, $allow, $internal), $port, $smtp, $state);
    }

    public function run(): int
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->stop = true;
        });
        pcntl_signal(SIGINT, function (): void {
            $this->stop = true;
        });
        // アプリのイメージ（公式の php-fpm）は止める合図が SIGQUIT（STOPSIGNAL）。compose で SIGTERM にしているが、どちらでも同じに止まる
        pcntl_signal(SIGQUIT, function (): void {
            $this->stop = true;
        });

        $servers = ['connect' => $this->listen($this->port)];

        if ($this->smtp !== null) {
            $servers['smtp'] = $this->listen($this->smtp['port']);
        }

        foreach ($servers as $server) {
            if ($server === null) {
                return 1;
            }
        }

        /** @var array<string, resource> $servers */
        self::log([
            'event' => 'start', 'mode' => $this->policy->mode, 'port' => $this->port,
            'smtp' => $this->smtp === null ? null : $this->smtp['host'].':'.$this->smtp['port'],
        ]);

        $children = [];
        $written = 0;

        while (! $this->stop) {
            $read = array_values($servers);
            $write = $except = null;
            $ready = @stream_select($read, $write, $except, 1);

            $this->reap($children);

            foreach ($ready > 0 ? $read : [] as $server) {
                $client = @stream_socket_accept($server, 0);

                if ($client === false) {
                    continue;
                }

                $kind = array_search($server, $servers, true) === 'smtp' ? 'smtp' : 'connect';

                if (count($children) >= self::MAX_CHILDREN) {
                    self::log(['event' => $kind, 'decision' => 'failed', 'reason' => 'busy']);
                    $this->counters->add('failed', time());
                    fclose($client);

                    continue;
                }

                $pid = pcntl_fork();

                if ($pid === -1) {
                    self::log(['event' => $kind, 'decision' => 'failed', 'reason' => 'fork']);
                    $this->counters->add('failed', time());
                    fclose($client);

                    continue;
                }

                if ($pid === 0) {
                    foreach ($servers as $own) {
                        fclose($own);
                    }

                    exit($kind === 'smtp' ? $this->relaySmtp($client) : $this->handleConnect($client));
                }

                $children[$pid] = true;
                fclose($client);
            }

            if (time() - $written >= self::STATE_EVERY) {
                $this->writeState();
                $written = time();
            }
        }

        // 止める合図: 新しい接続は受けず（受け口を閉じる）、途中の接続が終わるのを待つ。親が先に終わると、PID 1 なので子も殺される
        foreach ($servers as $server) {
            fclose($server);
        }

        $deadline = time() + self::DRAIN_SECONDS;
        self::log(['event' => 'draining', 'children' => count($children)]);

        while ($children !== [] && time() < $deadline) {
            $this->reap($children);
            usleep(200_000);
        }

        $this->writeState();
        self::log(['event' => 'stop', 'left' => count($children)]);

        return 0;
    }

    /**
     * @return resource|null
     */
    private function listen(int $port)
    {
        $server = @stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $error);

        if ($server === false) {
            self::log(['event' => 'start', 'problem' => 'listen', 'port' => $port, 'error' => $errno]);

            return null;
        }

        return $server;
    }

    /**
     * 終わった子を片付けて数える
     *
     * @param  array<int, true>  $children
     */
    private function reap(array &$children): void
    {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            unset($children[$pid]);
            $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
            $kind = self::CODES[$code] ?? ($code === 0 ? null : 'failed');

            if ($kind !== null) {
                $this->counters->add($kind, time());
            }
        }
    }

    /**
     * 状態のファイル（この 24 時間の数・形・最後の時刻）を書く。書けなくても止めない（数が出ないだけ）
     */
    private function writeState(): void
    {
        $now = time();
        $at = static fn (?int $time): ?string => $time === null ? null : gmdate('Y-m-d\TH:i:s\Z', $time);
        $json = json_encode([
            'version' => 1,
            'mode' => $this->policy->mode,
            'smtp_relay' => $this->smtp !== null,
            'started_at' => $at($this->startedAt),
            'updated_at' => $at($now),
            'last24h' => $this->counters->last24($now),
            // 直近 1 時間（つながらないのに 1 本も通っていない、を運営の様子が見る）
            'last1h' => $this->counters->last24($now, 60),
            'last_unlisted_at' => $at($this->counters->lastAt('unlisted')),
            'last_refused_at' => $at($this->counters->lastAt('refused')),
        ], JSON_THROW_ON_ERROR);
        $tmp = $this->statePath.'.tmp';

        if (@file_put_contents($tmp, $json) === false || ! @rename($tmp, $this->statePath)) {
            static $warned = false;

            if (! $warned) {
                self::log(['event' => 'state', 'problem' => 'write']);
                $warned = true;
            }
        }
    }

    /**
     * https の代理の 1 つの接続（子の処理）。終わりの番号を返す
     *
     * @param  resource  $client
     */
    private function handleConnect($client): int
    {
        $head = self::readHead($client);

        if ($head === null) {
            self::log(['event' => 'connect', 'decision' => 'refuse', 'reason' => 'bad-request']);

            return self::REFUSED;
        }

        [$header, $rest] = $head;

        if (preg_match('/\AGET \/healthz HTTP\/1\.[01]\r?\n/', $header."\n") === 1) {
            self::writeAll($client, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 3\r\nConnection: close\r\n\r\nok\n");

            return 0;
        }

        $target = Policy::parseConnect($header);

        if ($target === null) {
            // CONNECT のほか（平文の http の代理など）は受けない。中身は記録しない
            self::respond($client, 405, 'Method Not Allowed');
            self::log(['event' => 'connect', 'decision' => 'refuse', 'reason' => 'bad-request']);

            return self::REFUSED;
        }

        ['host' => $host, 'port' => $port] = $target;
        [$decision, $reason, $ips] = $this->policy->route($host, $port, self::resolve(...));

        if ($decision === 'refuse' || $decision === 'failed') {
            self::respond($client, $decision === 'refuse' ? 403 : 502, $decision === 'refuse' ? 'Forbidden' : 'Bad Gateway');
            self::log(['event' => 'connect', 'decision' => $decision, 'reason' => $reason, 'host' => $host, 'port' => $port]);

            return $decision === 'refuse' ? self::REFUSED : self::FAILED;
        }

        $upstream = self::connectAny($ips, $port);

        if ($upstream === null) {
            self::respond($client, 502, 'Bad Gateway');
            self::log(['event' => 'connect', 'decision' => 'failed', 'reason' => 'connect', 'host' => $host, 'port' => $port]);

            return self::FAILED;
        }

        if (! self::writeAll($client, "HTTP/1.1 200 Connection established\r\n\r\n")) {
            return self::FAILED;
        }

        // 握手の名前（SNI）が CONNECT の名前と違えば切る（許した名前の陰で別の宛先へ行かせない）。TLS でないもの・名前の無い握手は、
        // 見張りの形では記録して通し、止める形では切る
        [$hello, $sni] = self::readHello($client, $rest);

        if ($sni !== $host) {
            $problem = match (true) {
                $sni === false => 'not-tls',
                $sni === null => 'no-sni',
                default => 'sni-mismatch',
            };

            if ($problem === 'sni-mismatch' || $this->policy->mode === Policy::ENFORCE) {
                self::log(['event' => 'connect', 'decision' => 'refuse', 'reason' => $problem, 'host' => $host, 'port' => $port]);
                @fclose($upstream);

                return self::REFUSED;
            }

            [$decision, $reason] = ['unlisted', $problem];
        }

        self::log(['event' => 'connect', 'decision' => $decision, 'reason' => $reason, 'host' => $host, 'port' => $port]);

        if ($hello !== '' && ! self::writeAll($upstream, $hello)) {
            return self::FAILED;
        }

        self::splice($client, $upstream);

        return $decision === 'allow' ? self::ALLOWED : self::UNLISTED;
    }

    /**
     * メールの送り口への中継の 1 つの接続（子の処理）。宛先は決めた 1 つだけ（root が env に書いた MAIL_HOST。内向きの住所でもよい）
     *
     * @param  resource  $client
     */
    private function relaySmtp($client): int
    {
        assert($this->smtp !== null);
        ['host' => $host, 'port' => $port] = $this->smtp;
        $ips = Policy::isIpv4($host) ? [$host] : self::resolve($host);
        $upstream = self::connectAny($ips, $port);

        if ($upstream === null) {
            self::log(['event' => 'smtp', 'decision' => 'failed', 'reason' => $ips === [] ? 'dns' : 'connect', 'host' => $host, 'port' => $port]);

            return self::SMTP_FAILED;
        }

        self::log(['event' => 'smtp', 'decision' => 'allow', 'host' => $host, 'port' => $port]);
        self::splice($client, $upstream);

        return self::SMTP_OK;
    }

    /**
     * 要求の頭（空の行まで）と、その後ろに続けて来たもの。上限を超えた・時間切れ・閉じたら null
     *
     * @param  resource  $client
     * @return array{0: string, 1: string}|null
     */
    private static function readHead($client): ?array
    {
        stream_set_read_buffer($client, 0);
        $buffer = '';
        $deadline = microtime(true) + self::HEAD_TIMEOUT;

        while (($end = strpos($buffer, "\r\n\r\n")) === false) {
            $left = $deadline - microtime(true);

            if (strlen($buffer) > self::HEAD_MAX || $left <= 0) {
                return null;
            }

            $read = [$client];
            $write = $except = null;

            if (@stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1) * 1_000_000)) < 1) {
                return null;
            }

            $chunk = fread($client, self::HEAD_MAX);

            if ($chunk === false || $chunk === '') {
                return null;
            }

            $buffer .= $chunk;
        }

        return [substr($buffer, 0, $end), substr($buffer, $end + 4)];
    }

    /**
     * 確かめた住所へ順につなぐ（1 つ目がつながらなければ次へ）。どれもつながらなければ null
     *
     * @param  list<string>  $ips
     * @return resource|null
     */
    private static function connectAny(array $ips, int $port)
    {
        foreach ($ips as $ip) {
            $stream = @stream_socket_client("tcp://{$ip}:{$port}", $errno, $error, self::CONNECT_TIMEOUT);

            if ($stream !== false) {
                return $stream;
            }
        }

        return null;
    }

    /**
     * 200 の後に来る握手の始め（ClientHello）を読み、受け取ったもの（行き先へそのまま渡す）と名前を返す。
     * 名前は SNI の名前・null（名前の無い握手）・false（TLS でない・時間切れ・大きすぎる）
     *
     * @param  resource  $client
     * @return array{0: string, 1: string|false|null}
     */
    private static function readHello($client, string $buffer): array
    {
        $deadline = microtime(true) + self::HELLO_TIMEOUT;

        while (true) {
            [$state, $name] = Policy::clientHelloSni($buffer);

            if ($state === 'tls') {
                return [$buffer, $name];
            }

            $left = $deadline - microtime(true);

            if ($state === 'not-tls' || strlen($buffer) > self::HELLO_MAX || $left <= 0) {
                return [$buffer, false];
            }

            $read = [$client];
            $write = $except = null;

            if (@stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1) * 1_000_000)) < 1) {
                return [$buffer, false];
            }

            $chunk = fread($client, self::HELLO_MAX);

            if ($chunk === false || $chunk === '') {
                return [$buffer, false];
            }

            $buffer .= $chunk;
        }
    }

    /**
     * 名前を IPv4 の住所へ引く（システムの引き方〔/etc/hosts と DNS〕、駄目なら DNS の A を直に）
     *
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $ips = @gethostbynamel($host) ?: [];

        if ($ips === []) {
            foreach (@dns_get_record($host, DNS_A) ?: [] as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }

        return array_values(array_unique(array_filter($ips, Policy::isIpv4(...))));
    }

    /**
     * 両方向へそのまま流す。どちらかが閉じた・黙っている時間が長い・長すぎる接続は切る
     *
     * @param  resource  $a
     * @param  resource  $b
     */
    private static function splice($a, $b): void
    {
        foreach ([$a, $b] as $stream) {
            stream_set_blocking($stream, true);
            stream_set_read_buffer($stream, 0);
            stream_set_timeout($stream, 30);
        }

        $last = time();

        // 流れている接続は長さで切らない（黙っている時間だけで切る）
        while (true) {
            $read = [$a, $b];
            $write = $except = null;
            $ready = @stream_select($read, $write, $except, 5);

            if ($ready === false) {
                break;
            }

            if ($ready === 0) {
                if (time() - $last > self::IDLE_TIMEOUT) {
                    break;
                }

                continue;
            }

            foreach ($read as $from) {
                $data = fread($from, 65536);

                if ($data === false || $data === '') {
                    if (feof($from)) {
                        break 2;
                    }

                    continue;
                }

                if (! self::writeAll($from === $a ? $b : $a, $data)) {
                    break 2;
                }

                $last = time();
            }
        }

        @fclose($a);
        @fclose($b);
    }

    /**
     * @param  resource  $stream
     */
    private static function writeAll($stream, string $data): bool
    {
        while ($data !== '') {
            $written = @fwrite($stream, $data);

            if ($written === false || $written === 0) {
                return false;
            }

            $data = substr($data, $written);
        }

        return true;
    }

    /**
     * @param  resource  $client
     */
    private static function respond($client, int $status, string $text): void
    {
        self::writeAll($client, "HTTP/1.1 {$status} {$text}\r\nContent-Length: 0\r\nConnection: close\r\nX-EC-SUGAR-Egress: {$status}\r\n\r\n");
    }

    /**
     * 記録に 1 行（JSON）。名前・ポート・決めたこと・理由だけ
     *
     * @param  array<string, scalar|null>  $fields
     */
    private static function log(array $fields): void
    {
        fwrite(STDOUT, json_encode(['at' => gmdate('Y-m-d\TH:i:s\Z')] + $fields, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
    }
}

// 直に動かしたときだけ（テストが読み込んだときは動かさない）
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit((Proxy::fromEnvironment(__DIR__.'/allow.txt'))->run());
}
