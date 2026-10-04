<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Api\ApiController;
use App\Services\Invoices\CustomerUsageService;
use App\Serializers\V1\Customers\UsageSerializer;

/**
 * Port of Rails' Api::V1::Customers::UsageController
 * (app/controllers/api/v1/customers/usage_controller.rb) — the current
 * usage of a customer's subscription.
 *
 * Not ported: the past_usage action (PastUsageQuery — past usage periods
 * slice) and UsageFilters (the M2 filters pipeline; the query params are
 * accepted and ignored).
 */
class UsageController extends ApiController
{
    protected ?string $resourceName = 'customer_usage';

    public function current(Request $request): JsonResponse
    {
        $applyTaxes = filter_var(
            $request->query('apply_taxes', 'true'),
            FILTER_VALIDATE_BOOL,
        );

        $result = CustomerUsageService::withExternalIds(
            customerExternalId: (string) $request->route('external_id'),
            externalSubscriptionId: (string) $request->query('external_subscription_id'),
            organizationId: (string) $this->currentOrganization()->id,
            applyTaxes: $applyTaxes,
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new UsageSerializer(
                $result->usage,
                [
                    'root_name' => 'customer_usage',
                    'includes' => ['charges_usage'],
                ],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }
}
