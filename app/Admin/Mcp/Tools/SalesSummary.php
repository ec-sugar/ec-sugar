<?php

declare(strict_types=1);

namespace App\Admin\Mcp\Tools;

use App\Admin\Agent\ShopReader;
use App\Admin\Services\SalesPeriod;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('sales_summary')]
#[Description('Sales for a period, counted the same way as the admin sales page (test payments excluded): totals, daily figures and the top 10 products by amount. Periods: today, week, month, last_month, or custom with from/to (up to 366 days).')]
final class SalesSummary extends AgentTool
{
    protected const string LEVEL = 'money';

    protected function run(Request $request, ShopReader $reader): array
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'in:'.implode(',', SalesPeriod::PRESETS)],
            'from' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
        ]);

        return $reader->salesSummary((string) $data['period'], $data['from'] ?? null, $data['to'] ?? null);
    }

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->enum(SalesPeriod::PRESETS)->description('today, week, month, last_month or custom.')->required(),
            'from' => $schema->string()->format('date')->description('For custom: first day (YYYY-MM-DD).'),
            'to' => $schema->string()->format('date')->description('For custom: last day, inclusive (YYYY-MM-DD).'),
        ];
    }
}
