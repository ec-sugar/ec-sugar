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
#[Name('search_products')]
#[Description('Search the shop\'s products by name. Returns id, title, status (draft/active/archived), price in yen, tax class, available stock and the number of variants. Up to 50.')]
final class SearchProducts extends AgentTool
{
    protected function run(Request $request, ShopReader $reader): array
    {
        $data = $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:draft,active,archived'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.ShopReader::MAX_PRODUCTS],
        ]);

        return $reader->searchProducts($data['query'] ?? null, $data['status'] ?? null, (int) ($data['limit'] ?? 20));
    }

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->max(100)->description('Part of the product name. Empty for all.'),
            'status' => $schema->string()->enum(['draft', 'active', 'archived'])->description('Filter by status.'),
            'limit' => $schema->integer()->min(1)->max(ShopReader::MAX_PRODUCTS)->description('How many to return (default 20).'),
        ];
    }
}
