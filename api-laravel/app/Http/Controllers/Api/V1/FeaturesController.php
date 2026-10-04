<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Feature;
use Illuminate\Http\Request;
use App\Queries\FeaturesQuery;
use Illuminate\Http\JsonResponse;
use App\Services\Features\CreateService;
use App\Services\Features\UpdateService;
use App\Exceptions\Api\NotFoundException;
use App\Services\Features\DestroyService;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Exceptions\Api\ParameterMissingException;
use App\Serializers\V1\Entitlement\FeatureSerializer;

/**
 * Port of Rails' Api::V1::FeaturesController (app/controllers/api/v1/
 * features_controller.rb) — a feature is keyed by its code (Rails:
 * resources :features, param: :code with the wildcard code regex).
 */
class FeaturesController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'feature';

    public function index(Request $request): JsonResponse
    {
        $result = FeaturesQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            searchTerm: $request->query('search_term'),
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new CollectionSerializer(
                $result->features,
                FeatureSerializer::class,
                [
                    'collection_name' => 'features',
                    'meta' => $this->paginationMetadata($result->features),
                ],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    public function create(Request $request): JsonResponse
    {
        $result = CreateService::call(
            organization: $this->currentOrganization(),
            params: $this->inputParams($request),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderFeature($result->feature);
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $feature = $this->findFeature($request);

        return $this->renderFeature($feature);
    }

    public function update(Request $request): JsonResponse
    {
        $feature = $this->findFeature($request);

        $result = UpdateService::call(
            feature: $feature,
            params: $this->inputParams($request),
            partial: true,
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit.

            return $this->renderFeature($result->feature);
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $feature = Feature::query()
            ->where('organization_id', (string) $this->currentOrganization()->id)
            ->where('code', $request->route('feature_code'))
            ->first();

        $result = DestroyService::call(feature: $feature);

        if ($result->success()) {
            // TODO(port): api_logs + audit.

            return $this->renderFeature($result->feature);
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ------------------------------------------------------------------------

    private function findFeature(Request $request): Feature
    {
        $feature = Feature::query()
            ->where('organization_id', (string) $this->currentOrganization()->id)
            ->where('code', $request->route('feature_code'))
            ->first();

        if ($feature === null) {
            throw new NotFoundException('feature');
        }

        return $feature;
    }

    private function renderFeature(Feature $feature): JsonResponse
    {
        return $this->renderSerializerJson((new FeatureSerializer(
            $feature,
            ['root_name' => 'feature'],
        ))->toJson());
    }

    /**
     * Port of `params.require(:feature).permit(...)` — the contract,
     * verbatim (Rails' permitted params).
     *
     * @return array<string, mixed>
     */
    private function inputParams(Request $request): array
    {
        /** @var mixed $feature */
        $feature = $this->requireParam($request, 'feature');

        if (! is_array($feature)) {
            throw new ParameterMissingException('feature');
        }

        return $this->permitParams($feature, [
            'code',
            'name',
            'description',
            'privileges' => [[
                'code',
                'name',
                'value_type',
                'config' => '*',
            ]],
        ]);
    }
}
