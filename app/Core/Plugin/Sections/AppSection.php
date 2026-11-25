<?php

declare(strict_types=1);

namespace App\Core\Plugin\Sections;

use App\Core\Plugin\Manifest\ConsentKind;
use App\Core\Section\SectionSchema;
use App\Core\Section\SectionSpec;

/**
 * App が足す区画の種類（マニフェストの sections。ADR 0040）。宣言の中身（欄・パターン・見本・ワイヤーの絵）はテーマの区画と同じ形で、
 * 核の SectionSchema が同じ決まりで確かめた SectionSpec。そのほかに、同意の種類と、外から埋め込むときの決まり（EmbedRules）を持つ。
 *
 * - 種類の名前は app_ ＋ App の名前（- は _）か、その後ろに _ と英小文字・数字（例: App「google-map」は app_google_map・app_google_map_route）
 * - 描くのは App の Blade（パッケージの resources/views/sections/{種類}.blade.php か {種類}/{パターン}.blade.php）。
 *   核が views の名前の場所（app-{App の名前}）を決めて読む。テーマは同じ名前の部品を置けば描き直せる
 * - 店がその App を「使う」にしているときだけ、ページに置ける（区画を足す欄に出る）。止めた App の区画は出さない（値は消さない）
 */
final readonly class AppSection
{
    public const string PREFIX = SectionSchema::APP_PREFIX;

    /**
     * @param  string  $plugin  App の名前
     * @param  list<ConsentKind>  $consent  この区画を出すのに要る同意（空なら誰にでも出す。動画・地図の埋め込みは空。同意が要る区画は、全部に同意したお客さんにだけ出す）
     * @param  EmbedRules|null  $embed  外から埋め込む決まり（埋め込まない区画は null）
     * @param  string  $title  App の画面の名前（登録簿が入れる。編集の画面で「App」の印に出す）
     * @param  AppSectionHandler|null  $handler  App の区画の係（登録簿が入れる）
     */
    public function __construct(
        public string $plugin,
        public SectionSpec $spec,
        public array $consent,
        public ?EmbedRules $embed = null,
        public string $title = '',
        public ?AppSectionHandler $handler = null,
    ) {}

    public function type(): string
    {
        return $this->spec->key;
    }

    /**
     * App の種類の名前の頭（app_ ＋ App の名前。- は _）
     */
    public static function prefixOf(string $plugin): string
    {
        return self::PREFIX.str_replace('-', '_', $plugin);
    }

    /**
     * その App が持ってよい種類の名前か
     */
    public static function belongsTo(string $type, string $plugin): bool
    {
        $prefix = self::prefixOf($plugin);

        return $type === $prefix || preg_match('/\A'.preg_quote($prefix, '/').'_[a-z0-9]+(?:_[a-z0-9]+)*\z/', $type) === 1;
    }

    /**
     * 登録簿が App の名前と係を入れる。係が欄の値をもう一段確かめるよう、宣言にも付ける（SectionValues が保存のときに呼ぶ）
     */
    public function withPlugin(string $title, ?AppSectionHandler $handler): self
    {
        $type = $this->spec->key;
        $spec = $handler === null ? $this->spec : $this->spec->withCheck(static fn (array $values): array => $handler->problems($type, $values));

        return new self($this->plugin, $spec, $this->consent, $this->embed, $title, $handler);
    }

    /**
     * Blade の views の名前の場所（核が App のパッケージの resources/views に付ける）
     */
    public function viewNamespace(): string
    {
        return self::viewNamespaceOf($this->plugin);
    }

    public static function viewNamespaceOf(string $plugin): string
    {
        return 'app-'.$plugin;
    }
}
