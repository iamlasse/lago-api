<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Tax;
use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Customers::ApplyTaxesService — attaches taxes to a
 * customer through the customers_taxes join, by tax codes.
 */
class ApplyTaxesService extends BaseService
{
    public function __construct(
        private readonly ?Customer $customer,
        private readonly array $taxCodes,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('applied_taxes');
        $customer = $this->customer;
        $taxCodes = $this->taxCodes;

        if ($customer === null) {
            return $result->notFoundFailure('customer');
        }

        try {
            return DB::transaction(function () use ($customer, $taxCodes, $result): BaseResult {
                $taxes = $customer->organization->taxes()->whereIn('code', $taxCodes)->get();
                $foundCodes = $taxes->pluck('code')->all();

                $missing = array_values(array_diff($taxCodes, $foundCodes));

                if ($missing !== []) {
                    return $result->notFoundFailure('tax');
                }

                // Remove applied taxes whose code is no longer requested.
                $customer->appliedTaxes()
                    ->whereNotIn('tax_id', $taxes->pluck('id'))
                    ->delete();

                $appliedTaxes = [];

                foreach ($taxCodes as $taxCode) {
                    $tax = $taxes->firstWhere('code', $taxCode);

                    $appliedTax = $customer->appliedTaxes()
                        ->firstOrCreate(
                            ['tax_id' => $tax->id],
                            ['organization_id' => $customer->organization_id],
                        );

                    $appliedTaxes[] = $appliedTax;
                }

                // Mark draft invoices for refresh.
                $customer->invoices()->where('status', 1)->update(['ready_to_be_refreshed' => true]);

                $result->applied_taxes = $appliedTaxes;

                return $result;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return $result->recordValidationFailure([]);
        }
    }
}
