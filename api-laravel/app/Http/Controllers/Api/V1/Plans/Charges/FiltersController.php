<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans\Charges;

use App\Models\Plan;
use App\Models\Charge;
use App\Models\ChargeFilter;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\ChargeFilterSerializer;
use App\Http\Controllers\Api\V1\Plans\BaseController;

/**
 * Port of Rails' Api::V1::Plans::Charges::FiltersController (app/controllers/
 * api/v1/plans/charges/filters_controller.rb) — read endpoints only.
 *
 * Not registered: create / update / destroy — their services
 * (ChargeFilters::CreateService / UpdateService / DestroyService) are not
 * ported yet; only CreateOrUpdateBatchService (nested under charge
 * create/update) exists.
 */
class FiltersController extends BaseController
{
    use Pagination;

    public function index(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);
        $charge = $this->findCharge($plan, $request);

        // Rails: .page(params[:page]).per(params[:per_page] || PER_PAGE)
        $chargeFilters = $charge->filters()
            ->paginate(
                (int) ($request->query('per_page') ?: self::PER_PAGE),
                ['*'],
                'page',
                max(1, (int) $request->query('page', 1)),
            );

        return $this->renderSerializerJson((new CollectionSerializer(
            $chargeFilters,
            ChargeFilterSerializer::class,
            [
                'collection_name' => 'filters',
                'meta' => $this->paginationMetadata($chargeFilters),
            ],
        ))->toJson());
    }

    public function show(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);
        $charge = $this->findCharge($plan, $request);
        $chargeFilter = $this->findChargeFilter($charge, $request);

        return $this->renderSerializerJson((new ChargeFilterSerializer(
            $chargeFilter,
            ['root_name' => 'filter'],
        ))->toJson());
    }

    /**
     * Port of `find_charge` — plan-scoped, parent charges only, by
     * `:charge_code`.
     */
    private function findCharge(Plan $plan, Request $request): Charge
    {
        $charge = $plan->charges()
            ->parents()
            ->where('code', $request->route('charge_code'))
            ->first();

        if ($charge === null) {
            throw new NotFoundException('charge');
        }

        return $charge;
    }

    /**
     * Port of `find_charge_filter` — by id (RecordNotFound renders the
     * charge_filter_not_found envelope).
     */
    private function findChargeFilter(Charge $charge, Request $request): ChargeFilter
    {
        $chargeFilter = $charge->filters()->where('id', $request->route('id'))->first();

        if ($chargeFilter === null) {
            throw new NotFoundException('charge_filter');
        }

        return $chargeFilter;
    }
}
