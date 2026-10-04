<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use Illuminate\Http\Request;
use App\Services\Analytics\InvoiceCollectionsService;

/**
 * Port of Rails' Api::V1::Analytics::InvoiceCollectionsController
 * (app/controllers/api/v1/analytics/invoice_collections_controller.rb) —
 * GET /api/v{1,2}/analytics/invoice_collection (premium-gated at the
 * service).
 */
class InvoiceCollectionsController extends GrossRevenuesController
{
    protected function serializer(): string
    {
        return \App\Serializers\V1\Analytics\InvoiceCollectionSerializer::class;
    }

    protected function collectionName(): string
    {
        return 'invoice_collections';
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
        return InvoiceCollectionsService::call(
            organization: $this->currentOrganization(),
            filters: $this->filters($request),
        );
    }
}
