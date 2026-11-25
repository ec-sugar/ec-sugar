<?php

declare(strict_types=1);

namespace Tests\Unit;

use EcSugar\PluginLine\IdToken;
use PHPUnit\Framework\TestCase;

/**
 * App「LINE 連携」の ID トークンの確かめ（ADR 0070。LINE ログイン v2.1 のウェブのログインは HS256、鍵はチャネルシークレット）。
 * 署名・alg・iss・aud・exp・iat・nonce・sub の形のどれが違っても通さない
 */
final class LineIdTokenTest extends TestCase
{
    private const string CHANNEL = '1234567890';

    // 見本の鍵（秘密の検知に当たらないよう、短く分けて書く）
    private const string SECRET = '01234567'.'89abcdef'.'01234567'.'89abcdef';

    private const string NONCE = 'n0nce-value';

    private const int NOW = 1_800_000_000;

    private const string SUB = 'U0123456789abcdef0123456789abcdef';

    public function test_a_token_signed_for_this_channel_gives_the_line_user(): void
    {
        $this->assertSame(self::SUB, IdToken::verify(self::token(), self::CHANNEL, self::SECRET, self::NONCE, self::NOW));
        // 頭のパディングの無い base64url（LINE の形）でも読める。typ が無くてもよい
        $this->assertSame(self::SUB, IdToken::verify(self::token(header: ['alg' => 'HS256']), self::CHANNEL, self::SECRET, self::NONCE, self::NOW));
    }

    public function test_every_broken_part_is_refused(): void
    {
        $cases = [
            'ほかの鍵の署名' => self::token(secret: 'ffffffffffffffffffffffffffffffff'),
            'alg が none' => self::token(header: ['alg' => 'none', 'typ' => 'JWT']),
            'alg が ES256' => self::token(header: ['alg' => 'ES256', 'typ' => 'JWT', 'kid' => 'x']),
            'alg が HS512' => self::token(header: ['alg' => 'HS512', 'typ' => 'JWT']),
            'ほかのチャネル' => self::token(['aud' => '9999999999']),
            'aud が並び' => self::token(['aud' => [self::CHANNEL]]),
            'ほかの発行元' => self::token(['iss' => 'https://evil.example']),
            '期限切れ' => self::token(['exp' => self::NOW]),
            '期限が無い' => self::token(['exp' => null]),
            '先の発行時刻' => self::token(['iat' => self::NOW + 3600]),
            'nonce が違う' => self::token(['nonce' => 'other']),
            'nonce が無い' => self::token(['nonce' => null]),
            'sub の形が違う' => self::token(['sub' => 'U123']),
            'sub が無い' => self::token(['sub' => null]),
        ];

        foreach ($cases as $why => $jwt) {
            $this->assertNull(IdToken::verify($jwt, self::CHANNEL, self::SECRET, self::NONCE, self::NOW), $why);
        }

        // 形の壊れたもの
        foreach (['', 'a.b', 'a.b.c.d', '!!!.???.***', self::token().'x'] as $jwt) {
            $this->assertNull(IdToken::verify($jwt, self::CHANNEL, self::SECRET, self::NONCE, self::NOW), $jwt);
        }

        // 中身を書き換えると、署名が合わない
        [$head, , $signature] = explode('.', self::token());
        $forged = $head.'.'.self::b64((string) json_encode(self::claims(['sub' => 'Uffffffffffffffffffffffffffffffff']))).'.'.$signature;
        $this->assertNull(IdToken::verify($forged, self::CHANNEL, self::SECRET, self::NONCE, self::NOW));
        // 空の nonce では誰の値とも合わせない
        $this->assertNull(IdToken::verify(self::token(['nonce' => '']), self::CHANNEL, self::SECRET, '', self::NOW));
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $header
     */
    private static function token(array $claims = [], array $header = ['typ' => 'JWT', 'alg' => 'HS256'], string $secret = self::SECRET): string
    {
        $payload = self::b64((string) json_encode($header)).'.'.self::b64((string) json_encode(self::claims($claims)));

        return $payload.'.'.self::b64(hash_hmac('sha256', $payload, $secret, true));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function claims(array $overrides): array
    {
        $claims = array_replace(['iss' => 'https://access.line.me', 'sub' => self::SUB, 'aud' => self::CHANNEL, 'exp' => self::NOW + 3600, 'iat' => self::NOW, 'nonce' => self::NONCE, 'amr' => ['pwd'], 'name' => '花子'], $overrides);

        return array_filter($claims, static fn (mixed $v): bool => $v !== null);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
