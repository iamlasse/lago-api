<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Models\Plan;
use App\Models\FixedCharge;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\Api\NotFoundException;
use App\Services\FixedCharges\CreateService;
use App\Services\FixedCharges\UpdateService;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\V1\FixedChargeSerializer;
use App\Services\FixedCharges\DestroyService;
use App\Serializers\Base\CollectionSerializer;
use App\Exceptions\Api\ParameterMissingException;
use App\Http\Controllers\Concerns\ForbidsLegacyBilling;

/**
 * Port of Rails' Api::V1::Plans::FixedChargesController (app/controllers/
 * api/v1/plans/fixed_charges_controller.rb).
 *
 * Not ported (dependencies do not exist yet): FixedCharges::*ChildrenJob
 * cascade dispatch and FixedChargeEvent emission — `cascade_updates` is read
 * and passed to the services, which accept it as a no-op until child-plan
 * cascade is a milestone.
 */
class FixedChargesController extends BaseController
{
    use ForbidsLegacyBilling;
    use Pagination;

    public function index(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);

        // Rails: .page(params[:page]).per(params[:per_page] || PER_PAGE)
        $fixedCharges = $plan->fixedCharges()
            ->parents()
            ->latest()
            ->paginate(
                (int) ($request->query('per_page') ?: self::PER_PAGE),
                ['*'],
                'page',
                max(1, (int) $request->query('page', 1)),
            );

        return $this->renderSerializerJson((new CollectionSerializer(
            $fixedCharges,
            FixedChargeSerializer::class,
            [
                'collection_name' => 'fixed_charges',
                'meta' => $this->paginationMetadata($fixedCharges),
                'includes' => ['taxes'],
            ],
        ))->toJson());
    }

    public function show(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);
        $fixedCharge = $this->findFixedCharge($plan, $request);

        return $this->renderFixedCharge($fixedCharge);
    }

    public function create(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);
        $this->forbidLegacyBilling('create');

        $result = CreateService::call(
            plan: $plan,
            params: $this->inputParams($request),
            cascadeUpdates: $this->cascadeUpdates($request),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).
            return $this->renderFixedCharge($result->fixed_charge);
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);
        $fixedCharge = $this->findFixedCharge($plan, $request);
        $this->forbidLegacyBilling('update');

        $result = UpdateService::call(
            fixedCharge: $fixedCharge,
            params: $this->inputParams($request),
            timestamp: now()->getTimestamp(),
            cascadeUpdates: $this->cascadeUpdates($request),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit.
            return $this->renderFixedCharge($result->fixed_charge);
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);
        $fixedCharge = $this->findFixedCharge($plan, $request);
        $this->forbidLegacyBilling('destroy');

        $result = DestroyService::call(
            fixedCharge: $fixedCharge,
            cascadeUpdates: $this->cascadeUpdates($request),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit.
            return $this->renderFixedCharge($result->fixed_charge);
        }

        $this->renderErrorResponse($result);
    }

    private function renderFixedCharge(FixedCharge $fixedCharge): JsonResponse
    {
        return $this->renderSerializerJson((new FixedChargeSerializer(
            $fixedCharge,
            ['root_name' => 'fixed_charge', 'includes' => ['taxes']],
        ))->toJson());
    }

    /**
     * Port of `find_fixed_charge` — plan-scoped, parent charges only, by code.
     */
    private function findFixedCharge(Plan $plan, Request $request): FixedCharge
    {
        $fixedCharge = $plan->fixedCharges()
            ->parents()
            ->where('code', $request->route('code'))
            ->first();

        if ($fixedCharge === null) {
            throw new NotFoundException('fixed_charge');
        }

        return $fixedCharge;
    }

    /**
     * Port of `cascade_updates?` — ActiveModel::Type::Boolean cast of
     * `params.dig(:fixed_charge, :cascade_updates)` (outside the permit list).
     */
    private function cascadeUpdates(Request $request): bool
    {
        return in_array($request->input('fixed_charge.cascade_updates'), ['true', 'TRUE', 't', 'T', '1', 1, true], true);
    }

    /**
     * Port of `input_params` — `params.require(:fixed_charge).permit(...)`,
     * verbatim.
     *
     * @return array<string, mixed>
     */
    private function inputParams(Request $request): array
    {
        /** @var mixed $fixedCharge */
        $fixedCharge = $this->requireParam($request, 'fixed_charge');

        if (! is_array($fixedCharge)) {
            throw new ParameterMissingException('fixed_charge');
        }

        return $this->permitParams($fixedCharge, [
            'add_on_id',
            'add_on_code',
            'code',
            'invoice_display_name',
            'charge_model',
            'pay_in_advance',
            'prorated',
            'units',
            'apply_units_immediately',
            'properties' => '*',
            'tax_codes' => [],
        ]);
    }
}
