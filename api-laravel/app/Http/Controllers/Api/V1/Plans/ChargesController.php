<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Models\Plan;
use App\Models\Charge;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Charges\CreateService;
use App\Services\Charges\UpdateService;
use App\Serializers\V1\ChargeSerializer;
use App\Services\Charges\DestroyService;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Exceptions\Api\ParameterMissingException;
use App\Http\Controllers\Concerns\ForbidsLegacyBilling;

/**
 * Port of Rails' Api::V1::Plans::ChargesController (app/controllers/api/v1/
 * plans/charges_controller.rb).
 *
 * Not ported (dependencies do not exist yet): Charges::*ChildrenJob cascade
 * dispatch — `cascade_updates` is read and passed to the services, which
 * accept it as a no-op until child-plan cascade is a milestone.
 */
class ChargesController extends BaseController
{
    use ForbidsLegacyBilling;
    use Pagination;

    public function index(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);

        // Rails: .page(params[:page]).per(params[:per_page] || PER_PAGE)
        $charges = $plan->charges()
            ->parents()
            ->latest()
            ->paginate(
                (int) ($request->query('per_page') ?: self::PER_PAGE),
                ['*'],
                'page',
                max(1, (int) $request->query('page', 1)),
            );

        return $this->renderSerializerJson((new CollectionSerializer(
            $charges,
            ChargeSerializer::class,
            [
                'collection_name' => 'charges',
                'meta' => $this->paginationMetadata($charges),
                'includes' => ['taxes'],
            ],
        ))->toJson());
    }

    public function show(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);
        $charge = $this->findCharge($plan, $request);

        return $this->renderCharge($charge);
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
            return $this->renderCharge($result->charge);
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);
        $charge = $this->findCharge($plan, $request);
        $this->forbidLegacyBilling('update');

        $result = UpdateService::call(
            charge: $charge,
            params: $this->inputParams($request),
            cascadeUpdates: $this->cascadeUpdates($request),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit.
            return $this->renderCharge($result->charge);
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);
        $charge = $this->findCharge($plan, $request);
        $this->forbidLegacyBilling('destroy');

        $result = DestroyService::call(
            charge: $charge,
            cascadeUpdates: $this->cascadeUpdates($request),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit.
            return $this->renderCharge($result->charge);
        }

        $this->renderErrorResponse($result);
    }

    private function renderCharge(Charge $charge): JsonResponse
    {
        return $this->renderSerializerJson((new ChargeSerializer(
            $charge,
            ['root_name' => 'charge', 'includes' => ['taxes']],
        ))->toJson());
    }

    /**
     * Port of `find_charge` — plan-scoped, parent charges only, by code.
     */
    private function findCharge(Plan $plan, Request $request): Charge
    {
        $charge = $plan->charges()
            ->parents()
            ->where('code', $request->route('code'))
            ->first();

        if ($charge === null) {
            throw new NotFoundException('charge');
        }

        return $charge;
    }

    /**
     * Port of `cascade_updates?` — ActiveModel::Type::Boolean cast of
     * `params.dig(:charge, :cascade_updates)` (outside the permit list).
     */
    private function cascadeUpdates(Request $request): bool
    {
        return in_array($request->input('charge.cascade_updates'), ['true', 'TRUE', 't', 'T', '1', 1, true], true);
    }

    /**
     * Port of `input_params` — `params.require(:charge).permit(...)`, verbatim.
     *
     * @return array<string, mixed>
     */
    private function inputParams(Request $request): array
    {
        /** @var mixed $charge */
        $charge = $this->requireParam($request, 'charge');

        if (! is_array($charge)) {
            throw new ParameterMissingException('charge');
        }

        return $this->permitParams($charge, [
            'billable_metric_id',
            'code',
            'invoice_display_name',
            'charge_model',
            'pay_in_advance',
            'prorated',
            'invoiceable',
            'regroup_paid_fees',
            'min_amount_cents',
            'accepts_target_wallet',
            'properties' => '*',
            'filters' => [[
                'invoice_display_name',
                'properties' => '*',
                'values' => '*',
            ]],
            'tax_codes' => [],
            'applied_pricing_unit' => [
                'code',
                'conversion_rate',
            ],
        ]);
    }
}
