<?php

declare(strict_types=1);

namespace App\Admin\Mcp\Tools;

use App\Admin\Agent\ShopReader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('low_stock')]
#[Description('List variants of products on sale whose available stock is at or below a threshold (default 3), lowest first. Up to 50.')]
final class LowStock extends AgentTool
{
    protected function run(Request $request, ShopReader $reader): array
    {
        $data = $request->validate([
            'threshold' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.ShopReader::MAX_PRODUCTS],
        ]);

        return $reader->lowStock((int) ($data['threshold'] ?? 3), (int) ($data['limit'] ?? 20));
    }

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'threshold' => $schema->integer()->min(0)->max(1000)->description('Available stock at or below this number (default 3).'),
            'limit' => $schema->integer()->min(1)->max(ShopReader::MAX_PRODUCTS)->description('How many to return (default 20).'),
        ];
    }
}
