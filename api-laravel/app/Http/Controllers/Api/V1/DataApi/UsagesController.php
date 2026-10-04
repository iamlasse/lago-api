<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\DataApi;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\DataApi\UsagesService;
use App\Http\Controllers\Api\ApiController;

/**
 * Port of Rails' Api::V1::DataApi::UsagesController
 * (app/controllers/api/v1/data_api/usages_controller.rb) — serves
 * GET /api/v{1,2}/analytics/usage (config/routes/shared_api.rb
 * `get "analytics/usage", to: "data_api/usages#index"`) by proxying the Lago
 * Data API and rendering its JSON under the top-level "usages" key — Rails'
 * `render(json: {"usages" => result.usages}.to_json)`, no serializer.
 *
 * There is no premium gate on this endpoint (see UsagesService's
 * filtered_params); the failure path renders through the shared error
 * envelope. A failing Data API call (non-success status, connection error)
 * raises LagoHttpError/ConnectionException unrescued — exactly like Rails,
 * where LagoHttpClient::HttpError has no rescue_from and renders a 500.
 */
class UsagesController extends ApiController
{
    /**
     * Rails: Api::V1::DataApi::BaseController#resource_name — "analytic"
     * (the api-permissions resource for this endpoint is `analytic`, not
     * `data_api`).
     */
    protected ?string $resourceName = 'analytic';

    public function index(Request $request): JsonResponse
    {
        $result = UsagesService::call(
            organization: $this->currentOrganization(),
            params: $this->filterParams($request),
        );

        if ($result->success()) {
            return response()->json(['usages' => $result->usages]);
        }

        $this->renderErrorResponse($result);
    }

    /**
     * Port of #filter_params — Rails'
     * `params.permit(...11 scalar keys...).to_h.compact` on the GET query
     * string: unknown keys dropped, null values dropped, everything else
     * passed through untouched (the premium/non-premium filtering happens in
     * UsagesService#filtered_params).
     *
     * @return array<string, mixed>
     */
    private function filterParams(Request $request): array
    {
        $permitted = $this->permitParams((array) $request->query(), [
            'time_granularity',
            'currency',
            'from_date',
            'to_date',
            'customer_type',
            'external_customer_id',
            'customer_country',
            'external_subscription_id',
            'is_billable_metric_recurring',
            'plan_code',
            'billable_metric_code',
        ]);

        return array_filter($permitted, static fn ($value): bool => $value !== null);
    }
}
