<?php

declare(strict_types=1);

namespace App\Services\BillingEntities\Taxes;

use App\Services\BaseResult;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' BillingEntities::Taxes::RemoveTaxesService
 * (app/services/billing_entities/taxes/remove_taxes_service.rb) — detaches
 * taxes from a billing entity through the billing_entities_taxes join, by
 * tax codes.
 */
class RemoveTaxesService extends BaseService
{
    public function __construct(
        \App\Models\BillingEntity $billingEntity,
        private readonly array $taxCodes,
    ) {
        parent::__construct($billingEntity);
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('taxes_to_remove');
        $billingEntity = $this->billingEntity;
        $taxCodes = $this->taxCodes;

        if ($taxCodes === []) {
            return $result;
        }

        // Rails: find_taxes_to_remove — all codes must resolve or the whole
        // call fails with not_found_failure!(resource: "tax").
        $taxesToRemove = $billingEntity->organization->taxes()->whereIn('code', $taxCodes)->get();

        if ($taxesToRemove->count() !== count($taxCodes)) {
            return $result->notFoundFailure('tax');
        }

        // Rails: billing_entity.applied_taxes.where(tax:).destroy_all.
        DB::table('billing_entities_taxes')
            ->where('billing_entity_id', $billingEntity->id)
            ->whereIn('tax_id', $taxesToRemove->pluck('id'))
            ->delete();

        $result->taxes_to_remove = $taxesToRemove->all();

        $this->refreshDraftInvoices();

        return $result;
    }
}
