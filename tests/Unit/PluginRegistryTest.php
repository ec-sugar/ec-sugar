<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use EcSugar\PluginPreorder\PreorderCheckout;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * プラグインの登録簿（ADR 0025）。バージョンが合わない・二重・形の違う名前は受け付けない
 */
final class PluginRegistryTest extends TestCase
{
    public function test_plugins_are_registered_once_with_the_right_api(): void
    {
        $registry = new PluginRegistry;
        $registry->register($this->plugin('zeta'));
        $registry->register($this->plugin('alpha'));

        $this->assertSame(['alpha', 'zeta'], array_map(static fn (PluginDefinition $p): string => $p->name, $registry->all()));
        $this->assertTrue($registry->has('alpha'));
        $this->assertFalse($registry->has('nope'));

        foreach ([
            '二重' => $this->plugin('alpha'),
            'バージョンが違う' => $this->plugin('beta', 2),
            '大文字' => $this->plugin('Beta'),
            '短すぎる' => $this->plugin('b'),
            '記号' => $this->plugin('b/../x'),
        ] as $case => $plugin) {
            try {
                $registry->register($plugin);
                $this->fail($case);
            } catch (InvalidArgumentException) {
                $this->assertCount(2, $registry->all(), $case);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $registry->get('nope');
    }

    public function test_only_one_app_holds_the_preorder_port(): void
    {
        // 予約販売の口（ADR 0073）は 1 つの App だけ（発売日と予約の数の決まりを 2 つ持たない）
        $registry = new PluginRegistry;
        $registry->register(new PluginDefinition(name: 'first', title: 'first', description: '', pluginApi: PluginRegistry::API_VERSION, preorder: new PreorderCheckout));
        $this->assertSame('first', $registry->preorderPlugin()?->name);

        $this->expectException(InvalidArgumentException::class);
        $registry->register(new PluginDefinition(name: 'second', title: 'second', description: '', pluginApi: PluginRegistry::API_VERSION, preorder: new PreorderCheckout));
    }

    private function plugin(string $name, int $api = PluginRegistry::API_VERSION): PluginDefinition
    {
        return new PluginDefinition(name: $name, title: $name, description: '', pluginApi: $api);
    }
}
