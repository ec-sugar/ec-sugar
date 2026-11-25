<?php

declare(strict_types=1);

namespace App\Admin\Console;

use App\Admin\Services\HostChecks;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Console\Command;

/**
 * ホスト（サーバーの外）から見た状態を受け取る（ADR 0043）。HTTP の入口は無く、ホストの root が
 * ec-sugar（毎晩の更新の確かめ・更新・バックアップの終わり）から `docker compose exec app php artisan operations:host-report …` で呼ぶ。
 *
 *   --cert=<ホスト名>,<期限の時刻>     証明書の期限（ホスト名ごと。読めなかったら <ホスト名>,unreadable）
 *   --disk=<data|backup>,<空きの割合>,<空きのバイト数>   空き容量（data は Docker の置き場〔DB・写真〕、backup は /srv/ec-sugar）
 *   --update-result=<語> --update-at=<時刻> [--update-version=<コミット>]   最後の更新の結果
 *   --update-checked-at=<時刻>        最後に取得元（リモート）へ届いた時刻
 *   --update-auto=yes|no              自動の更新を入れているか（入れていなければ取得元の古さを見ない）
 *   --backup-result=<ok|failed> --backup-at=<時刻>   最後のバックアップの結果
 *   --tunnel=<語> --tunnel-at=<時刻>   Cloudflare Tunnel の見張りの結果（ok・foreign・down・unknown・unwatched。見直しの中 5）
 *   --integrity=<ok|mismatch> --integrity-at=<時刻> [--integrity-problem=<名前> …]
 *                                     中の照らしの結果（ADR 0105）。mismatch の時は、あるべき姿との違いの名前（image:app・file:compose など）を 1〜20 個
 *   --sentinel=<seen|silent> --sentinel-at=<時刻>   外の見張りが来ている・30 分来ていない（来たことがある時だけ）と、最後に来た時刻
 *   --offsite=<ok|failed> --offsite-tried-at=<時刻> [--offsite-at=<時刻>] [--offsite-reason=<語>] [--offsite-lock=<ok|open|unknown>]
 *   [--offsite-heads=<ok|failed> [--offsite-heads-at=<時刻>]]
 *                                     サーバーの外のバックアップ（ADR 0110。置き場を設定したサーバーだけ）。最後の回の結果と最後に試した時刻、
 *                                     最後にバックアップを送れた時刻、失敗の理由の決まった語（failed の時だけ）、置き場が上書きと削除を断ったか、連鎖の先頭
 *
 * 値は決まった形だけを受ける（ホスト名・時刻〔date -Is の形〕・整数・決まった語・40 桁のハッシュ）。自由な文は受けない。
 * 1 つでも形が違えば何も書かずに失敗で終わる（終わりの番号は 0 でない）。誤りの文に受け取った値は出さない（記録に余計な文字を流さない）。
 * 通れば前の報告を丸ごと置き換える
 */
final class HostReportCommand extends Command
{
    /** 1 回の報告に載せる証明書の数の上限 */
    public const int MAX_CERTIFICATES = 20;

    /** 証明書の期限として受ける範囲（年。今からこれより先は形の誤りとみなす） */
    public const int MAX_CERT_YEARS = 20;

    /** 結果の時刻として受ける先の範囲（時計のずれ。これより未来は断る） */
    public const int MAX_FUTURE_MINUTES = 60;

    /** 空きのバイト数の上限（1 EiB） */
    public const int MAX_BYTES = 1 << 60;

    public const array UPDATE_RESULTS = ['ok', 'rolled_back', 'failed', 'stopped', 'manual_rollback'];

    public const array BACKUP_RESULTS = ['ok', 'failed'];

    public const array DISKS = ['data', 'backup'];

    /**
     * トンネルの見張りの結果: ok（どの接続役もこのサーバーから）・foreign（よそからつながっている）・down（接続役が 0）・
     * unknown（確かめられない）・unwatched（読むだけの鍵が無く見ていない）
     */
    public const array TUNNEL_RESULTS = ['ok', 'foreign', 'down', 'unknown', 'unwatched'];

    /** 中の照らしの結果: ok（あるべき姿のまま）・mismatch（違いがある） */
    public const array INTEGRITY_RESULTS = ['ok', 'mismatch'];

    /** 外の見張りの様子: seen（30 分の内に来た）・silent（来たことはあるが 30 分来ていない） */
    public const array SENTINEL_RESULTS = ['seen', 'silent'];

    /** サーバーの外のバックアップの最後の回: ok（送れた）・failed（送れなかった） */
    public const array OFFSITE_RESULTS = ['ok', 'failed'];

    /**
     * サーバーの外へ送れなかった理由（ホストの EC_OFFSITE_REASONS と同じ並び）: config（設定が読めない）・curl-old（curl が古い）・no-gpg（gpg が無い）・
     * no-recipient（公開鍵が無い）・bad-recipient（公開鍵で暗号化できない）・put（置き場に置けない）・gave-up（回数の上限まで試した）
     */
    public const array OFFSITE_REASONS = ['config', 'curl-old', 'no-gpg', 'no-recipient', 'bad-recipient', 'put', 'gave-up'];

    /** 置き場のロックの確かめ: ok（上書きと削除を断った）・open（どちらかが通った）・unknown（確かめられなかった） */
    public const array OFFSITE_LOCKS = ['ok', 'open', 'unknown'];

    /** 記録の連鎖の先頭: ok（送れた）・failed（送れなかった） */
    public const array OFFSITE_HEADS = ['ok', 'failed'];

    /** 1 回の報告に載せる違いの名前の数の上限（ホストの EC_INTEGRITY_MAX_PROBLEMS と同じ） */
    public const int MAX_INTEGRITY_PROBLEMS = 20;

    /** 違いの名前の形（ホストの ec_integrity_valid_problem と同じ。小文字の英数字と . _ - を : でつなぐ。値・パスは入らない） */
    public const string INTEGRITY_PROBLEM = '/\A[a-z][a-z0-9-]*(:[a-z0-9][a-z0-9._-]*){1,2}\z/';

    protected $signature = 'operations:host-report
        {--cert=* : 証明書の期限（<ホスト名>,<期限の時刻> か <ホスト名>,unreadable）}
        {--disk=* : 空き容量（<data|backup>,<空きの割合>,<空きのバイト数>）}
        {--update-result= : 最後の更新の結果（ok・rolled_back・failed・stopped・manual_rollback）}
        {--update-at= : 最後の更新の時刻}
        {--update-version= : 最後の更新で入れようとしたバージョン（コミットのハッシュ 40 桁）}
        {--update-checked-at= : 最後に取得元へ届いた時刻}
        {--update-auto= : 自動の更新を入れているか（yes・no）}
        {--backup-result= : 最後のバックアップの結果（ok・failed）}
        {--backup-at= : 最後のバックアップの時刻}
        {--tunnel= : Cloudflare Tunnel の見張りの結果（ok・foreign・down・unknown・unwatched）}
        {--tunnel-at= : Cloudflare Tunnel の見張りの時刻}
        {--integrity= : 中の照らしの結果（ok・mismatch）}
        {--integrity-at= : 中の照らしの結果が変わった時刻}
        {--integrity-problem=* : 基準の状態との違いの名前（image:app・file:compose など。mismatch の時だけ）}
        {--sentinel= : 外部監視の状況（seen・silent）}
        {--sentinel-at= : 外部監視が最後に来た時刻}
        {--offsite= : サーバーの外のバックアップの最後の回（ok・failed）}
        {--offsite-tried-at= : サーバーの外へ最後に送ろうとした時刻}
        {--offsite-at= : サーバーの外へ最後にバックアップを送れた時刻}
        {--offsite-reason= : サーバーの外へ送れなかった理由（config・curl-old・no-gpg・no-recipient・bad-recipient・put・gave-up。failed の時だけ）}
        {--offsite-lock= : 保存先が上書きと削除を断ったか（ok・open・unknown）}
        {--offsite-heads= : 記録の連鎖の先頭をサーバーの外へ送れたか（ok・failed）}
        {--offsite-heads-at= : 記録の連鎖の先頭を最後に送れた時刻}';

    protected $description = 'ホスト（ec-sugar）から見た状態（証明書の期限・空き容量・更新・バックアップ・トンネルの監視・内部の整合性チェック・サーバーの外のバックアップ）を受け取り、運営ダッシュボードに出す';

    /** @var list<string> */
    private array $problems = [];

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $rows = [...$this->certificates($now), ...$this->disks()];
        $update = $this->update($now);
        $backup = $this->backup($now);
        $tunnel = $this->tunnel($now);
        array_push($rows, ...$this->integrity($now));
        $sentinel = $this->sentinel($now);
        array_push($rows, ...$this->offsite($now));

        if ($sentinel !== null) {
            $rows[] = $sentinel;
        }

        if ($update !== null) {
            $rows[] = $update;
        }

        if ($backup !== null) {
            $rows[] = $backup;
        }

        if ($tunnel !== null) {
            $rows[] = $tunnel;
        }

        if ($this->problems === [] && $rows === []) {
            $this->problems[] = '報告する値がありません（--cert・--disk・--update-…・--backup-…・--tunnel…・--integrity…・--sentinel…・--offsite… のどれかを渡す）';
        }

        if ($this->problems !== []) {
            foreach ($this->problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }

        HostChecks::replace($rows, $now);
        $this->info('ホストの報告を受け取りました（'.count($rows).' 項目）');

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function certificates(CarbonImmutable $now): array
    {
        $values = $this->list('cert');

        if (count($values) > self::MAX_CERTIFICATES) {
            $this->problems[] = '--cert は '.self::MAX_CERTIFICATES.' 個までです';

            return [];
        }

        $rows = [];

        foreach ($values as $index => $value) {
            $label = '--cert の '.($index + 1).' 個目';
            $parts = explode(',', $value);

            if (count($parts) !== 2 || ! self::isHost($parts[0])) {
                $this->problems[] = "{$label}の形が違います（<ホスト名>,<期限の時刻> か <ホスト名>,unreadable）";

                continue;
            }

            if (isset($rows[$parts[0]])) {
                $this->problems[] = "{$label}のホスト名が前と重なっています";

                continue;
            }

            if ($parts[1] === 'unreadable') {
                $rows[$parts[0]] = ['kind' => 'certificate', 'name' => $parts[0], 'result' => 'unreadable', 'at' => null];

                continue;
            }

            $expires = self::time($parts[1]);

            if ($expires === null || $expires->lessThan(CarbonImmutable::create(2000)) || $expires->greaterThan($now->addYears(self::MAX_CERT_YEARS))) {
                $this->problems[] = "{$label}の期限の時刻の形か範囲が違います";

                continue;
            }

            $rows[$parts[0]] = ['kind' => 'certificate', 'name' => $parts[0], 'result' => null, 'at' => $expires->utc()];
        }

        return array_values($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function disks(): array
    {
        $rows = [];

        foreach ($this->list('disk') as $index => $value) {
            $label = '--disk の '.($index + 1).' 個目';

            // 割合は 0〜100、バイト数は 0 以上の整数（先頭の 0 や符号・小数は断る）
            if (preg_match('/\A(data|backup),(0|[1-9][0-9]{0,2}),(0|[1-9][0-9]{0,18})\z/', $value, $match) !== 1
                || (int) $match[2] > 100 || strlen($match[3]) > 19 || (int) $match[3] > self::MAX_BYTES || $match[3] !== (string) (int) $match[3]) {
                $this->problems[] = "{$label}の形か範囲が違います（<data|backup>,<空きの割合 0〜100>,<空きのバイト数>）";

                continue;
            }

            if (isset($rows[$match[1]])) {
                $this->problems[] = "{$label}の保存先が前と重なっています";

                continue;
            }

            $rows[$match[1]] = ['kind' => 'disk', 'name' => $match[1], 'free_percent' => (int) $match[2], 'free_bytes' => (int) $match[3]];
        }

        return array_values($rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function update(CarbonImmutable $now): ?array
    {
        $result = $this->text('update-result');
        $at = $this->text('update-at');
        $version = $this->text('update-version');
        $checked = $this->text('update-checked-at');
        $auto = $this->text('update-auto');

        if ($result === null && $at === null && $version === null && $checked === null && $auto === null) {
            return null;
        }

        // name は種類ごとに意味が違う列。更新の行では「自動の更新を入れているか」を入れる（空 = 分からない。古い台本からの報告）
        $row = ['kind' => 'update', 'name' => '', 'result' => null, 'at' => null, 'checked_at' => null, 'version' => null];

        if ($auto !== null) {
            if (! in_array($auto, ['yes', 'no'], true)) {
                $this->problems[] = '--update-auto は yes か no です';
            } else {
                $row['name'] = $auto;
            }
        }

        if ($result !== null || $at !== null || $version !== null) {
            if ($result === null || ! in_array($result, self::UPDATE_RESULTS, true)) {
                $this->problems[] = '--update-result は '.implode('・', self::UPDATE_RESULTS).' のどれかです（--update-at と組で渡す）';
            }

            $row['result'] = $result;
            $row['at'] = $this->pastTime('--update-at', $at, $now);

            if ($version !== null && preg_match('/\A[0-9a-f]{40}\z/', $version) !== 1) {
                $this->problems[] = '--update-version はコミットのハッシュ（小文字の 16 進 40 桁）です';
            }

            $row['version'] = $version;
        }

        if ($checked !== null) {
            $row['checked_at'] = $this->pastTime('--update-checked-at', $checked, $now);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function backup(CarbonImmutable $now): ?array
    {
        $result = $this->text('backup-result');
        $at = $this->text('backup-at');

        if ($result === null && $at === null) {
            return null;
        }

        if ($result === null || ! in_array($result, self::BACKUP_RESULTS, true)) {
            $this->problems[] = '--backup-result は '.implode('・', self::BACKUP_RESULTS).' のどれかです（--backup-at と組で渡す）';
        }

        return ['kind' => 'backup', 'name' => '', 'result' => $result, 'at' => $this->pastTime('--backup-at', $at, $now)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tunnel(CarbonImmutable $now): ?array
    {
        $result = $this->text('tunnel');
        $at = $this->text('tunnel-at');

        if ($result === null && $at === null) {
            return null;
        }

        if ($result === null || ! in_array($result, self::TUNNEL_RESULTS, true)) {
            $this->problems[] = '--tunnel は '.implode('・', self::TUNNEL_RESULTS).' のどれかです（--tunnel-at と組で渡す）';
        }

        return ['kind' => 'tunnel', 'name' => '', 'result' => $result, 'at' => $this->pastTime('--tunnel-at', $at, $now)];
    }

    /**
     * 中の照らし（ADR 0105）。結果の行（name は空）と、違いの名前ごとの行（result は mismatch）
     *
     * @return list<array<string, mixed>>
     */
    private function integrity(CarbonImmutable $now): array
    {
        $result = $this->text('integrity');
        $at = $this->text('integrity-at');
        $names = $this->list('integrity-problem');

        if ($result === null && $at === null && $names === []) {
            return [];
        }

        if ($result === null || ! in_array($result, self::INTEGRITY_RESULTS, true)) {
            $this->problems[] = '--integrity は '.implode('・', self::INTEGRITY_RESULTS).' のどれかです（--integrity-at と組で渡す）';

            return [];
        }

        $time = $this->pastTime('--integrity-at', $at, $now);

        if ($result === 'ok' && $names !== []) {
            $this->problems[] = '--integrity-problem は --integrity=mismatch の時だけ渡します';
        }

        if ($result === 'mismatch' && $names === []) {
            $this->problems[] = '--integrity=mismatch の時は、違いの名前を --integrity-problem で 1 つ以上渡します';
        }

        if (count($names) > self::MAX_INTEGRITY_PROBLEMS) {
            $this->problems[] = '--integrity-problem は '.self::MAX_INTEGRITY_PROBLEMS.' 個までです';

            return [];
        }

        $rows = [['kind' => 'integrity', 'name' => '', 'result' => $result, 'at' => $time]];

        foreach ($names as $index => $name) {
            if (strlen($name) > 100 || preg_match(self::INTEGRITY_PROBLEM, $name) !== 1) {
                $this->problems[] = '--integrity-problem の '.($index + 1).' 個目の形が違います（image:app・file:compose のような名前だけ）';

                continue;
            }

            if (isset($rows[$name])) {
                $this->problems[] = '--integrity-problem の '.($index + 1).' 個目が前と重なっています';

                continue;
            }

            $rows[$name] = ['kind' => 'integrity', 'name' => $name, 'result' => 'mismatch', 'at' => $time];
        }

        return array_values($rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sentinel(CarbonImmutable $now): ?array
    {
        $result = $this->text('sentinel');
        $at = $this->text('sentinel-at');

        if ($result === null && $at === null) {
            return null;
        }

        if ($result === null || ! in_array($result, self::SENTINEL_RESULTS, true)) {
            $this->problems[] = '--sentinel は '.implode('・', self::SENTINEL_RESULTS).' のどれかです（--sentinel-at と組で渡す）';
        }

        return ['kind' => 'sentinel', 'name' => '', 'result' => $result, 'at' => $this->pastTime('--sentinel-at', $at, $now)];
    }

    /**
     * サーバーの外のバックアップ（ADR 0110）。最後の回の行（name は空。at は最後に送れた時刻、checked_at は最後に試した時刻）と、
     * 理由（reason）・置き場のロック（lock）・連鎖の先頭（heads）の行。どれも決まった語と時刻だけ（入口・バケット・鍵・バックアップの名前は受けない）
     *
     * @return list<array<string, mixed>>
     */
    private function offsite(CarbonImmutable $now): array
    {
        $result = $this->text('offsite');
        $tried = $this->text('offsite-tried-at');
        $at = $this->text('offsite-at');
        $reason = $this->text('offsite-reason');
        $lock = $this->text('offsite-lock');
        $heads = $this->text('offsite-heads');
        $headsAt = $this->text('offsite-heads-at');

        if ($result === null && $tried === null && $at === null && $reason === null && $lock === null && $heads === null && $headsAt === null) {
            return [];
        }

        if ($result === null || ! in_array($result, self::OFFSITE_RESULTS, true)) {
            $this->problems[] = '--offsite は '.implode('・', self::OFFSITE_RESULTS).' のどれかです（--offsite-tried-at と組で渡す。ほかの --offsite-… だけでは渡せない）';

            return [];
        }

        $rows = [[
            'kind' => 'offsite', 'name' => '', 'result' => $result,
            'at' => $at === null ? null : $this->pastTime('--offsite-at', $at, $now),
            'checked_at' => $this->pastTime('--offsite-tried-at', $tried, $now),
        ]];

        if ($reason !== null) {
            if ($result !== 'failed') {
                $this->problems[] = '--offsite-reason は --offsite=failed の時だけ渡します';
            } elseif (! in_array($reason, self::OFFSITE_REASONS, true)) {
                $this->problems[] = '--offsite-reason は '.implode('・', self::OFFSITE_REASONS).' のどれかです';
            } else {
                $rows[] = ['kind' => 'offsite', 'name' => 'reason', 'result' => $reason];
            }
        }

        if ($lock !== null) {
            if (! in_array($lock, self::OFFSITE_LOCKS, true)) {
                $this->problems[] = '--offsite-lock は '.implode('・', self::OFFSITE_LOCKS).' のどれかです';
            } else {
                $rows[] = ['kind' => 'offsite', 'name' => 'lock', 'result' => $lock];
            }
        }

        if ($heads !== null || $headsAt !== null) {
            if ($heads === null || ! in_array($heads, self::OFFSITE_HEADS, true)) {
                $this->problems[] = '--offsite-heads は '.implode('・', self::OFFSITE_HEADS).' のどれかです（--offsite-heads-at だけでは渡せない）';
            } else {
                $rows[] = ['kind' => 'offsite', 'name' => 'heads', 'result' => $heads, 'at' => $headsAt === null ? null : $this->pastTime('--offsite-heads-at', $headsAt, $now)];
            }
        }

        return $rows;
    }

    /**
     * 起きたことの時刻（2020 年より後で、今より先でない。時計のずれは 1 時間まで許す）。形が違えば problems に足して null
     */
    private function pastTime(string $option, ?string $value, CarbonImmutable $now): ?CarbonImmutable
    {
        $time = $value === null ? null : self::time($value);

        if ($time === null || $time->lessThan(CarbonImmutable::create(2020)) || $time->greaterThan($now->addMinutes(self::MAX_FUTURE_MINUTES))) {
            $this->problems[] = "{$option} の時刻の形か範囲が違います（例 2026-10-30T03:31:02+09:00）";

            return null;
        }

        return $time->utc();
    }

    /**
     * date -Is の形（2026-10-30T03:31:02+09:00）か UTC の Z（2026-10-30T03:31:02Z）だけ。13 月や 25 時のような繰り上がる値は断る
     */
    private static function time(string $value): ?CarbonImmutable
    {
        if (preg_match('/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(Z|[+-]\d{2}:\d{2})\z/', $value, $match) !== 1) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $match[1].($match[2] === 'Z' ? '+00:00' : $match[2]));

        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s') !== $match[1]) {
            return null;
        }

        return CarbonImmutable::instance($parsed);
    }

    /**
     * ホスト名（ec_valid_host と同じ決まり: 小文字の英数字とハイフンのラベルを点でつなぎ、末尾は英字で始まるラベル。253 文字まで）
     */
    private static function isHost(string $value): bool
    {
        return strlen($value) <= 253
            && preg_match('/\A([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]\z/', $value) === 1;
    }

    /**
     * 繰り返す選択肢の値（文字の並びだけ）
     *
     * @return list<string>
     */
    private function list(string $option): array
    {
        $values = $this->option($option);

        return array_values(array_map(static fn (mixed $value): string => is_string($value) ? $value : '', is_array($values) ? $values : []));
    }

    private function text(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) ? $value : null;
    }
}
