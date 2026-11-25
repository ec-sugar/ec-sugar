<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use App\Core\Plugin\Contracts\CheckoutOptInHandler;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use Throwable;

/**
 * 注文の入力の「お知らせのメールを受け取る」の核の側。口（CheckoutOptInHandler）を持つ App をこの店で使っている時だけ、切り替えを出す。
 * 切り替えはいつも選んでいない状態から出し（同意は本人が選ぶ）、選んだ人の注文が確定した後にだけ、App にメールを渡す。
 * App が落ちても、注文の入力の画面と注文は変わらない（切り替えを出さない・渡せなかったことを報告するだけ）
 */
final class CheckoutOptIns
{
    public const int LABEL_MAX = 60;

    public const int NOTE_MAX = 200;

    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    public function handler(): ?CheckoutOptInHandler
    {
        $plugin = $this->plugins->optInPlugin();

        return $plugin !== null && $this->states->enabled($plugin->name) ? $plugin->checkoutOptIn : null;
    }

    /**
     * 画面に出す切り替えの文字と一言（使っていない店・App が落ちた・文が長すぎる時は null で、切り替えを出さない）
     *
     * @return array{label: string, note: string}|null
     */
    public function offer(): ?array
    {
        try {
            $handler = $this->handler();

            if ($handler === null) {
                return null;
            }

            $label = trim($handler->label());
            $note = trim($handler->note());

            return $label === '' || mb_strlen($label) > self::LABEL_MAX || mb_strlen($note) > self::NOTE_MAX ? null : ['label' => $label, 'note' => $note];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * 注文が確定した後に呼ぶ。選んでいて、今も使っている店なら App に渡す。
     * 会員の ID は、ログインした会員のメールが注文のメールと同じ時だけ渡す（確かめ済みのメール。違えば App が確かめのメールを送る）
     */
    public function accept(CheckoutInput $input, ?string $memberId, ?string $memberEmail, string $transactionId): void
    {
        if (! $input->optIn) {
            return;
        }

        try {
            $email = (string) $input->buyer['email'];
            $same = $memberId !== null && $memberEmail !== null && mb_strtolower(trim($memberEmail)) === mb_strtolower($email);
            $this->handler()?->accepted($email, $same ? $memberId : null, $transactionId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
