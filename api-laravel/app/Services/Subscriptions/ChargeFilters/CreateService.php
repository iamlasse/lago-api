<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\ChargeFilters;

use App\Models\Charge;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;
use App\Services\Subscriptions\Concerns\ChargeOverrideConcern;
use App\Services\Subscriptions\Concerns\PlanOverrideConcern;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Subscriptions::ChargeFilters::CreateService
 * (app/services/subscriptions/charge_filters/create_service.rb) — the
 * GraphQL createSubscriptionChargeFilter body.
 */
class CreateService extends BaseService
{
    use PlanOverrideConcern;
    use ChargeOverrideConcern;

    public function __construct(
        private readonly ?Subscription $subscription,
        private readonly ?Charge $charge,
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

        if (($this->params['values'] ?? null) === null || (array) $this->params['values'] === []) {
            return $result->singleValidationFailure('value_is_mandatory', 'values');
        }

        try {
            DB::transaction(function () use ($result): void {
                $targetPlan = $this->ensurePlanOverride();
                $targetCharge = $this->findOrCreateChargeOverride($targetPlan);

                $sortedValues = (array) $this->params['values'];
                ksort($sortedValues);

                foreach ($targetCharge->filters as $existing) {
                    if ($this->valuesHash($existing->toH()) === $this->valuesHash($sortedValues)) {
                        $result->singleValidationFailure('value_already_exists', 'values')->raiseIfError();
                    }
                }

                $createResult = \App\Services\ChargeFilters\CreateService::callBang(
                    charge: $targetCharge,
                    params: $this->params,
                );

                $result->charge_filter = $createResult->charge_filter;
            });
        } catch (FailedResult $e) {
            return $e->result;
        }

        return $result;
    }

    /**
     * Rails compares `f.to_h.sort == sorted_values` — the values hash with
     * sorted entries (and sorted values inside each entry).
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
