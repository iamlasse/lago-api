<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use Illuminate\Http\Request;
use App\Models\BillingEntity;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Api\ApiController;

/**
 * Port of Rails' Api::V1::Analytics::BaseController
 * (app/controllers/api/v1/analytics/base_controller.rb) — renders the
 * analytics service records through the per-controller row serializer
 * under the controller name (Rails' CollectionSerializer with
 * `collection_name: controller_name`), and resolves the optional
 * billing_entity_code filter.
 */
abstract class BaseController extends ApiController
{
    /**
     * Rails: Api::V1::Analytics::BaseController#resource_name — the
     * api-permissions resource for every analytics endpoint is `analytic`.
     */
    protected ?string $resourceName = 'analytic';

    /**
     * Rails: `"::V1::Analytics::#{controller_name.classify}Serializer"` —
     * e.g. gross_revenues -> GrossRevenueSerializer.
     */
    abstract protected function serializer(): string;

    /** Rails: `controller_name` — the collection key ("gross_revenues"). */
    abstract protected function collectionName(): string;

    abstract protected function filters(Request $request): array;

    /**
     * Rails: `::Analytics::#{controller_name.classify.pluralize}Service`
     * — e.g. gross_revenues -> GrossRevenuesService::call(organization, **filters).
     */
    abstract protected function serviceResult(Request $request): mixed;

    public function index(Request $request): JsonResponse
    {
        $result = $this->serviceResult($request);

        if ($result->success()) {
            $serialized = call_user_func(
                [$this->serializer(), 'collection'],
                $result->records ?? [],
            );

            return $this->renderSerializerJson(
                json_encode([$this->collectionName() => $serialized], JSON_UNESCAPED_SLASHES),
            );
        }

        $this->renderErrorResponse($result);
    }

    /**
     * Rails: `billing_entity` — resolved by code within the CURRENT
     * organization (nil when unknown, mirroring BillingEntity.find_by).
     */
    protected function billingEntity(Request $request): ?BillingEntity
    {
        $code = $request->query('billing_entity_code');

        if ($code === null || $code === '') {
            return null;
        }

        return BillingEntity::query()
            ->where('organization_id', $this->currentOrganization()->id)
            ->where('code', $code)
            ->first();
    }
}
