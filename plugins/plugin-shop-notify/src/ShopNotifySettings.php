<?php

declare(strict_types=1);

namespace EcSugar\PluginShopNotify;

use App\Core\Plugin\Services\PluginSettings;

/**
 * App「店への知らせ」の設定を読む（店の文脈の中だけ）。宛先は秘密の項目（店の鍵で暗号化）から、送る直前にだけ読む。
 *
 * Slack の宛先は https://hooks.slack.com/services/… だけ（ADR 0060 の 2）。保存の時にマニフェストの形でも断るが、
 * 送る直前にここでもう一度同じ形を確かめる（形の決まりが後で変わっても、任意の宛先へ送らない。サーバーから外への要求の悪用を防ぐ）
 */
final class ShopNotifySettings
{
    public const string PLUGIN = 'shop-notify';

    public const string SLACK_URL = '~\Ahttps://hooks\.slack\.com/services/[A-Za-z0-9_-]{1,40}/[A-Za-z0-9_-]{1,40}/[A-Za-z0-9_-]{1,80}\z~';

    /** 知らせの種類 => 切り替えの項目 */
    private const array TOGGLES = ['paid' => 'on_paid', 'cancelled' => 'on_cancelled', 'low_stock' => 'on_low_stock'];

    /** 在庫が少ないとみなす数の選択肢 => 数 */
    private const array THRESHOLDS = ['le0' => 0, 'le1' => 1, 'le3' => 3, 'le5' => 5, 'le10' => 10, 'le20' => 20];

    public function __construct(private readonly PluginSettings $settings) {}

    /**
     * この種類の知らせを送るか（試しの知らせは切り替えに関わらず送る）
     */
    public function wants(string $kind): bool
    {
        if ($kind === 'test') {
            return true;
        }

        $key = self::TOGGLES[$kind] ?? null;

        return $key !== null && ($this->values()[$key] ?? false) === true;
    }

    public function threshold(): int
    {
        return self::THRESHOLDS[(string) ($this->values()['low_stock_threshold'] ?? 'le3')] ?? 3;
    }

    /**
     * 送れる宛先（宛先の種類 => 宛先）。形の合わないものは無いのと同じ
     *
     * @return array<'slack'|'mail', string>
     */
    public function channels(): array
    {
        $out = [];
        $slack = $this->settings->secret(self::PLUGIN, 'slack_webhook_url');

        if (is_string($slack) && preg_match(self::SLACK_URL, $slack) === 1) {
            $out['slack'] = $slack;
        }

        $mail = $this->settings->secret(self::PLUGIN, 'mail_to');

        if (is_string($mail) && filter_var($mail, FILTER_VALIDATE_EMAIL) !== false) {
            $out['mail'] = $mail;
        }

        return $out;
    }

    /**
     * @return array<string, bool|string>
     */
    private function values(): array
    {
        return $this->settings->publicValues(self::PLUGIN) ?? [];
    }
}
