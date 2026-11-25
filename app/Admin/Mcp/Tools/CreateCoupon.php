<?php

declare(strict_types=1);

namespace App\Admin\Mcp\Tools;

use App\Admin\Http\Requests\CouponRequest;
use App\Core\Discount\Enums\CouponKind;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_coupon')]
#[Description('Propose a coupon code: a percentage off, a fixed amount off in yen, or free shipping, for all products or the given products, with optional period (Japan dates), minimum subtotal and limits. This always becomes a proposal: nothing changes until shop staff approve it in the admin.')]
final class CreateCoupon extends AgentWriteTool
{
    protected const string TOOL = 'create_coupon';

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->max(64)->description('The code customers type (3-32 letters, digits and hyphens).')->required(),
            'name' => $schema->string()->max(100)->description('A note for the shop staff.')->required(),
            'kind' => $schema->string()->enum(array_map(static fn (CouponKind $k): string => $k->value, CouponKind::cases()))->description('percent, fixed or free_shipping.')->required(),
            'value' => $schema->integer()->min(1)->max(CouponRequest::MAX_AMOUNT)->description('percent: 1-100. fixed: yen off. Not used for free_shipping.'),
            'max_discount' => $schema->integer()->min(1)->max(CouponRequest::MAX_AMOUNT)->description('percent only: the most yen off.'),
            'product_ids' => $schema->array()->items($schema->string())->max(CouponRequest::MAX_TARGETS)->description('Only these products (omit for all products).'),
            'min_subtotal' => $schema->integer()->min(1)->max(CouponRequest::MAX_AMOUNT)->description('Minimum subtotal in yen.'),
            'starts_on' => $schema->string()->format('date')->description('First day (YYYY-MM-DD, Japan time).'),
            'ends_on' => $schema->string()->format('date')->description('Last day, inclusive (YYYY-MM-DD, Japan time).'),
            'usage_limit' => $schema->integer()->min(1)->max(CouponRequest::MAX_USAGE)->description('How many times in total.'),
            'per_customer_limit' => $schema->integer()->min(1)->max(CouponRequest::MAX_PER_CUSTOMER)->description('How many times per customer (default 1).'),
            'members_only' => $schema->boolean()->description('Only for members (default false).'),
        ];
    }
}
