<?php

declare(strict_types=1);

namespace App\Admin\Services;

use App\Admin\Models\Operator;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * 管理画面で作る復元のキット（ADR 0111）。
 *
 * キット（APP_KEY と、サーバーの外のバックアップを開ける鍵を、合い言葉で暗号化した 1 つのファイル）はオーナーのブラウザの中で作る。
 * サーバーがするのは 3 つだけ:
 *
 * 1. APP_KEY をオーナーの画面へ渡す（material）。オーナーだけ・直前のパスキーの再確認の後（経路の印）。渡すたびに監査ログに残す
 * 2. ブラウザが保存したキットを開け直して確かめた後に、開ける鍵の公開の半分を受け取る（register）。
 *    開け直したキットの APP_KEY から作った印（proof）が合う時だけ受ける（保存したファイルと合い言葉で本当に戻せる、を機械で確かめる）
 * 3. ホストの ec-sugar に、暗号化の相手を答える（recipient）
 *
 * キットの合い言葉・開ける鍵の秘密の半分・キットそのものは、サーバーへ来ない・置かない。
 */
final class RecoveryKit
{
    /** 監査ログの種類: APP_KEY を画面へ渡した */
    public const string AUDIT_MATERIAL = 'operations.recovery_kit_material_issued';

    /** 監査ログの種類: キットを確かめて、開ける鍵の公開の半分を受け取った */
    public const string AUDIT_REGISTERED = 'operations.recovery_kit_registered';

    /** 印（proof）の文の頭。ブラウザの側（resources/admin/recovery-kit.js）と同じ */
    public const string PROOF_PREFIX = 'ec-sugar-recovery-kit:';

    private const int KEY_MAX = 8192;

    private const string ARMOR_HEAD = '-----BEGIN PGP PUBLIC KEY BLOCK-----';

    private const string ARMOR_TAIL = '-----END PGP PUBLIC KEY BLOCK-----';

    /**
     * キットに入れる APP_KEY（base64:… の 1 行）。キットの道具（deploy/recovery-kit）が受ける形でなければ null
     */
    public static function appKey(): ?string
    {
        $key = config('app.key');

        return is_string($key) && preg_match('/\Abase64:[A-Za-z0-9+\/]{43}=\z/', $key) === 1 ? $key : null;
    }

    /**
     * APP_KEY を画面へ渡したことを監査ログに残して、渡す値を返す。形が違えば null（何も残さない）
     *
     * @return array{app_key: string, server: string}|null
     */
    public static function material(Operator $operator, AuditLog $audit, string $server, ?string $ip = null): ?array
    {
        if (tenant() !== null) {
            throw new LogicException('復元のキットは店の文脈の外で扱う');
        }

        $key = self::appKey();

        if ($key === null || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/', $server) !== 1 || str_contains($server, '..')) {
            return null;
        }

        // 渡す前に残す（残せなければ渡さない）。中身は空（鍵・指紋は載せない）
        $audit->record(self::AUDIT_MATERIAL, $operator, [], null, $ip);

        return ['app_key' => $key, 'server' => $server];
    }

    /**
     * 開け直したキットの APP_KEY と指紋から作る印。ブラウザが同じ式で作って送る
     */
    public static function proof(string $appKey, string $fingerprint): string
    {
        return hash_hmac('sha256', self::PROOF_PREFIX.$fingerprint, $appKey);
    }

    /**
     * 公開鍵の形を確かめて、鍵そのものから指紋を数える（送られた指紋を信じない）。受けられない形は例外
     *
     * 受けるのは、OpenPGP の版 4 の公開鍵の ASCII の包み 1 つ。秘密の鍵の包み・長すぎるもの・包みの外に字があるものは断る。
     * 暗号化に使える鍵か・1 つだけかは、採る時にホストの gpg が確かめる（ec_offsite_recipient_ok）
     */
    public static function fingerprintOf(string $publicKey): string
    {
        $text = str_replace("\r\n", "\n", trim($publicKey));

        // 長さは DB の制約（200〜8192 字。最後に改行を 1 つ足して置く）と同じ決まり。ここで断らないと、制約の違反が 500 になる
        if (strlen($text) < 200 || strlen($text) >= self::KEY_MAX || ! str_starts_with($text, self::ARMOR_HEAD."\n") || ! str_ends_with($text, "\n".self::ARMOR_TAIL)
            || substr_count($text, '-----BEGIN ') !== 1 || preg_match('/[^\x0a\x20-\x7e]/', $text) === 1) {
            throw new InvalidArgumentException('公開鍵の包みの形が違います');
        }

        $inner = substr($text, strlen(self::ARMOR_HEAD) + 1, -strlen(self::ARMOR_TAIL) - 1);
        // 見出しの行（Version: など）と空の行の後が本体。最後の =xxxx は検査の和（無い実装もある）
        $parts = explode("\n\n", $inner, 2);
        $body = count($parts) === 2 ? $parts[1] : $inner;
        $lines = array_values(array_filter(explode("\n", $body), static fn (string $line): bool => $line !== '' && $line[0] !== '='));
        $base64 = implode('', $lines);

        if ($base64 === '' || preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/', $base64) !== 1) {
            throw new InvalidArgumentException('公開鍵の中身が base64 ではありません');
        }

        $binary = base64_decode($base64, true);

        if ($binary === false || strlen($binary) < 12) {
            throw new InvalidArgumentException('公開鍵の中身を読めません');
        }

        // 最初のパケットが公開鍵（札 6）であること。長さの書き方は新旧の 2 通り
        $head = ord($binary[0]);

        if (($head & 0x80) === 0) {
            throw new InvalidArgumentException('公開鍵のパケットではありません');
        }

        if (($head & 0x40) !== 0) {
            $tag = $head & 0x3F;
            $first = ord($binary[1]);
            [$length, $offset] = match (true) {
                $first < 192 => [$first, 2],
                $first < 224 => [(($first - 192) << 8) + ord($binary[2]) + 192, 3],
                $first === 255 => [unpack('N', substr($binary, 2, 4))[1], 6],
                default => [-1, 0],
            };
        } else {
            $tag = ($head & 0x3C) >> 2;
            [$length, $offset] = match ($head & 0x03) {
                0 => [ord($binary[1]), 2],
                1 => [unpack('n', substr($binary, 1, 2))[1], 3],
                2 => [unpack('N', substr($binary, 1, 4))[1], 5],
                default => [-1, 0],
            };
        }

        if ($tag !== 6 || $length < 6 || $length > 0xFFFF || $offset + $length > strlen($binary)) {
            throw new InvalidArgumentException('公開鍵のパケットの形が違います');
        }

        $packet = substr($binary, $offset, $length);

        if (ord($packet[0]) !== 4) {
            throw new InvalidArgumentException('公開鍵の版が違います（版 4 だけ）');
        }

        return strtoupper(sha1("\x99".pack('n', $length).$packet));
    }

    /**
     * 確かめ終えたキットの、開ける鍵の公開の半分を受け取る。印が合わない・形が違う時は false（何も残さない）。
     * 受けたら、同じトランザクションで APP_KEY の控えの答え（AppKeyBackup）と監査ログも残す
     */
    public static function register(Operator $operator, string $publicKey, string $fingerprint, string $proof, CarbonImmutable $now, AuditLog $audit, ?string $ip = null): bool
    {
        if (tenant() !== null) {
            throw new LogicException('復元のキットは店の文脈の外で扱う');
        }

        $appKey = self::appKey();

        try {
            $counted = self::fingerprintOf($publicKey);
        } catch (InvalidArgumentException) {
            return false;
        }

        if ($appKey === null || ! hash_equals($counted, $fingerprint) || ! hash_equals(self::proof($appKey, $counted), $proof)) {
            return false;
        }

        $central = self::central();
        $text = str_replace("\r\n", "\n", trim($publicKey))."\n";

        $central->transaction(static function () use ($central, $operator, $counted, $text, $now, $audit, $ip): void {
            // 同時に 2 人が送っても順に並べる（1 行だけの表。後の方が残る）
            $central->statement('LOCK TABLE recovery_kit_recipients IN EXCLUSIVE MODE');
            $before = $central->table('recovery_kit_recipients')->where('id', 1)->value('fingerprint');
            // 控えの答えを先に残す（AppKeyBackup::confirm だけを押した人と、錠を取る順をそろえる: 答えの行 → 監査ログ。逆だと同時の時に片方が落ちる）
            AppKeyBackup::confirm($operator, $now, $audit, $ip);

            if ($before !== $counted) {
                $central->table('recovery_kit_recipients')->where('id', 1)->delete();
                $central->table('recovery_kit_recipients')->insert([
                    'id' => 1, 'fingerprint' => $counted, 'public_key' => $text, 'registered_at' => $now->utc(), 'operator_id' => $operator->id,
                ]);
                // 指紋は公開してよい値（公開鍵から誰でも数えられる）。前の指紋も残す（どの鍵からどの鍵へ替わったか）
                $audit->record(self::AUDIT_REGISTERED, $operator, ['fingerprint' => $counted, 'replaced' => is_string($before) ? $before : ''], null, $ip);
            }
        });

        return true;
    }

    /**
     * いまの開ける鍵の公開の半分（無ければ null）
     *
     * @return array{fingerprint: string, public_key: string, registered_at: CarbonImmutable}|null
     */
    public static function recipient(): ?array
    {
        $row = self::central()->table('recovery_kit_recipients')->where('id', 1)->first();

        return $row === null ? null : [
            'fingerprint' => (string) $row->fingerprint,
            'public_key' => (string) $row->public_key,
            'registered_at' => CarbonImmutable::parse((string) $row->registered_at),
        ];
    }

    /**
     * ホストの ec-sugar が、いまバックアップの暗号化に使っている公開鍵の指紋を知らせる（operations:recovery-recipient --host-fingerprint）。
     * 画面が、キットの鍵と同じかを比べるために持つ（違えば、そのキットではサーバーの外のバックアップを開けない）。形が違えば false
     */
    public static function reportHostFingerprint(string $fingerprint, CarbonImmutable $now): bool
    {
        if (preg_match('/\A[0-9A-F]{40,64}\z/', $fingerprint) !== 1) {
            return false;
        }

        self::central()->table('recovery_kit_host_keys')->upsert(
            [['id' => 1, 'fingerprint' => $fingerprint, 'reported_at' => $now->utc()]], ['id'], ['fingerprint', 'reported_at'],
        );

        return true;
    }

    /**
     * ホストが最後に知らせた公開鍵の指紋（知らせが無ければ null。置き場を設定していないサーバー・公開鍵をまだ置いていないサーバー）
     */
    public static function hostFingerprint(): ?string
    {
        $value = self::central()->table('recovery_kit_host_keys')->where('id', 1)->value('fingerprint');

        return is_string($value) ? $value : null;
    }

    private static function central(): ConnectionInterface
    {
        return DB::connection(ShopDigests::central());
    }
}
