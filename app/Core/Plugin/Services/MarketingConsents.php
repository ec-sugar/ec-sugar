<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Plugin\Contracts\MarketingConsent;
use App\Core\Plugin\PluginRegistry;
use Throwable;

/**
 * お知らせのメールの受け取りの同意を、同意の記録を持つ App に聞く核の窓口（ADR 0067）。
 * 答える App は、注文の入力の「お知らせのメールを受け取る」の係（PluginRegistry::optInPlugin）が MarketingConsent も実装したもの。
 *
 * - 同意しているか: 答える App が入っていない・この店で使っていない・落ちたときは「同意していない」（送らない側に倒す）
 * - 止める: 答える App が入っていれば、この店で使うのをやめていても止める（止めた印は残り、また使い始めても届かない。止めるのはいつでも安全）
 */
final class MarketingConsents
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    /**
     * 同意を答える App がこの店で使われているか（使われていなければ、誰にもお知らせのメールを送らない）
     */
    public function available(): bool
    {
        $plugin = $this->plugins->optInPlugin();

        return $plugin !== null && $plugin->checkoutOptIn instanceof MarketingConsent && $this->states->enabled($plugin->name);
    }

    public function consents(string $email): bool
    {
        $email = trim($email);

        if ($email === '' || ! $this->available()) {
            return false;
        }

        try {
            return $this->handler()?->consents($email) === true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * 受け取りを止める。止めたら真（答える App が無い・もともと届けていない・落ちた時は偽）
     */
    public function withdraw(string $email, string $via): bool
    {
        $email = trim($email);

        if ($email === '' || ! in_array($via, ['link', 'one_click'], true)) {
            return false;
        }

        try {
            return $this->handler()?->withdraw($email, $via) === true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function handler(): ?MarketingConsent
    {
        $handler = $this->plugins->optInPlugin()?->checkoutOptIn;

        return $handler instanceof MarketingConsent ? $handler : null;
    }
}
