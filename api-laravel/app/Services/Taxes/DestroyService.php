<?php

declare(strict_types=1);

namespace App\Services\Taxes;

use App\Models\Tax;
use App\Models\Invoice;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Taxes::DestroyService (app/services/taxes/destroy_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): BillingEntities::Taxes::RemoveTaxesService per billing entity
 *   (the billing_entities_taxes join rows the Rails version removes through
 *   it are left in place until that service is ported).
 * - TODO(port): activity log middleware.
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?Tax $tax,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('tax');
        $tax = $this->tax;

        if ($tax === null) {
            return $result->notFoundFailure('tax');
        }

        $result->tax = $tax;

        if ($tax->trashed()) {
            return $result;
        }

        // NOTE: we must retrieve the list of draft invoices before proceeding
        // to destroy as we need the applied_tax relation.
        $draftInvoiceIds = $this->draftInvoiceIds($tax);

        DB::transaction(function () use ($tax): void {
            // TODO(port): BillingEntities::Taxes::RemoveTaxesService.call!(
            //   billing_entity:, tax_codes: [tax.code]) for every
            //   tax.billing_entities entry.

            // Rails: tax.applied_taxes.delete_all (customers_taxes).
            DB::table('customers_taxes')->where('tax_id', $tax->id)->delete();

            // Rails: tax.draft_fee_taxes.delete_all — fees_taxes of fees whose
            // invoice is draft.
            DB::table('fees_taxes')
                ->where('tax_id', $tax->id)
                ->whereIn('fee_id', DB::table('fees')
                    ->join('invoices', 'invoices.id', '=', 'fees.invoice_id')
                    ->where('invoices.status', 0) // Rails: Invoice.draft (draft: 0)
                    ->select('fees.id'))
                ->delete();

            // Rails: tax.draft_invoice_taxes.delete_all — invoices_taxes of
            // draft invoices.
            DB::table('invoices_taxes')
                ->where('tax_id', $tax->id)
                ->whereIn('invoice_id', DB::table('invoices')->where('status', 0)->select('id'))
                ->delete();

            foreach (['credit_notes_taxes', 'add_ons_taxes', 'plans_taxes', 'charges_taxes', 'commitments_taxes', 'fixed_charges_taxes', 'rate_cards_taxes'] as $table) {
                DB::table($table)->where('tax_id', $tax->id)->delete();
            }

            $tax->deleted_at = now();
            $errors = $tax->validateAttributes();

            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            $tax->save();
        });

        Invoice::query()->whereIn('id', $draftInvoiceIds)->update(['ready_to_be_refreshed' => true]);

        return $result;
    }

    /**
     * Rails: `draft_invoice_ids` — the organization's draft invoices of the
     * tax's applicable customers.
     *
     * @return list<string>
     */
    protected function draftInvoiceIds(Tax $tax): array
    {
        return $tax->organization->invoices()
            ->whereIn('customer_id', $tax->applicableCustomers()->select('customers.id'))
            ->where('status', 0) // Rails: Invoice.draft (draft: 0)
            ->pluck('id')
            ->all();
    }
}
