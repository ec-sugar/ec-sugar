<?php

declare(strict_types=1);

namespace App\Storefront\Members;

use App\Core\Plugin\AccountLink;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;

/**
 * マイページに置く App の入口（定期便など）。この店で使っている App のうち、入口を宣言したものだけ
 */
final class AccountLinks
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    /**
     * @return list<AccountLink>
     */
    public function all(): array
    {
        $links = [];

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->accountLink !== null && $plugin->accountLink->plugin === $plugin->name && $this->states->enabled($plugin->name)) {
                $links[] = $plugin->accountLink;
            }
        }

        return $links;
    }
}
