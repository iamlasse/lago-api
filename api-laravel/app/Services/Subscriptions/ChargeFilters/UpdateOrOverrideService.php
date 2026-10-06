<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\ChargeFilters;

use App\Models\Charge;
use App\Models\ChargeFilter;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;
use App\Services\Subscriptions\Concerns\ChargeOverrideConcern;
use App\Services\Subscriptions\Concerns\PlanOverrideConcern;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Subscriptions::ChargeFilters::UpdateOrOverrideService
 * (app/services/subscriptions/charge_filters/update_or_override_service.rb) —
 * the GraphQL updateSubscriptionChargeFilter body.
 */
class UpdateOrOverrideService extends BaseService
{
    use PlanOverrideConcern;
    use ChargeOverrideConcern;

    public function __construct(
        private readonly ?Subscription $subscription,
        private readonly ?Charge $charge,
        private readonly ?ChargeFilter $chargeFilter,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('charge_filter');

        if (! \App\Support\License::premium()) {
            return $result->forbiddenFailure();
        }

        if ($this->subscription === null) {
            return $result->notFoundFailure('subscription');
        }

        if ($this->charge === null) {
            return $result->notFoundFailure('charge');
        }

        if ($this->chargeFilter === null) {
            return $result->notFoundFailure('charge_filter');
        }

        try {
            DB::transaction(function () use ($result): void {
                $targetPlan = $this->ensurePlanOverride();
                $targetCharge = $this->findOrCreateChargeOverride($targetPlan);
                $result->charge_filter = $this->findOrCreateFilterOverride($targetCharge);
            });
        } catch (FailedResult $e) {
            return $e->result;
        }

        return $result;
    }

    private function findOrCreateFilterOverride(Charge $targetCharge): ChargeFilter
    {
        $filterValuesHash = $this->chargeFilter->toH();

        $existingFilter = $this->findFilterByValues($targetCharge, $filterValuesHash);

        if ($existingFilter !== null) {
            return $this->updateFilter($existingFilter, $targetCharge);
        }

        $createResult = \App\Services\ChargeFilters\CreateService::callBang(
            charge: $targetCharge,
            params: [
                'values' => $filterValuesHash,
                'properties' => array_key_exists('properties', $this->params)
                    ? $this->params['properties']
                    : $this->chargeFilter->properties,
                'invoice_display_name' => array_key_exists('invoice_display_name', $this->params)
                    ? $this->params['invoice_display_name']
                    : $this->chargeFilter->invoice_display_name,
            ],
        );

        return $createResult->charge_filter;
    }

    private function findFilterByValues(Charge $targetCharge, array $filterValuesHash): ?ChargeFilter
    {
        $wanted = $this->valuesHash($filterValuesHash);

        foreach ($targetCharge->filters as $filter) {
            if ($this->valuesHash($filter->toH()) === $wanted) {
                return $filter;
            }
        }

        return null;
    }

    private function updateFilter(ChargeFilter $existingFilter, Charge $targetCharge): ChargeFilter
    {
        if (array_key_exists('properties', $this->params)) {
            $propertiesParam = $this->params['properties'];
            if (is_array($propertiesParam)) {
                unset($propertiesParam['presentation_group_keys']);
            }

            $existingFilter->properties = \App\Services\ChargeModels\FilterPropertiesService::call(
                chargeable: $targetCharge,
                properties: $propertiesParam,
            )->properties;
        }

        if (array_key_exists('invoice_display_name', $this->params)) {
            $existingFilter->invoice_display_name = $this->params['invoice_display_name'];
        }

        $existingFilter->save();

        return $existingFilter->refresh();
    }

    /**
     * Rails compares `f.to_h.sort == filter_values_hash.sort`.
     *
     * @param  array<string, mixed>  $hash
     */
    private function valuesHash(array $hash): string
    {
        ksort($hash);

        $normalized = [];

        foreach ($hash as $key => $values) {
            $values = array_values((array) $values);
            sort($values);
            $normalized[$key] = $values;
        }

        return (string) json_encode($normalized);
    }
}
