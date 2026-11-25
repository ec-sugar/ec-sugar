<?php

declare(strict_types=1);

namespace App\Admin\Mcp\Tools;

use App\Core\Order\Services\SaleOrderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('mark_shipped')]
#[Description('Mark a paid order as shipped, with the carrier and the tracking number. The customer gets the shipping email. Depending on the shop setting this is applied at once or becomes a proposal the shop staff approve in the admin.')]
final class MarkShipped extends AgentWriteTool
{
    protected const string TOOL = 'mark_shipped';

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->string()->description('The order id.')->required(),
            'carrier' => $schema->string()->enum(array_keys(SaleOrderService::CARRIERS))->description('yamato, sagawa, japanpost or other.')->required(),
            'tracking_number' => $schema->string()->max(64)->description('Tracking number (letters, digits and hyphens).'),
            'fulfillment_id' => $schema->string()->description('Only when the order has several shipments.'),
        ];
    }
}
