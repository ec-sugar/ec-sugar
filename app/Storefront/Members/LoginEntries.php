<?php

declare(strict_types=1);

namespace App\Storefront\Members;

use App\Core\Plugin\Contracts\LoginEntryProvider;
use App\Core\Plugin\LoginEntry;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 会員のログインの画面に置く App の入口（「LINE でログイン」など。ADR 0070）。この店で使っている App のうち、口を持ち、
 * 設定がそろって入口を返したものだけ。App が落ちてもログインの画面は描く（その App の入口を出さないだけ）
 */
final class LoginEntries
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    /**
     * @return list<LoginEntry>
     */
    public function all(): array
    {
        $entries = [];

        foreach ($this->enabled() as $name => $provider) {
            try {
                $entry = $provider->loginEntry();
            } catch (Throwable $e) {
                Log::error('App のログインの入口を出せなかった', ['plugin' => $name, 'error' => $e::class]);

                continue;
            }

            // ほかの App の名前を名乗る入口は出さない
            if ($entry !== null && $entry->plugin === $name) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * 会員がつないでいる App のログインの手段の一言（「LINE をつないでいます」）。使っている App だけ
     *
     * @return list<string>
     */
    public function linkedLabels(string $accountId): array
    {
        $labels = [];

        foreach ($this->enabled() as $name => $provider) {
            try {
                $label = $provider->linkedLabel($accountId);
            } catch (Throwable $e) {
                Log::error('App のログインの手段のつなぎを読めなかった', ['plugin' => $name, 'error' => $e::class]);

                continue;
            }

            if (is_string($label) && $label !== '' && mb_strlen($label) <= 20 && preg_match('/\p{C}/u', $label) !== 1) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * ログインの途中で App がセッションに置く値の名前（入っている App の全部。使うのをやめた App の残りも消すため）
     *
     * @return list<string>
     */
    public function sessionKeys(): array
    {
        $keys = [];

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->loginEntry === null) {
                continue;
            }

            foreach ($plugin->loginEntry->sessionKeys() as $key) {
                // 核の値（会員のログイン・再確認・パスキー）と同じ名前は App に名乗らせない
                if (preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $key) === 1 && ! str_starts_with($key, 'member_')) {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, LoginEntryProvider>
     */
    private function enabled(): array
    {
        $out = [];

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->loginEntry !== null && $this->states->enabled($plugin->name)) {
                $out[$plugin->name] = $plugin->loginEntry;
            }
        }

        return $out;
    }
}
