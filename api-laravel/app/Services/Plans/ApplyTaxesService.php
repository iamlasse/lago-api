<?php

declare(strict_types=1);

namespace App\Services\Plans;

use App\Models\Plan;
use App\Models\PlanTax;
use App\Services\BaseResult;

/**
 * Port of Rails' Plans::ApplyTaxesService
 * (app/services/plans/apply_taxes_service.rb).
 */
class ApplyTaxesService extends \App\Services\BaseService
{
    public function __construct(
        private readonly ?Plan $plan,
        private readonly array $taxCodes,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('applied_taxes');

        if ($this->plan === null) {
            return $result->notFoundFailure('plan');
        }

        $plan = $this->plan;
        $taxCodes = $this->taxCodes;

        $taxes = $plan->organization
            ->taxes()
            ->whereIn('code', $taxCodes)
            ->get();

        if (array_values(array_diff($taxCodes, $taxes->pluck('code')->all())) !== []) {
            return $result->notFoundFailure('tax');
        }

        $removedTaxIds = $plan->taxes()
            ->whereNotIn('code', $taxCodes === [] ? [''] : $taxCodes)
            ->pluck('taxes.id');

        $plan->appliedTaxes()->whereIn('tax_id', $removedTaxIds)->delete();

        $appliedTaxes = [];

        foreach ($taxCodes as $taxCode) {
            $appliedTaxes[] = PlanTax::query()
                ->firstOrCreate([
                    'plan_id' => $plan->id,
                    'tax_id' => $taxes->firstWhere('code', $taxCode)->id,
                ], [
                    'organization_id' => $plan->organization_id,
                ]);
        }

        $result->applied_taxes = $appliedTaxes;

        return $result;
    }
}
