<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customers;

use App\Support\License;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\Api\ForbiddenException;
use App\Http\Controllers\Api\ApiController;
use App\Services\Invoices\CustomerUsageService;
use App\Serializers\V1\Customers\ProjectedUsageSerializer;

/**
 * Port of Rails' Api::V1::Customers::ProjectedUsageController
 * (app/controllers/api/v1/customers/projected_usage_controller.rb) — the
 * current usage with the end-of-period projection.
 *
 * TODO(port): UsageFilters (the M2 filters pipeline; the query params are
 * accepted and ignored).
 */
class ProjectedUsageController extends ApiController
{
    protected ?string $resourceName = 'customer_usage';

    public function current(Request $request): JsonResponse
    {
        $this->authorizeProjectedUsage();

        $applyTaxes = filter_var(
            $request->query('apply_taxes', 'true'),
            FILTER_VALIDATE_BOOL,
        );

        $result = CustomerUsageService::withExternalIds(
            customerExternalId: (string) $request->route('external_id'),
            externalSubscriptionId: (string) $request->query('external_subscription_id'),
            organizationId: (string) $this->currentOrganization()->id,
            applyTaxes: $applyTaxes,
            withProjection: true,
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new ProjectedUsageSerializer(
                $result->usage,
                ['root_name' => 'customer_projected_usage'],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    /**
     * Rails: `authorize` override — 403 projected_usage_not_enabled unless
     * the organization opted in (License.premium? &&
     * premium_integrations.include?("projected_usage")).
     */
    private function authorizeProjectedUsage(): void
    {
        $organization = $this->currentOrganization();
        $premium = License::premium();
        $enabled = $premium && in_array(
            'projected_usage',
            (array) ($organization->premium_integrations ?? []),
            true,
        );

        if (! $enabled) {
            throw new ForbiddenException('projected_usage_not_enabled');
        }
    }
}
