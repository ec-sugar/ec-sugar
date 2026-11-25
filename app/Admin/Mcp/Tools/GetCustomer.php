<?php

declare(strict_types=1);

namespace App\Admin\Mcp\Tools;

use App\Admin\Agent\AgentContext;
use App\Admin\Agent\ShopReader;
use App\Core\Shop\Services\AgentSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('get_customer')]
#[Description('Get one customer: email, name, phone and address from the latest order, saved addresses, and the order history (id, time, status, total). Only in shops that chose to share customer information with AI agents.')]
final class GetCustomer extends AgentTool
{
    protected const string LEVEL = 'operate';

    protected function available(AgentContext $context): bool
    {
        return app(AgentSettings::class)->sharesCustomerData();
    }

    protected function run(Request $request, ShopReader $reader): ?array
    {
        $data = $request->validate(['id' => ['required', 'string', 'ulid']]);

        return $reader->customer(strtolower((string) $data['id']));
    }

    protected function arguments(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->description('The customer id (from search_customers, or customer_account in get_order).')->required()];
    }
}
