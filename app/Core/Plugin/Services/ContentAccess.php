<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Plugin\PluginRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * お客さんが持っている「読む権利（鍵）」（ContentAccessProvider）。この店で使っている App のうち、口を持つものだけに聞く。
 * 有料の記事を出すかの判断に使う（見せる側は売り方を知らない）。
 *
 * App が落ちたら「持っていない」として扱い（本文を送らない側へ倒す）、壊れたら知らせる（ADR 0043）。
 * ログインしていない人には聞かない（鍵は会員の台帳の ID に結びつく）
 */
final class ContentAccess
{
    /** 1 回に聞ける鍵の数（記事の「読める条件」が膨らんでも、聞く回数を増やさない） */
    private const int MAX_KEYS = 20;

    /** 鍵の形。`<種類>:<名前>` で、核は中身を解釈しない */
    private const string KEY = '/\A[a-z][a-z0-9-]{0,31}:[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/';

    /**
     * 鍵の形が正しいか。**App は自分で正規表現を持たず、ここに聞く**
     * （核と App で形がずれると、保存できたのに誰にも読めない鍵ができる）
     */
    public static function validKey(string $key): bool
    {
        return preg_match(self::KEY, $key) === 1;
    }

    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    /**
     * 頼まれた鍵のうち、そのお客さんが持っているもの
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function held(?string $accountId, array $keys): array
    {
        $keys = array_values(array_unique(array_filter(
            $keys,
            static fn (string $key): bool => self::validKey($key),
        )));

        if ($accountId === null || $accountId === '' || $keys === []) {
            return [];
        }

        $keys = array_slice($keys, 0, self::MAX_KEYS);
        $held = [];

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->contentAccess === null || ! $this->states->enabled($plugin->name)) {
                continue;
            }

            try {
                $answer = $plugin->contentAccess->held($accountId, $keys);
            } catch (Throwable $e) {
                report($e);
                Log::error('App の読む権利を読めなかった', ['plugin' => $plugin->name, 'error' => $e::class]);

                continue;
            }

            foreach ($answer as $key) {
                // 頼んだ鍵だけ（App の誤りで別のものを開けない）。厳密比較なので、文字でない値はここで落ちる
                if (in_array($key, $keys, true) && ! in_array($key, $held, true)) {
                    $held[] = $key;
                }
            }
        }

        return $held;
    }

    /**
     * 頼まれた鍵のどれかを持っているか（1 つでも持っていれば読める）
     *
     * @param  list<string>  $keys
     */
    public function any(?string $accountId, array $keys): bool
    {
        return $this->held($accountId, $keys) !== [];
    }
}
