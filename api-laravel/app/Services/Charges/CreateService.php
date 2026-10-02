<?php

declare(strict_types=1);

namespace App\Services\Charges;

use App\Models\Plan;
use App\Models\Charge;
use App\Enums\ChargeModel;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\BillableMetric;
use Illuminate\Support\Facades\DB;
use App\Services\ChargeModels\FilterPropertiesService;
use App\Services\ChargeFilters\CreateOrUpdateBatchService;
use App\Services\ChargeModels\BuildDefaultPropertiesService;

use function array_key_exists;

/**
 * Port of Rails' Charges::CreateService (app/services/charges/create_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): AppliedPricingUnits::CreateService — args accepted, ignored.
 * - TODO(port): Charges::CreateChildrenJob cascade dispatch (parent-plan
 *   children are a later milestone); `cascade_updates` is accepted but no-op.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?Plan $plan,
        private readonly array $params,
        private readonly bool $cascadeUpdates = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('charge');

        if ($this->plan === null) {
            return $result->notFoundFailure('plan');
        }

        $plan = $this->plan;
        $params = $this->params;

        $billableMetric = BillableMetric::query()
            ->where('organization_id', $plan->organization_id)
            ->where('id', $params['billable_metric_id'] ?? null)
            ->first();

        if ($billableMetric === null) {
            return $result->notFoundFailure('billable_metric');
        }

        try {
            $charge = DB::transaction(function () use ($plan, $params, $result): Charge {
                $charge = $plan->charges()->make([
                    'organization_id' => $plan->organization_id,
                    'billable_metric_id' => $params['billable_metric_id'] ?? null,
                    'code' => $params['code'] ?? null,
                    'invoice_display_name' => $params['invoice_display_name'] ?? null,
                    'amount_currency' => $params['amount_currency'] ?? null,
                    'charge_model' => $params['charge_model'] ?? null,
                    'parent_id' => $params['parent_id'] ?? null,
                    'pay_in_advance' => $params['pay_in_advance'] ?? false,
                    'prorated' => $params['prorated'] ?? false,
                ]);

                // Rails assigns the enum NAME; the column stores the integer
                // position. An unknown name fails the enum's inclusion
                // validation with "value_is_invalid" at save time.
                $chargeModelOption = ChargeModel::fromOption($params['charge_model'] ?? null);

                if ($chargeModelOption === null && ($params['charge_model'] ?? null) !== null) {
                    $result->validationFailure(['charge_model' => ['value_is_invalid']])->raiseIfError();
                }

                $charge->charge_model = $chargeModelOption;

                $chargeModelName = $chargeModelOption === null
                    ? null
                    : ChargeModel::from($chargeModelOption)->label();

                $properties = (isset($params['properties']) && is_array($params['properties']) && $params['properties'] !== [])
                    ? $params['properties']
                    : BuildDefaultPropertiesService::call($chargeModelName)->properties;

                $charge->properties = FilterPropertiesService::call(
                    chargeable: $charge,
                    properties: $properties,
                )->properties;

                if (($params['filters'] ?? null) !== null && $params['filters'] !== []) {
                    $charge->save();

                    CreateOrUpdateBatchService::call(
                        charge: $charge,
                        filtersParams: array_map(fn ($filter) => (array) $filter, $params['filters']),
                    )->raiseIfError();
                }

                if ($this->premium()) {
                    if (($params['invoiceable'] ?? null) !== null) {
                        $charge->invoiceable = $params['invoiceable'];
                    }

                    if (array_key_exists('regroup_paid_fees', $params)) {
                        $charge->regroup_paid_fees = $params['regroup_paid_fees'];
                    }

                    $charge->min_amount_cents = $params['min_amount_cents'] ?? 0;

                    if ($this->eventsTargetingWalletsEnabled($plan)) {
                        $charge->accepts_target_wallet = $params['accepts_target_wallet'] ?? false;
                    }
                }

                $this->saveCharge($charge);

                // TODO(port): AppliedPricingUnits::CreateService — the
                // applied_pricing_unit arg is accepted and ignored.

                if (array_key_exists('tax_codes', $params) && $params['tax_codes'] !== null) {
                    ApplyTaxesService::call(
                        charge: $charge,
                        taxCodes: (array) $params['tax_codes'],
                    )->raiseIfError();
                }

                return $charge;
            });

            $result->charge = $charge;

            // TODO(port): Charges::CreateChildrenJob — cascade to child plans.

            return $result;
        } catch (\App\Services\Failures\FailedResult $e) {
            return $this->embedFailure($result, $e);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Rails: rescue ActiveRecord::RecordNotUnique — the partial unique
            // index on (plan_id, code) among parents.
            return $result->singleValidationFailure('value_already_exist', 'code');
        }
    }

    private function saveCharge(Charge $charge): void
    {
        $errors = $charge->validateAttributes();

        if ($errors !== []) {
            // Rails: charge.save! -> RecordInvalid, rescued below.
            static::makeResult()->recordValidationFailure($errors)->raiseIfError();
        }

        $charge->save();
    }

    private function eventsTargetingWalletsEnabled(Plan $plan): bool
    {
        $integrations = $plan->organization->premium_integrations ?? [];

        return $this->premium()
            && in_array('events_targeting_wallets', (array) $integrations, true);
    }
}
