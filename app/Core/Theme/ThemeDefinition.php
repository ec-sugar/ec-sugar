<?php

declare(strict_types=1);

namespace App\Core\Theme;

use App\Core\Section\SectionSchema;
use LogicException;

/**
 * テーマの定義。テーマのパッケージは自分のサービスプロバイダでこれを ThemeRegistry に登録する。
 *
 * テーマは「テーマ API」のバージョンを宣言する（CONCEPT 7「テーマ API はバージョン固定」）。店頭はテーマに決まった名前の
 * 画面（layout・home・legal・error）と、決まった形のデータ（docs/design/theme-api.md）だけを渡す。テーマは DB に触らない。
 */
final readonly class ThemeDefinition
{
    /**
     * 動きの強さ（theme.json の motion。docs/design/theme-api.md の「動き」）。外枠が <html data-motion="…"> に出し、核の動きの部品（CSS と JS）が読む。
     * none は動かさない、subtle は押した・開いた・変わった時だけ短く、rich はスクロールで浮かぶ・写真のゆっくりした拡大まで
     */
    public const array MOTIONS = ['none', 'subtle', 'rich'];

    public const string MOTION_DEFAULT = 'subtle';

    /**
     * @param  string  $name  テーマの名前（英小文字・数字・ハイフン）
     * @param  int  $themeApi  合わせて作ったテーマ API のバージョン
     * @param  string  $views  Blade の名前空間（loadViewsFrom の第 2 引数）
     * @param  string  $stylesheet  Vite の入口（リポジトリの根からの道筋。@vite に渡す）
     * @param  string|null  $manifest  theme.json の道筋（区画の宣言。ADR 0028。登録に必ず要る）
     * @param  SectionSchema|null  $sections  区画の宣言（ThemeRegistry が theme.json から作って入れる。テーマは渡さない）
     * @param  string  $motion  動きの強さ（theme.json の motion。none・subtle・rich。ThemeRegistry が入れる。テーマは渡さない）
     * @param  string|null  $screenshot  見本の絵（theme.json の screenshot。管理画面の「デザイン」のテーマの札に出す）の道筋。
     *                                   theme.json と同じフォルダの中のファイルだけ（ThemeRegistry が確かめて入れる。テーマは渡さない）
     * @param  ThemeStyle|null  $style  店の人が選べる見た目（theme.json の style。ADR 0097）。宣言しないテーマは null（何も選べない。ThemeRegistry が入れる）
     */
    public function __construct(
        public string $name,
        public string $label,
        public int $themeApi,
        public string $views,
        public string $stylesheet,
        public ?string $manifest = null,
        public ?SectionSchema $sections = null,
        public string $motion = self::MOTION_DEFAULT,
        public ?string $screenshot = null,
        public ?ThemeStyle $style = null,
    ) {}

    /**
     * 区画の宣言（登録の後は必ずある）
     */
    public function schema(): SectionSchema
    {
        return $this->sections ?? throw new LogicException("テーマ {$this->name} は登録の前です（セクションの宣言が無い）");
    }

    public function withManifest(SectionSchema $sections, string $motion, ?string $screenshot = null, ?ThemeStyle $style = null): self
    {
        return new self($this->name, $this->label, $this->themeApi, $this->views, $this->stylesheet, $this->manifest, $sections, $motion, $screenshot, $style);
    }

    public function view(string $name): string
    {
        return "{$this->views}::{$name}";
    }
}
