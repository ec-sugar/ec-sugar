<?php

declare(strict_types=1);

namespace App\Admin\Mcp\Tools;

use App\Admin\Agent\AgentContext;
use App\Admin\Agent\AgentTokens;
use App\Admin\Agent\ShopReader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('get_proposal')]
#[Description('See what happened to a proposal you made with a write tool: pending (waiting for the shop staff), approved (applied, with the result), rejected, expired (after 7 days) or failed (the shop changed meanwhile, or it could not be applied). Only your own proposals.')]
final class GetProposal extends AgentTool
{
    protected const string LEVEL = 'operate';

    protected function availableForKey(AgentContext $context): bool
    {
        return $context->scope === AgentTokens::WRITE;
    }

    protected function run(Request $request, ShopReader $reader): ?array
    {
        $data = $request->validate(['id' => ['required', 'string', 'ulid']]);

        return $reader->proposal(strtolower((string) $data['id']), app(AgentContext::class)->operatorId);
    }

    protected function arguments(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->description('The proposal id returned by a write tool.')->required()];
    }
}
