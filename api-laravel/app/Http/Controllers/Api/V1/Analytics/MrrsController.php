<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use Illuminate\Http\Request;
use App\Services\Analytics\MrrsService;

/**
 * Port of Rails' Api::V1::Analytics::MrrsController
 * (app/controllers/api/v1/analytics/mrrs_controller.rb) —
 * GET /api/v{1,2}/analytics/mrr (premium-gated at the service; forbidden
 * "feature_unavailable" without a license).
 */
class MrrsController extends GrossRevenuesController
{
    protected function serializer(): string
    {
        return \App\Serializers\V1\Analytics\MrrSerializer::class;
    }

    protected function collectionName(): string
    {
        return 'mrrs';
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
        return MrrsService::call(
            organization: $this->currentOrganization(),
            filters: $this->filters($request),
        );
    }
}
