<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use Illuminate\Http\Request;
use App\Services\Analytics\OverdueBalancesService;

/**
 * Port of Rails' Api::V1::Analytics::OverdueBalancesController
 * (app/controllers/api/v1/analytics/overdue_balances_controller.rb) —
 * GET /api/v{1,2}/analytics/overdue_balance.
 */
class OverdueBalancesController extends GrossRevenuesController
{
    protected function serializer(): string
    {
        return \App\Serializers\V1\Analytics\OverdueBalanceSerializer::class;
    }

    protected function collectionName(): string
    {
        return 'overdue_balances';
    }

    protected function filters(Request $request): array
    {
        return [
            'external_customer_id' => $request->query('external_customer_id'),
            'currency' => $this->upcasedCurrency($request),
            'months' => $request->query('months'),
            'billing_entity_id' => $this->billingEntity($request)?->id,
        ];
    }

    protected function serviceResult(Request $request): mixed
    {
        return OverdueBalancesService::call(
            organization: $this->currentOrganization(),
            filters: $this->filters($request),
        );
    }
}
