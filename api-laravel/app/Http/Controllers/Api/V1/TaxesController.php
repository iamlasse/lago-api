<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Tax;
use App\Queries\TaxesQuery;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Taxes\CreateService;
use App\Services\Taxes\UpdateService;
use App\Services\Taxes\DestroyService;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Exceptions\Api\ParameterMissingException;
use App\Serializers\V1\TaxWithBillingEntitiesSerializer;

/**
 * Port of Rails' Api::V1::TaxesController
 * (app/controllers/api/v1/taxes_controller.rb) — a tax is keyed by its
 * code (Rails: resources :taxes, param: :code).
 */
class TaxesController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'tax';

    public function create(Request $request): JsonResponse
    {
        $result = CreateService::call(
            organization: $this->currentOrganization(),
            params: $this->inputParams($request),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderTax($result->tax);
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $tax = $this->currentOrganization()
            ->taxes()
            ->where('code', $request->route('code'))
            ->first();

        $result = UpdateService::call(
            tax: $tax,
            params: $this->inputParams($request),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderTax($result->tax);
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $tax = $this->currentOrganization()
            ->taxes()
            ->where('code', $request->route('code'))
            ->first();

        $result = DestroyService::call(tax: $tax);

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderTax($result->tax);
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $tax = $this->currentOrganization()
            ->taxes()
            ->where('code', $request->route('code'))
            ->first();

        if ($tax === null) {
            throw new NotFoundException('tax');
        }

        return $this->renderTax($tax);
    }

    public function index(Request $request): JsonResponse
    {
        $result = TaxesQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->taxes,
                    TaxWithBillingEntitiesSerializer::class,
                    [
                        'collection_name' => 'taxes',
                        'meta' => $this->paginationMetadata($result->taxes),
                        'default_billing_entity' => $this->currentOrganization()->defaultBillingEntity,
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    /**
     * Port of `render_tax` — every single-tax response carries the default
     * billing entity so applied_to_organization can be recomputed.
     */
    private function renderTax(Tax $tax): JsonResponse
    {
        return $this->renderSerializerJson((new TaxWithBillingEntitiesSerializer(
            $tax,
            [
                'root_name' => 'tax',
                'default_billing_entity' => $this->currentOrganization()->defaultBillingEntity,
            ],
        ))->toJson());
    }

    /**
     * Port of `params.require(:tax).permit(...)` — the contract, verbatim
     * (Rails' permitted params).
     *
     * @return array<string, mixed>
     */
    private function inputParams(Request $request): array
    {
        /** @var mixed $tax */
        $tax = $this->requireParam($request, 'tax');

        if (! is_array($tax)) {
            throw new ParameterMissingException('tax');
        }

        return $this->permitParams($tax, [
            'code',
            'description',
            'name',
            'rate',
            'applied_to_organization',
        ]);
    }
}
