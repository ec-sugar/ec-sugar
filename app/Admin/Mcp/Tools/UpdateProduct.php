<?php

declare(strict_types=1);

namespace App\Admin\Mcp\Tools;

use App\Admin\Http\Requests\ListingRules;
use App\Core\Tax\TaxClass;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_product')]
#[Description('Change a product: name, price in yen (tax included), description, tax class, or publish/unpublish (status active or draft). Pass version from get_product to refuse the change if someone edited the product meanwhile. Depending on the shop setting this is applied at once or becomes a proposal the shop staff approve in the admin.')]
final class UpdateProduct extends AgentWriteTool
{
    protected const string TOOL = 'update_product';

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The product id.')->required(),
            'version' => $schema->integer()->min(1)->description('The version you read with get_product (optional).'),
            'title' => $schema->string()->max(200)->description('New name.'),
            'price' => $schema->integer()->min(0)->max(ListingRules::MAX_AMOUNT)->description('New price in yen, tax included.'),
            'description' => $schema->string()->max(5000)->description('New plain text description (replaces the current one).'),
            'tax_class' => $schema->string()->enum(array_map(static fn (TaxClass $c): string => $c->value, TaxClass::cases()))->description('standard or reduced.'),
            'status' => $schema->string()->enum(['active', 'draft'])->description('active shows it in the shop, draft hides it.'),
        ];
    }
}
