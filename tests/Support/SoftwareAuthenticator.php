<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * テスト用のソフトウェア認証器（WebAuthn、ES256、attestation は none）。
 * ブラウザが navigator.credentials.create / get で返す JSON と同じ形を作り、サーバー側の検証を本物のまま通す。
 * 失敗系を作るため、origin・本人確認フラグ・署名カウンタを差し替えられる。
 */
final class SoftwareAuthenticator
{
    public readonly string $credentialId;

    private OpenSSLAsymmetricKey $key;

    private int $counter = 0;

    /**
     * @param  bool  $synced  同期のパスキー（iCloud キーチェーンなど）のふりをする。登録のときに「写せる」「写した」の印（BE・BS）を立てる
     */
    public function __construct(private readonly string $rpId, private readonly string $origin, private readonly bool $synced = false)
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($key === false) {
            throw new RuntimeException('鍵を作れませんでした');
        }

        $this->key = $key;
        $this->credentialId = random_bytes(32);
    }

    /**
     * @param  array<string, mixed>  $options  サーバーが返した登録オプション（options）
     * @return array<string, mixed>
     */
    public function register(array $options): array
    {
        $clientData = $this->clientData('webauthn.create', (string) $options['challenge'], $this->origin);

        $authData = hash('sha256', $this->rpId, true)
            .chr($this->synced ? 0x5D : 0x45)            // UP | UV | AT（同期なら BE | BS も）
            .pack('N', 0)
            .str_repeat("\0", 16)                        // AAGUID（不明）
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .$this->coseKey();

        $attestation = self::cbor([
            ['fmt', 'none'],
            ['attStmt', []],
            ['authData', new CborBytes($authData)],
        ]);

        return [
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'attestationObject' => self::b64($attestation),
                'transports' => ['internal'],
            ],
            'clientExtensionResults' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $options  サーバーが返した検証オプション（options）
     * @return array<string, mixed>
     */
    public function assert(array $options, string $userHandle, ?string $origin = null, bool $userVerified = true, ?int $counter = null): array
    {
        $this->counter = $counter ?? $this->counter + 1;
        $clientData = $this->clientData('webauthn.get', (string) $options['challenge'], $origin ?? $this->origin);
        $flags = ($userVerified ? 0x05 : 0x01) | ($this->synced ? 0x18 : 0x00); // UP | UV（同期なら BE | BS も）
        $authData = hash('sha256', $this->rpId, true).chr($flags).pack('N', $this->counter);

        if (! openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('署名できませんでした');
        }

        return [
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'authenticatorData' => self::b64($authData),
                'signature' => self::b64($signature),
                'userHandle' => self::b64($userHandle),
            ],
            'clientExtensionResults' => [],
        ];
    }

    private function clientData(string $type, string $challenge, string $origin): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->key);

        if ($details === false) {
            throw new RuntimeException('公開鍵を取り出せませんでした');
        }

        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        // kty: EC2, alg: ES256, crv: P-256
        return self::cbor([[1, 2], [3, -7], [-1, 1], [-2, new CborBytes($x)], [-3, new CborBytes($y)]]);
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * 必要な分だけの CBOR（整数・バイト列・文字列・マップ）。マップは [キー, 値] の組の列で渡す。
     *
     * @param  list<array{0: int|string, 1: mixed}>  $pairs
     */
    private static function cbor(array $pairs): string
    {
        $out = self::head(5, count($pairs));

        foreach ($pairs as [$k, $v]) {
            $out .= self::item($k).self::item($v);
        }

        return $out;
    }

    private static function item(mixed $value): string
    {
        return match (true) {
            $value instanceof CborBytes => self::head(2, strlen($value->bytes)).$value->bytes,
            is_int($value) && $value >= 0 => self::head(0, $value),
            is_int($value) => self::head(1, -1 - $value),
            is_string($value) => self::head(3, strlen($value)).$value,
            $value === [] => self::head(5, 0),
            default => throw new RuntimeException('未対応の CBOR の値です'),
        };
    }

    private static function head(int $major, int $length): string
    {
        return match (true) {
            $length < 24 => chr(($major << 5) | $length),
            $length < 256 => chr(($major << 5) | 24).chr($length),
            $length < 65536 => chr(($major << 5) | 25).pack('n', $length),
            default => chr(($major << 5) | 26).pack('N', $length),
        };
    }
}
