<?php

declare(strict_types=1);

namespace EcSugar\PluginWrapping;

use App\Core\Plugin\Services\PluginSettings;

/**
 * のしの決まり（ADR 0078）。この App の設定（秘密でない値。形はマニフェストの決まりで核が確かめ済み）から読む:
 * のしを受けるか・表書きの選択肢（「、」区切り。空なら既定の 7 つ）・料金（税込み。空なら無料）・名入れの欄を出すか・名入れの文字数（空なら 10）
 */
final readonly class NoshiRules
{
    public const array DEFAULT_TITLES = ['御祝', '内祝', '御礼', '御中元', '御歳暮', '粗品', '無地'];

    public const int DEFAULT_NAME_MAX = 10;

    /**
     * @param  list<string>  $titles
     */
    public function __construct(
        public bool $enabled,
        public array $titles,
        public int $fee,
        public bool $nameField,
        public int $nameMax,
    ) {}

    public static function current(): self
    {
        $values = app(PluginSettings::class)->publicValues(WrappingCharges::PLUGIN) ?? [];
        $titles = is_string($values['noshi_titles'] ?? null) ? array_values(array_unique(array_filter(array_map('trim', explode('、', $values['noshi_titles'])), static fn (string $t): bool => $t !== ''))) : [];
        $fee = is_string($values['noshi_fee'] ?? null) && $values['noshi_fee'] !== '' ? (int) $values['noshi_fee'] : 0;
        $max = is_string($values['noshi_name_max'] ?? null) && $values['noshi_name_max'] !== '' ? (int) $values['noshi_name_max'] : self::DEFAULT_NAME_MAX;

        return new self(
            enabled: ($values['noshi'] ?? false) === true,
            titles: $titles === [] ? self::DEFAULT_TITLES : $titles,
            fee: max(0, min($fee, 10000)),
            nameField: ($values['noshi_name'] ?? false) === true,
            nameMax: max(1, min($max, 20)),
        );
    }

    /**
     * 確かめの画面の指紋に入れる決まりの要約（確かめの後に店が表書き・料金・名入れの決まりを変えたら確定しない）
     */
    public function digest(): string
    {
        return hash('sha256', json_encode([$this->enabled, $this->titles, $this->fee, $this->nameField, $this->nameMax], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
