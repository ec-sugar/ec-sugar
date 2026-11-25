<?php

declare(strict_types=1);

namespace App\Admin\Mcp\Tools;

use App\Admin\Http\Requests\CouponRequest;
use App\Core\Payment\Enums\ReturnReason;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('refund_order')]
#[Description('Propose refunding a shipped order through Stripe: an amount in yen, or everything that is left to refund when amount is omitted. This always becomes a proposal: nothing changes until shop staff with the money permission approve it in the admin. Orders not shipped yet are cancelled instead (cancel_order).')]
final class RefundOrder extends AgentWriteTool
{
    protected const string TOOL = 'refund_order';

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->string()->description('The order id.')->required(),
            'amount' => $schema->integer()->min(1)->max(CouponRequest::MAX_AMOUNT)->description('Amount in yen (integer). Omit to refund all that is left.'),
            'reason' => $schema->string()->enum(array_map(static fn (ReturnReason $r): string => $r->value, ReturnReason::choosable()))->description('Why (default other).'),
        ];
    }
}
