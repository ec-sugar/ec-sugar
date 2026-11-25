<?php

declare(strict_types=1);

namespace EcSugar\PluginLine;

/**
 * LINE ログインで確かめた利用者（ADR 0070）。sub は ID トークンの署名を確かめた後の LINE の利用者の ID。
 * purpose と account は、始めた時にこのブラウザのセッションに置いた値（ログインか、ログインした会員がつなぐか）
 */
final readonly class LineIdentity
{
    /**
     * @param  'login'|'link'  $purpose
     */
    public function __construct(
        public string $sub,
        public bool $friend,
        public string $purpose,
        public ?string $account,
    ) {}
}
