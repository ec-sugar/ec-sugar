<?php

declare(strict_types=1);

namespace App\Admin\Mcp\Tools;

use App\Admin\Agent\ShopReader;
use App\Core\Transaction\Enums\OrderStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('list_orders')]
#[Description('List the shop\'s orders, newest first: id, time placed, status, total in yen and number of items. Customers are shown as an opaque id; the buyer name and email are added only in shops that chose to share customer information. Dates are in Japan time. Up to 100.')]
final class ListOrders extends AgentTool
{
    protected function run(Request $request, ShopReader $reader): array
    {
        $statuses = array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::cases());
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string', 'in:'.implode(',', $statuses)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.ShopReader::MAX_ORDERS],
        ]);

        return $reader->orders($data['from'] ?? null, $data['to'] ?? null, $data['status'] ?? null, (int) ($data['limit'] ?? 20));
    }

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')->description('First day (YYYY-MM-DD, Japan time).'),
            'to' => $schema->string()->format('date')->description('Last day, inclusive (YYYY-MM-DD, Japan time).'),
            'status' => $schema->string()->enum(array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::cases()))->description('Filter by order status.'),
            'limit' => $schema->integer()->min(1)->max(ShopReader::MAX_ORDERS)->description('How many to return (default 20).'),
        ];
    }
}
