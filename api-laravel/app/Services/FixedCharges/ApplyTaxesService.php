<?php

declare(strict_types=1);

namespace App\Services\FixedCharges;

use App\Models\FixedCharge;
use App\Services\BaseResult;
use App\Models\FixedChargeTax;

/**
 * Port of Rails' FixedCharges::ApplyTaxesService
 * (app/services/fixed_charges/apply_taxes_service.rb).
 */
class ApplyTaxesService extends \App\Services\BaseService
{
    public function __construct(
        private readonly ?FixedCharge $fixedCharge,
        private readonly array $taxCodes,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('applied_taxes');

        if ($this->fixedCharge === null) {
            return $result->notFoundFailure('fixed_charge');
        }

        $fixedCharge = $this->fixedCharge;
        $taxCodes = $this->taxCodes;

        $taxes = $fixedCharge->plan->organization
            ->taxes()
            ->whereIn('code', $taxCodes)
            ->get();

        if (array_values(array_diff($taxCodes, $taxes->pluck('code')->all())) !== []) {
            return $result->notFoundFailure('tax');
        }

        $removedTaxIds = $fixedCharge->taxes()
            ->whereNotIn('code', $taxCodes === [] ? [''] : $taxCodes)
            ->pluck('taxes.id');

        $fixedCharge->appliedTaxes()->whereIn('tax_id', $removedTaxIds)->delete();

        $appliedTaxes = [];

        foreach ($taxCodes as $taxCode) {
            $appliedTaxes[] = FixedChargeTax::query()
                ->firstOrCreate([
                    'fixed_charge_id' => $fixedCharge->id,
                    'tax_id' => $taxes->firstWhere('code', $taxCode)->id,
                ], [
                    'organization_id' => $fixedCharge->organization_id,
                ]);
        }

        $result->applied_taxes = $appliedTaxes;

        return $result;
    }
}
