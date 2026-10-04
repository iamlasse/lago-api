<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use Illuminate\Http\Request;
use App\Services\Analytics\GrossRevenuesService;

/**
 * Port of Rails' Api::V1::Analytics::GrossRevenuesController
 * (app/controllers/api/v1/analytics/gross_revenues_controller.rb) —
 * GET /api/v{1,2}/analytics/gross_revenue.
 */
class GrossRevenuesController extends BaseController
{
    protected function serializer(): string
    {
        return \App\Serializers\V1\Analytics\GrossRevenueSerializer::class;
    }

    protected function collectionName(): string
    {
        return 'gross_revenues';
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
        return GrossRevenuesService::call(
            organization: $this->currentOrganization(),
            filters: $this->filters($request),
        );
    }

    /** Rails: `params[:currency]&.upcase`. */
    protected function upcasedCurrency(Request $request): ?string
    {
        $currency = $request->query('currency');

        return $currency === null ? null : mb_strtoupper($currency);
    }
}
