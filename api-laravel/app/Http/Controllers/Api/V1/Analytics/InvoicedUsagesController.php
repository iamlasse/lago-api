<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use Illuminate\Http\Request;
use App\Services\Analytics\InvoicedUsagesService;

/**
 * Port of Rails' Api::V1::Analytics::InvoicedUsagesController
 * (app/controllers/api/v1/analytics/invoiced_usages_controller.rb) —
 * GET /api/v{1,2}/analytics/invoiced_usage (premium-gated at the service).
 */
class InvoicedUsagesController extends GrossRevenuesController
{
    protected function serializer(): string
    {
        return \App\Serializers\V1\Analytics\InvoicedUsageSerializer::class;
    }

    protected function collectionName(): string
    {
        return 'invoiced_usages';
    }

    protected function filters(Request $request): array
    {
        return [
            'currency' => $this->upcasedCurrency($request),
            'months' => $request->query('months'),
            'billing_entity_id' => $this->billingEntity($request)?->id,
        ];
    }

    protected function serviceResult(Request $request): mixed
    {
        return InvoicedUsagesService::call(
            organization: $this->currentOrganization(),
            filters: $this->filters($request),
        );
    }
}
