<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use Illuminate\Support\Str;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Fees\ApplyTaxesService as FeeApplyTaxesService;

/**
 * Port of Rails' Invoices::BuildRegenerationPreviewService
 * (app/services/invoices/build_regeneration_preview_service.rb) — duplicates
 * the invoice's fees (with freshly applied taxes) onto an in-memory invoice
 * copy and computes its totals, so the regeneration preview answers with the
 * WOULD-BE invoice without persisting anything.
 *
 * NOTE: Provider taxes don't apply in this service — the external provider
 * tax call would make the preview a bad user experience.
 */
class BuildRegenerationPreviewService extends BaseService
{
    public function __construct(
        private readonly Invoice $invoice,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('invoice');

        $previewInvoice = $this->invoice->replicate();

        $dupFees = collect();

        foreach ($this->invoice->fees as $fee) {
            $dupFee = $fee->replicate();
            $dupFees->add($dupFee);

            FeeApplyTaxesService::callBang(fee: $dupFee);

            $dupFee->id = $fee->id;
            foreach ($dupFee->appliedTaxes as $appliedTax) {
                $appliedTax->fee_id = $fee->id;
                $appliedTax->id = Str::uuid()->toString();
            }
        }

        $previewInvoice->setRelation('fees', $dupFees);

        $result = ComputeAmountsFromFees::call(
            invoice: $previewInvoice,
            provider_taxes: null,
        );
        $result->raiseIfError();

        $previewInvoice = $result->invoice;
        $previewInvoice->id = $this->invoice->id;

        $previewInvoice->appliedTaxes->each(function ($appliedTax): void {
            $appliedTax->invoice_id = $this->invoice->id;
            $appliedTax->id = Str::uuid()->toString();
        });

        $result->invoice = $previewInvoice;

        return $result;
    }
}
