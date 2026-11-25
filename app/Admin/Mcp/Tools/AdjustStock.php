<?php

declare(strict_types=1);

namespace App\Admin\Mcp\Tools;

use App\Admin\Agent\ShopWriter;
use App\Core\Inventory\Enums\AdjustmentReason;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('adjust_stock')]
#[Description('Change stock of one product variant at one stock location: mode receive adds quantity (goods arrived); mode count sets the counted quantity (stocktake, with a reason). Stock reserved by orders cannot be removed. Depending on the shop setting this is applied at once or becomes a proposal the shop staff approve in the admin.')]
final class AdjustStock extends AgentWriteTool
{
    protected const string TOOL = 'adjust_stock';

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->string()->description('The product id.')->required(),
            'variant_id' => $schema->string()->description('The variant id (from get_product). Needed when the product has variants.'),
            'location_id' => $schema->string()->description('The stock location id. Needed when the seller has several locations.'),
            'mode' => $schema->string()->enum(['receive', 'count'])->description('receive: add quantity. count: the quantity on hand after counting.')->required(),
            'quantity' => $schema->integer()->min(0)->max(ShopWriter::MAX_STOCK)->description('For receive, how many arrived (1 or more). For count, the counted quantity.')->required(),
            'reason' => $schema->string()->enum(array_map(static fn (AdjustmentReason $r): string => $r->value, AdjustmentReason::cases()))->description('For count: why it differs (default stocktake).'),
        ];
    }
}
