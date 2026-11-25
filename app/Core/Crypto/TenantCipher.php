<?php

declare(strict_types=1);

namespace App\Core\Crypto;

use Closure;
use RuntimeException;

/**
 * テナント鍵で個人情報の列を暗号化する（ADR 0010）。
 *
 * 鍵の置き場所を 3 層に分ける:
 *   テナント DB   … 暗号文だけ
 *   中央 DB       … APP_KEY で包んだテナント鍵（App\Models\Tenant::data_key、encrypted キャスト）
 *   環境変数      … APP_KEY
 * 3 つすべてを取られて初めて復号できる。1 テナントの鍵が漏れても他テナントには及ばない。
 *
 * 方式: XChaCha20-Poly1305（libsodium）。形式は "te1:" + base64(nonce + 暗号文)。
 * ブラインドインデックスは HKDF で派生した別鍵による HMAC-SHA256（完全一致検索専用）。
 */
final class TenantCipher
{
    public const string PREFIX = 'te1:';

    // 鍵を派生する文脈の名前は旧名 SUGAR-EC のまま。変えると保存したブラインドインデックスと記録の封印が合わなくなる（で変えないと決めた）
    private const string INDEX_CONTEXT = 'sugar-ec.blind-index.v1';

    private const string SEAL_CONTEXT = 'sugar-ec.event-chain.v1';

    /**
     * @param  Closure(): string  $keyResolver  現在のテナントの生鍵（32 バイト）を返す。テナント文脈の外では例外
     */
    public function __construct(private readonly Closure $keyResolver) {}

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, '', $nonce, $this->key());

        return self::PREFIX.base64_encode($nonce.$cipher);
    }

    public function decrypt(string $payload): string
    {
        if (! str_starts_with($payload, self::PREFIX)) {
            throw new RuntimeException('暗号文の形式が不正です（既知のプレフィックスではない）');
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if ($raw === false || strlen($raw) <= $nonceLength) {
            throw new RuntimeException('暗号文の形式が不正です');
        }

        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw, $nonceLength), '', substr($raw, 0, $nonceLength), $this->key(),
        );

        if ($plain === false) {
            throw new RuntimeException('復号に失敗しました（鍵が違うか、暗号文が改竄されています）');
        }

        return $plain;
    }

    /**
     * 正規化済みの値からブラインドインデックス（64 桁の hex）を作る。
     */
    public function index(string $normalized): string
    {
        return hash_hmac('sha256', $normalized, $this->indexKey());
    }

    /**
     * 改竄検知用の印（64 桁の hex）。ドメインイベントのハッシュ連鎖に使う（ADR 0012）。
     * 鍵はインデックスとも暗号化とも別に HKDF で派生する。鍵を持たない者は連鎖を作り直せない。
     */
    public function seal(string $data): string
    {
        return hash_hmac('sha256', $data, hash_hkdf('sha256', $this->key(), 32, self::SEAL_CONTEXT));
    }

    private function key(): string
    {
        $key = ($this->keyResolver)();

        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('テナント鍵の長さが不正です');
        }

        return $key;
    }

    private function indexKey(): string
    {
        return hash_hkdf('sha256', $this->key(), 32, self::INDEX_CONTEXT);
    }
}
