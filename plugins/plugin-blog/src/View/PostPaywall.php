<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog\View;

/**
 * 記事の「ここから先は有料」の案内（テーマ API の一部）。`PostView::$paywall` に、
 * **有料の分を落としたときだけ**入る（読める人・無料の記事では null）。
 *
 * テーマはこの形だけを読む。文は決まった文（店の人の入力は入らない）で、買う・入会する導線は後の区切り（買い切り・月額）が
 * ここに差し込む。鍵（`keys`）は導線を作る側のための値で、**画面には出さない**（出しても害は無いが、要らないものは出さない）
 */
final readonly class PostPaywall
{
    /**
     * @param  list<string>  $keys  記事の読める条件（`<種類>:<名前>`。1 つでも持っていれば読める）
     * @param  bool  $signedIn  会員としてログインしているか
     * @param  bool  $hasFreePart  無料で読める分があったか
     * @param  string  $returnPath  ログインの後に戻る道（/p/blog/read/{記事の ID}）
     */
    public function __construct(
        public array $keys,
        public bool $signedIn,
        public bool $hasFreePart,
        public string $returnPath,
    ) {}

    /** 案内の文 */
    public function message(): string
    {
        return $this->hasFreePart
            ? 'ここから先は、お買い上げいただいた方・ご購読中の方がお読みいただけます。'
            : 'この記事は、お買い上げいただいた方・ご購読中の方がお読みいただけます。';
    }

    /** ログインの案内（会員でない人にだけ） */
    public function signInNote(): ?string
    {
        return $this->signedIn ? null : 'すでにお買い上げ・ご購読の方は、ログインするとお読みいただけます。';
    }

    /** ログインの道筋（読み終えたら記事へ戻る） */
    public function signInUrl(): string
    {
        return route('storefront.account.login', ['return' => $this->returnPath], false);
    }
}
