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
#[Name('search_customers')]
#[Description('Find customers by their exact full name or exact email address (personal data is encrypted, so partial matches are not possible): id, email, name on the latest order, member or not, number and total of paid orders. Up to 20. Only in shops that chose to share customer information with AI agents.')]
final class SearchCustomers extends AgentTool
{
    protected const string LEVEL = 'operate';

    protected function available(AgentContext $context): bool
    {
        return app(AgentSettings::class)->sharesCustomerData();
    }

    protected function run(Request $request, ShopReader $reader): array
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'max:254'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.ShopReader::MAX_CUSTOMERS],
        ]);

        return $reader->searchCustomers((string) $data['query'], (int) ($data['limit'] ?? 10));
    }

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->max(254)->description('Exact full name (as on the order) or exact email address.')->required(),
            'limit' => $schema->integer()->min(1)->max(ShopReader::MAX_CUSTOMERS)->description('How many to return (default 10).'),
        ];
    }
}
