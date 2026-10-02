<?php

declare(strict_types=1);

namespace App\Services\Commitments;

use App\Models\Commitment;
use App\Services\BaseResult;
use App\Models\CommitmentTax;

/**
 * Port of Rails' Commitments::ApplyTaxesService — the minimal piece the
 * Plans create/update services call for the premium minimum commitment.
 */
class ApplyTaxesService extends \App\Services\BaseService
{
    public function __construct(
        private readonly ?Commitment $commitment,
        private readonly array $taxCodes,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('applied_taxes');

        if ($this->commitment === null) {
            return $result->notFoundFailure('commitment');
        }

        $commitment = $this->commitment;
        $taxCodes = $this->taxCodes;

        $taxes = $commitment->plan->organization
            ->taxes()
            ->whereIn('code', $taxCodes)
            ->get();

        if (array_values(array_diff($taxCodes, $taxes->pluck('code')->all())) !== []) {
            return $result->notFoundFailure('tax');
        }

        $removedTaxIds = $commitment->taxes()
            ->whereNotIn('code', $taxCodes === [] ? [''] : $taxCodes)
            ->pluck('taxes.id');

        $commitment->appliedTaxes()->whereIn('tax_id', $removedTaxIds)->delete();

        $appliedTaxes = [];

        foreach ($taxCodes as $taxCode) {
            $appliedTaxes[] = CommitmentTax::query()
                ->firstOrCreate([
                    'commitment_id' => $commitment->id,
                    'tax_id' => $taxes->firstWhere('code', $taxCode)->id,
                ], [
                    'organization_id' => $commitment->organization_id,
                ]);
        }

        $result->applied_taxes = $appliedTaxes;

        return $result;
    }
}
