<?php

declare(strict_types=1);

namespace App\Core\Plugin\Manifest;

/**
 * App の設定の項目 1 つの宣言（マニフェストの settings。AppManifest が形を確かめてから作る）。
 * secret（秘密）の項目は、店の DB に暗号化して置き、記録（イベント）にも管理画面の応答にもショップにも中身を出さない
 */
final readonly class SettingSpec
{
    // 文の項目に、宣言の形の決まりとは別に、核がいつも断る文字（HTML・スクリプト・属性を抜け出す文字と制御文字）
    private const string UNSAFE = '/[<>"\'`\\\\]|\p{C}/u';

    /**
     * @param  ?string  $pattern  文の形の決まり（正規表現の本体。核が \A(?:…)\z で全体に当てる）。text だけ
     * @param  ?int  $max  文の長さの上限（核の上限以下）。text だけ
     * @param  array<string, string>  $options  選択肢（値 => 画面の名前）。choice だけ
     * @param  bool|string|null  $default  まだ入れていないときの値（toggle と choice だけ）
     */
    public function __construct(
        public string $key,
        public SettingType $type,
        public string $label,
        public ?string $help = null,
        public bool $required = false,
        public bool $secret = false,
        public ?string $pattern = null,
        public ?int $max = null,
        public array $options = [],
        public bool|string|null $default = null,
    ) {}

    /**
     * 文の形の決まりを、全体に当てる正規表現にする（区切りの ~ はマニフェストで断っている）
     */
    public function regex(): ?string
    {
        return $this->pattern === null ? null : '~\A(?:'.$this->pattern.')\z~u';
    }

    /**
     * 値が宣言に合わないときの、画面に出す一言（合えば null）。null と（切り替えでない項目の）空は「消す」なので、ここでは断らない
     * （App の設定〔PluginSettings〕と商品の画面の App の欄〔ListingPanels〕が同じ決まりで確かめる）
     */
    public function problem(mixed $value): ?string
    {
        if ($value === null || ($value === '' && $this->type !== SettingType::Toggle)) {
            return null;
        }

        return match ($this->type) {
            SettingType::Toggle => is_bool($value) ? null : '切り替えの値が違います。',
            SettingType::Choice => is_string($value) && isset($this->options[$value]) ? null : '選択肢から選んでください。',
            SettingType::Text => $this->textProblem($value),
            // 写真は ID の形だけをここで見る（店の写真にあるかは、店の文脈の中で PluginSettings が確かめる）
            SettingType::Media => is_string($value) && preg_match('/\A[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}\z/', $value) === 1 ? null : '写真を選んでください。',
        };
    }

    private function textProblem(mixed $value): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return '文字で入れてください。';
        }

        if (mb_strlen($value) > (int) $this->max) {
            return "{$this->max} 文字までで入れてください。";
        }

        // 形の決まり（宣言の正規表現）に全体が合うこと。決まりで読めないとき（false）も断る
        if (preg_match(self::UNSAFE, $value) === 1 || preg_match((string) $this->regex(), $value) !== 1) {
            return $this->help !== null ? "形が違います。{$this->help}" : '形が違います。';
        }

        return null;
    }

    /**
     * 管理画面に渡す形（欄を作るのに使う。秘密の項目でも宣言は渡す）
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'label' => $this->label,
            'help' => $this->help,
            'required' => $this->required,
            'secret' => $this->secret,
            'pattern' => $this->pattern,
            'max' => $this->max,
            'options' => $this->options === [] ? null : $this->options,
            'default' => $this->default,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
