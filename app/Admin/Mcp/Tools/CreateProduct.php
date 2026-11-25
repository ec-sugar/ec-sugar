<?php

declare(strict_types=1);

namespace App\Admin\Mcp\Tools;

use App\Admin\Http\Requests\ListingRules;
use App\Core\Tax\TaxClass;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_product')]
#[Description('Create a product as a draft (not shown in the shop until published): name, price in yen (tax included), optional description and tax class. Depending on the shop setting this is applied at once or becomes a proposal the shop staff approve in the admin.')]
final class CreateProduct extends AgentWriteTool
{
    protected const string TOOL = 'create_product';

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(200)->description('Product name.')->required(),
            'price' => $schema->integer()->min(0)->max(ListingRules::MAX_AMOUNT)->description('Price in yen, tax included.')->required(),
            'description' => $schema->string()->max(5000)->description('Plain text description (optional).'),
            'tax_class' => $schema->string()->enum(array_map(static fn (TaxClass $c): string => $c->value, TaxClass::cases()))->description('standard (10%, default) or reduced (8%, food and drinks).'),
        ];
    }
}
