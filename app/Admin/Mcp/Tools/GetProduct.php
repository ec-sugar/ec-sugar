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
#[Name('get_product')]
#[Description('Get one product: title, status, price, tax class, summary, description and each variant with its options, price and available stock.')]
final class GetProduct extends AgentTool
{
    protected function run(Request $request, ShopReader $reader): ?array
    {
        $data = $request->validate(['id' => ['required', 'string', 'ulid']]);

        return $reader->product(strtolower((string) $data['id']));
    }

    protected function arguments(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->description('The product id (from search_products).')->required()];
    }
}
