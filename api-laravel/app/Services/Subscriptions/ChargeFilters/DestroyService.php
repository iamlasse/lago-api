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
 * Port of Rails' Subscriptions::ChargeFilters::DestroyService
 * (app/services/subscriptions/charge_filters/destroy_service.rb) — the
 * GraphQL destroySubscriptionChargeFilter body.
 */
class DestroyService extends BaseService
{
    use PlanOverrideConcern;
    use ChargeOverrideConcern;

    public function __construct(
        private readonly ?Subscription $subscription,
        private readonly ?Charge $charge,
        private readonly ?ChargeFilter $chargeFilter,
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

                $targetFilter = $this->findFilterOnCharge($targetCharge);

                if ($targetFilter === null) {
                    $result->notFoundFailure('charge_filter')->raiseIfError();
                }

                $destroyResult = \App\Services\ChargeFilters\DestroyService::callBang(
                    chargeFilter: $targetFilter,
                );

                $result->charge_filter = $destroyResult->charge_filter;
            });
        } catch (FailedResult $e) {
            return $e->result;
        }

        return $result;
    }

    private function findFilterOnCharge(Charge $targetCharge): ?ChargeFilter
    {
        $filterValuesHash = $this->valuesHash($this->chargeFilter->toH());

        foreach ($targetCharge->filters as $filter) {
            if ($this->valuesHash($filter->toH()) === $filterValuesHash) {
                return $filter;
            }
        }

        return null;
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
