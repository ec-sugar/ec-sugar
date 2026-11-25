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
#[Name('get_order')]
#[Description('Get one order: status, lines (item, options, discounts, shipping), subtotal, tax, total and the tax breakdown by rate. The customer is shown as an opaque id; the buyer name and email (and the customer id for get_customer) are added only in shops that chose to share customer information.')]
final class GetOrder extends AgentTool
{
    protected function run(Request $request, ShopReader $reader): ?array
    {
        $data = $request->validate(['id' => ['required', 'string', 'ulid']]);

        return $reader->order(strtolower((string) $data['id']));
    }

    protected function arguments(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->description('The order id (from list_orders).')->required()];
    }
}
