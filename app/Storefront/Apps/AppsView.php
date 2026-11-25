<?php

declare(strict_types=1);

namespace App\Storefront\Apps;

use App\Core\Plugin\Manifest\CspSources;
use App\Core\Plugin\Manifest\PageKind;

/**
 * テーマに渡す、App の差し込みの中身（すべての画面に $apps。ADR 0034）。テーマは核の部品に渡すだけで、中を組み立てない:
 *   <x-storefront.apps :apps="$apps" />            body の終わり（スクリプトと同意の帯）
 *   <x-storefront.app-links :apps="$apps" … />     フッター（外部送信に関する公表・Cookie の設定のリンク）
 * $csp はこの応答に足す CSP の宛先（差し込んだ App の分だけ。StorefrontSecurityHeaders が読む）
 * App を差し込む画面では、核の部品が App のスクリプトより前に Trusted Types の既定の関所（TRUSTED_TYPES_SCRIPT）を読む
 * $preview が真の画面（商品のプレビュー）では、同じ部品 <x-storefront.apps> が画面の上にプレビューの帯を描く（どのテーマでも）
 */
final readonly class AppsView
{
    /** Trusted Types の既定の関所（default policy）を作る核のスクリプト（Vite の入口。App は関所を作らない） */
    public const string TRUSTED_TYPES_SCRIPT = 'resources/storefront/trusted-types.js';

    /**
     * @param  list<AppTag>  $tags
     * @param  array<string, list<string>>  $csp
     */
    public function __construct(
        public PageKind $kind,
        public array $tags = [],
        public array $csp = [],
        public ?ConsentBanner $banner = null,
        public ?string $disclosureUrl = null,
        public ?string $consentUrl = null,
        public bool $preview = false,
        public ?string $editor = null,
        public ?string $trial = null,
    ) {}

    /**
     * 商品のプレビューの画面の差し込み: 個人情報の画面と同じく App を差し込まず、核の部品がプレビューの帯を描く。
     * テーマの下書きのプレビュー（ADR 0039 区切り 2）では $editor に枠の親（管理画面のオリジン）を渡し、帯の代わりに編集の印を描く。
     * $trial は区画の試し見の断片の道筋（同じオリジンの署名つき。編集の印に添える）
     */
    public function previewing(?string $editor = null, ?string $trial = null): self
    {
        return new self(PageKind::Private, [], [], null, $this->disclosureUrl, $this->consentUrl, true, $editor, $editor === null ? null : $trial);
    }

    /**
     * 既定の関所が script の src に通してよい外の宛先（この画面で差し込んだ App の script-src だけ。形をもう一度確かめる）
     *
     * @return list<string>
     */
    public function scriptSources(): array
    {
        return array_values(array_filter($this->csp['script-src'] ?? [], static fn (string $s): bool => CspSources::problem($s) === null));
    }

    public static function none(PageKind $kind = PageKind::Private): self
    {
        return new self($kind);
    }
}
