<?php

declare(strict_types=1);

namespace App\Services\Charges;

use App\Models\Charge;
use App\Services\BaseResult;

/**
 * Port of Rails' Charges::ApplyTaxesService
 * (app/services/charges/apply_taxes_service.rb).
 */
class ApplyTaxesService extends \App\Services\BaseService
{
    public function __construct(
        private readonly ?Charge $charge,
        private readonly array $taxCodes,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('applied_taxes');

        if ($this->charge === null) {
            return $result->notFoundFailure('charge');
        }

        $charge = $this->charge;
        $taxCodes = $this->taxCodes;

        $taxes = $charge->plan->organization
            ->taxes()
            ->whereIn('code', $taxCodes)
            ->get();

        if (array_values(array_diff($taxCodes, $taxes->pluck('code')->all())) !== []) {
            return $result->notFoundFailure('tax');
        }

        $removedTaxIds = $charge->taxes()
            ->whereNotIn('code', $taxCodes === [] ? [''] : $taxCodes)
            ->pluck('taxes.id');

        $charge->appliedTaxes()->whereIn('tax_id', $removedTaxIds)->delete();

        $appliedTaxes = [];

        foreach ($taxCodes as $taxCode) {
            $appliedTaxes[] = \App\Models\ChargeTax::query()
                ->firstOrCreate([
                    'charge_id' => $charge->id,
                    'tax_id' => $taxes->firstWhere('code', $taxCode)->id,
                ], [
                    'organization_id' => $charge->organization_id,
                ]);
        }

        $result->applied_taxes = $appliedTaxes;

        return $result;
    }
}
