<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Plan;
use App\Queries\PlansQuery;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Plans\CreateService;
use App\Services\Plans\UpdateService;
use App\Serializers\V1\PlanSerializer;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Services\Plans\PrepareDestroyService;
use App\Exceptions\Api\ParameterMissingException;

/**
 * Port of Rails' Api::V1::PlansController (app/controllers/api/v1/
 * plans_controller.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - plan metadata persistence (Metadata::ItemMetadata) — the `metadata`
 *   input is permitted and ignored by the service;
 * - usage_thresholds / entitlements include payloads (the serializer
 *   renders them empty; UsageThresholds::UpdateService and the entitlement
 *   models are a later milestone).
 */
class PlansController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'plan';

    public function create(Request $request): JsonResponse
    {
        $result = CreateService::call(
            args: array_merge($this->inputParams($request), [
                'organization_id' => $this->currentOrganization()->id,
            ]),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).
            return $this->renderPlan($result->plan);
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $plan = $this->currentOrganization()
            ->plans()
            ->parents()
            ->where('code', $request->route('code'))
            ->first();

        $result = UpdateService::call(
            plan: $plan,
            params: $this->inputParams($request),
        );

        if ($result->success()) {
            // Rails reloads to eager-load usage_thresholds / fixed_charges /
            // entitlements like :entitlements — the serializer lazy-loads the
            // ported relations itself.
            $plan = Plan::query()->find($result->plan->id);

            return $this->renderPlan($plan);
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $plan = $this->currentOrganization()
            ->plans()
            ->parents()
            ->where('code', $request->route('code'))
            ->first();

        $result = PrepareDestroyService::call(plan: $plan);

        if ($result->success()) {
            // Rails reloads with_discarded + includes — the plan is not
            // discarded yet at this point (pending_deletion flag), but the
            // reload mirrors the Rails flow.
            $plan = Plan::withTrashed()->find($result->plan->id);

            return $this->renderPlan($plan);
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $plan = $this->currentOrganization()
            ->plans()
            ->parents()
            ->where('code', $request->route('code'))
            ->first();

        if ($plan === null) {
            throw new NotFoundException('plan');
        }

        return $this->renderPlan($plan);
    }

    public function index(Request $request): JsonResponse
    {
        $result = PlansQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: ['include_pending_deletion' => true],
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new \App\Serializers\Base\CollectionSerializer(
                    $result->plans,
                    PlanSerializer::class,
                    [
                        'collection_name' => 'plans',
                        'meta' => $this->paginationMetadata($result->plans),
                        'includes' => [
                            'charges',
                            'usage_thresholds',
                            'applicable_usage_thresholds',
                            'taxes',
                            'minimum_commitment',
                            'entitlements',
                        ],
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    /**
     * Port of `render_plan`: every single-plan response carries the same
     * includes, verbatim.
     */
    private function renderPlan(Plan $plan): JsonResponse
    {
        return $this->renderSerializerJson((new PlanSerializer(
            $plan,
            [
                'root_name' => 'plan',
                'includes' => [
                    'charges',
                    'fixed_charges',
                    'usage_thresholds',
                    'applicable_usage_thresholds',
                    'taxes',
                    'minimum_commitment',
                    'entitlements',
                ],
            ],
        ))->toJson());
    }

    /**
     * Port of `input_params` — `params.require(:plan).permit(...)`, the
     * create/update contract, verbatim (Rails' permitted params).
     *
     * @return array<string, mixed>
     */
    private function inputParams(Request $request): array
    {
        /** @var mixed $plan */
        $plan = $this->requireParam($request, 'plan');

        if (! is_array($plan)) {
            throw new ParameterMissingException('plan');
        }

        return $this->permitParams($plan, [
            'name',
            'invoice_display_name',
            'code',
            'interval',
            'description',
            'amount_cents',
            'amount_currency',
            'trial_period',
            'pay_in_advance',
            'bill_charges_monthly',
            'bill_fixed_charges_monthly',
            'cascade_updates',
            'metadata' => '*',
            'tax_codes' => [],
            'minimum_commitment' => [
                'id',
                'invoice_display_name',
                'amount_cents',
                'tax_codes' => [],
            ],
            'charges' => [[
                'id',
                'code',
                'invoice_display_name',
                'billable_metric_id',
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
            ]],
            'fixed_charges' => [[
                'id',
                'code',
                'invoice_display_name',
                'units',
                'add_on_id',
                'apply_units_immediately',
                'charge_model',
                'pay_in_advance',
                'prorated',
                'properties' => '*',
                'tax_codes' => [],
            ]],
            'usage_thresholds' => [[
                'id',
                'threshold_display_name',
                'amount_cents',
                'recurring',
            ]],
        ]);
    }
}
