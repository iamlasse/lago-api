<?php

declare(strict_types=1);

namespace App\Services\AddOns;

use App\Models\AddOn;
use App\Models\AddOnTax;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' AddOns::ApplyTaxesService
 * (app/services/add_ons/apply_taxes_service.rb) — syncs the add-on's tax
 * rows to the given tax codes: removes the ones that fell out of the list
 * and creates the missing ones.
 */
class ApplyTaxesService extends BaseService
{
    public function __construct(
        private readonly ?AddOn $addOn,
        private readonly array $taxCodes,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('applied_taxes');

        if ($this->addOn === null) {
            return $result->notFoundFailure('add_on');
        }

        $organization = $this->addOn->organization;
        $taxCodes = array_values(array_unique(array_filter(
            $this->taxCodes,
            static fn (mixed $code): bool => is_string($code) && $code !== '',
        )));

        $taxes = $organization->taxes()->whereIn('code', $taxCodes)->get();

        // Rails: return not_found_failure!(resource: "tax") if
        // (tax_codes - taxes.pluck(:code)).present?
        $knownCodes = $taxes->pluck('code')->all();

        if (array_diff($taxCodes, $knownCodes) !== []) {
            return $result->notFoundFailure('tax');
        }

        // Rails: remove the applied taxes whose tax fell out of the list.
        $removedTaxIds = $this->addOn->taxes()
            ->whereNotIn('code', $taxCodes)
            ->pluck('taxes.id');

        $this->addOn->appliedTaxes()->whereIn('tax_id', $removedTaxIds)->delete();

        // Rails: create_with(organization:).find_or_create_by!(tax:) per code.
        $appliedTaxes = [];

        DB::transaction(function () use (&$appliedTaxes, $taxCodes, $taxes): void {
            foreach ($taxCodes as $taxCode) {
                $tax = $taxes->firstWhere('code', $taxCode);
                assert($tax !== null);

                $appliedTax = AddOnTax::query()
                    ->firstOrCreate([
                        'add_on_id' => $this->addOn->id,
                        'tax_id' => $tax->id,
                    ], [
                        'organization_id' => $this->addOn->organization_id,
                    ]);

                $appliedTaxes[] = $appliedTax;
            }
        });

        $result->applied_taxes = $appliedTaxes;

        return $result;
    }
}
