<?php

declare(strict_types=1);

namespace App\Admin\Agent;

use RuntimeException;

/**
 * OAuth の誤りの答え（RFC 6749 の 5.2・RFC 7591 の 3.2.2・RFC 8707）。error は決まった名前だけ、説明は道具の層が作った文だけ
 * （ライブラリの例外の文や、受け取った値をそのまま返さない）。説明（error_description）は RFC 6749 の決まりで ASCII だけなので英語で書く
 * （読むのはクライアントの道具。店の人に見せる文は許可の画面が日本語で出す）
 */
final class OAuthError extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly string $description,
        public readonly int $status = 400,
    ) {
        parent::__construct($error);
    }

    /**
     * @return array{error: string, error_description: string}
     */
    public function body(): array
    {
        return ['error' => $this->error, 'error_description' => $this->description];
    }
}
