<?php

declare(strict_types=1);

namespace App\Storefront\Orders;

use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Transaction\Models\Transaction;
use LogicException;
use Throwable;

/**
 * ご注文の画面とご注文の知らせのメールに載せる、App の注文の事実（予約の日時・場所・人数など）。
 * この店で使っている App のうち、口（OrderFactProvider）を持つものだけに聞く（App の名前の順）。
 * 形が違う（名前 1〜40 文字・値 1〜200 文字・改行と制御文字なし・数の上限）事実は、その App の分を出さずに報告する。
 * App が落ちても、ご注文の画面とメールは描く（その App の事実を出さないだけ）
 */
final class OrderFacts
{
    /** 1 つの App が足せる事実の数 */
    public const int MAX = 6;

    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    /**
     * @return list<array{name: string, value: string}>
     */
    public function forOrder(Transaction $order): array
    {
        $facts = [];

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->orderFacts === null || ! $this->states->enabled($plugin->name)) {
                continue;
            }

            try {
                $found = $plugin->orderFacts->forOrder($order);

                if (count($found) > self::MAX) {
                    throw new LogicException("App {$plugin->name} のご注文の事実が多すぎます");
                }

                foreach ($found as $fact) {
                    if (! self::fits($fact['name'], 40) || ! self::fits($fact['value'], 200)) {
                        throw new LogicException("App {$plugin->name} のご注文の事実の形が違います");
                    }
                }

                array_push($facts, ...array_map(static fn (array $f): array => ['name' => $f['name'], 'value' => $f['value']], $found));
            } catch (Throwable $error) {
                report($error);
            }
        }

        return $facts;
    }

    private static function fits(mixed $text, int $max): bool
    {
        return is_string($text) && $text !== '' && mb_check_encoding($text, 'UTF-8') && mb_strlen($text) <= $max && preg_match('/\p{C}/u', $text) !== 1;
    }
}
