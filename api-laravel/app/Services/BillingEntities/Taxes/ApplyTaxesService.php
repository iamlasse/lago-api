<?php

declare(strict_types=1);

namespace App\Services\BillingEntities\Taxes;

use App\Models\Tax;
use Illuminate\Support\Str;
use App\Services\BaseResult;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' BillingEntities::Taxes::ApplyTaxesService
 * (app/services/billing_entities/taxes/apply_taxes_service.rb) — attaches
 * taxes to a billing entity through the billing_entities_taxes join, by tax
 * codes (no BillingEntityAppliedTax model exists yet — the join table is
 * written directly, the same convention as Tax::billingEntities).
 */
class ApplyTaxesService extends BaseService
{
    public function __construct(
        \App\Models\BillingEntity $billingEntity,
        private readonly array $taxCodes,
    ) {
        parent::__construct($billingEntity);
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('applied_taxes', 'taxes_to_apply');
        $billingEntity = $this->billingEntity;
        $taxCodes = $this->taxCodes;

        if ($taxCodes === []) {
            return $result;
        }

        // Rails: find_taxes_on_organization — all codes must resolve or the
        // whole call fails with not_found_failure!(resource: "tax").
        $taxesToApply = $billingEntity->organization->taxes()->whereIn('code', $taxCodes)->get();

        if ($taxesToApply->count() !== count($taxCodes)) {
            return $result->notFoundFailure('tax');
        }

        // Rails: billing_entity.applied_taxes
        //   .create_with(organization_id: tax.organization_id)
        //   .find_or_create_by!(tax:) — per-tax idempotent attach.
        $appliedTaxes = [];

        foreach ($taxesToApply as $tax) {
            /** @var Tax $tax */
            $attached = DB::table('billing_entities_taxes')
                ->where('billing_entity_id', $billingEntity->id)
                ->where('tax_id', $tax->id)
                ->exists();

            if (! $attached) {
                DB::table('billing_entities_taxes')->insert([
                    'id' => (string) Str::uuid(),
                    'billing_entity_id' => $billingEntity->id,
                    'tax_id' => $tax->id,
                    'organization_id' => $tax->organization_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $appliedTaxes[] = $tax;
        }

        $result->applied_taxes = $appliedTaxes;
        $result->taxes_to_apply = $taxesToApply->all();

        $this->refreshDraftInvoices();

        return $result;
    }
}
