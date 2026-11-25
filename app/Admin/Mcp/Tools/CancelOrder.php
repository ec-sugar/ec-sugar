<?php

declare(strict_types=1);

namespace App\Admin\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('cancel_order')]
#[Description('Propose cancelling an order that has not been shipped (a paid order is refunded in full through Stripe and its stock comes back). This always becomes a proposal: nothing changes until shop staff with the money permission approve it in the admin.')]
final class CancelOrder extends AgentWriteTool
{
    protected const string TOOL = 'cancel_order';

    protected function arguments(JsonSchema $schema): array
    {
        return ['order_id' => $schema->string()->description('The order id.')->required()];
    }
}
