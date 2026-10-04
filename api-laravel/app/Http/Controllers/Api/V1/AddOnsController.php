<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\AddOn;
use App\Queries\AddOnsQuery;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\AddOns\CreateService;
use App\Services\AddOns\UpdateService;
use App\Serializers\V1\AddOnSerializer;
use App\Services\AddOns\DestroyService;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Exceptions\Api\ParameterMissingException;

/**
 * Port of Rails' Api::V1::AddOnsController
 * (app/controllers/api/v1/add_ons_controller.rb) — add-ons are keyed by
 * code (Rails: resources :add_ons, param: :code with the wildcard code
 * constraint).
 */
class AddOnsController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'add_on';

    public function create(Request $request): JsonResponse
    {
        $result = CreateService::call(args: array_merge($this->inputParams($request), [
            'organization_id' => $this->currentOrganization()->id,
        ]));

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderAddOn($result->add_on);
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $addOn = $this->currentOrganization()
            ->addOns()
            ->where('code', $request->route('code'))
            ->first();

        $result = UpdateService::call(
            addOn: $addOn,
            params: $this->inputParams($request),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderAddOn($result->add_on);
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $addOn = $this->currentOrganization()
            ->addOns()
            ->where('code', $request->route('code'))
            ->first();

        $result = DestroyService::call(addOn: $addOn);

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderAddOn($result->add_on);
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $addOn = $this->currentOrganization()
            ->addOns()
            ->where('code', $request->route('code'))
            ->first();

        if ($addOn === null) {
            throw new NotFoundException('add_on');
        }

        return $this->renderAddOn($addOn);
    }

    public function index(Request $request): JsonResponse
    {
        $result = AddOnsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->add_ons,
                    AddOnSerializer::class,
                    [
                        'collection_name' => 'add_ons',
                        'meta' => $this->paginationMetadata($result->add_ons),
                        'includes' => ['taxes'],
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    /**
     * Port of `params.expect(add_on: [...])` — the create/update contract,
     * verbatim (Rails' permitted params).
     *
     * @return array<string, mixed>
     */
    private function inputParams(Request $request): array
    {
        /** @var mixed $addOn */
        $addOn = $this->requireParam($request, 'add_on');

        if (! is_array($addOn)) {
            throw new ParameterMissingException('add_on');
        }

        return $this->permitParams($addOn, [
            'name',
            'invoice_display_name',
            'code',
            'amount_cents',
            'amount_currency',
            'description',
            'tax_codes' => [],
        ]);
    }

    private function renderAddOn(AddOn $addOn): JsonResponse
    {
        return $this->renderSerializerJson(
            (new AddOnSerializer(
                $addOn,
                ['root_name' => 'add_on', 'includes' => ['taxes']],
            ))->toJson()
        );
    }
}
