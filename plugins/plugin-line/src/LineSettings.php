<?php

declare(strict_types=1);

namespace EcSugar\PluginLine;

use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;

/**
 * App「LINE 連携」の設定を読む（店の文脈の中だけ。ADR 0070）。秘密（チャネルシークレット・アクセストークン）は店の鍵で暗号化した
 * 設定の秘密の項目から、使う直前にだけ読む。保存の時にマニフェストの形で断っているが、使う直前にもここで同じ形を確かめる
 * （形の決まりが後で変わっても、壊れた値を LINE へ送らない・署名の鍵にしない）
 */
final class LineSettings
{
    public const string PLUGIN = 'line';

    public function __construct(
        private readonly PluginSettings $settings,
        private readonly PluginStates $states,
    ) {}

    public function enabled(): bool
    {
        return tenant() !== null && $this->states->enabled(self::PLUGIN);
    }

    /**
     * LINE ログインのチャネル ID とシークレット（両方そろって形が合う時だけ）
     *
     * @return array{id: string, secret: string}|null
     */
    public function login(): ?array
    {
        $id = $this->values()['login_channel_id'] ?? null;
        $secret = $this->settings->secret(self::PLUGIN, 'login_channel_secret');

        if (! is_string($id) || preg_match('/\A[0-9]{10}\z/', $id) !== 1 || ! is_string($secret) || preg_match('/\A[0-9a-f]{32}\z/', $secret) !== 1) {
            return null;
        }

        return ['id' => $id, 'secret' => $secret];
    }

    /**
     * Messaging API のチャネルアクセストークン（push に使う）
     */
    public function accessToken(): ?string
    {
        $token = $this->settings->secret(self::PLUGIN, 'messaging_access_token');

        return is_string($token) && preg_match('~\A[A-Za-z0-9+/=._-]{20,200}\z~', $token) === 1 ? $token : null;
    }

    /**
     * Messaging API のチャネルシークレット（Webhook の署名を確かめる鍵）
     */
    public function webhookSecret(): ?string
    {
        $secret = $this->settings->secret(self::PLUGIN, 'messaging_channel_secret');

        return is_string($secret) && preg_match('/\A[0-9a-f]{32}\z/', $secret) === 1 ? $secret : null;
    }

    /**
     * ログインの時の友だち追加（LINE の bot_prompt に渡す値。出さないなら null）
     */
    public function botPrompt(): ?string
    {
        $value = $this->values()['bot_prompt'] ?? 'normal';

        return in_array($value, ['normal', 'aggressive'], true) ? $value : null;
    }

    /**
     * この知らせを LINE でも送るか（order_paid・order_shipped）
     */
    public function wants(string $kind): bool
    {
        $key = ['order_paid' => 'on_paid', 'order_shipped' => 'on_shipped'][$kind] ?? null;

        return $key !== null && ($this->values()[$key] ?? false) === true;
    }

    /**
     * @return array<string, bool|string>
     */
    private function values(): array
    {
        return $this->settings->publicValues(self::PLUGIN) ?? [];
    }
}
