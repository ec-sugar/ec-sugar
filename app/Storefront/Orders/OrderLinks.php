<?php

declare(strict_types=1);

namespace App\Storefront\Orders;

use App\Core\Plugin\OrderLink;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Transaction\Models\Transaction;
use LogicException;
use Throwable;

/**
 * お客さんのご注文の画面（ご注文の確認・マイページの注文の履歴）に置く App のリンク（「レビューを書く」「領収書」など。2026-11-04）。
 * この店で使っている App のうち、口（OrderLinkProvider）を持つものだけに聞く（App の名前の順）。
 * 呼ぶのは、その注文を見てよいと確かめた後だけ（OrderReader::readWithLinks。OrderController::show と会員のマイページ）。
 * App が落ちても、ほかの名前を名乗っても、ご注文の画面は描く（その App のリンクを出さないだけ。落ちたことは報告する）
 */
final class OrderLinks
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    /**
     * @return list<OrderLink>
     */
    public function forOrder(Transaction $order): array
    {
        $links = [];

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->orderLinks === null || ! $this->states->enabled($plugin->name)) {
                continue;
            }

            try {
                $found = $plugin->orderLinks->forOrder($order);

                foreach ($found as $link) {
                    // ほかの App の名前を名乗るリンクは出さない（行き先は OrderLink が自分の /p/<名前>/ の下に限っている）
                    if ($link->plugin !== $plugin->name) {
                        throw new LogicException("App {$plugin->name} のご注文のリンクがほかの App の名前を名乗っています");
                    }
                }

                array_push($links, ...$found);
            } catch (Throwable $error) {
                report($error);
            }
        }

        return $links;
    }
}
