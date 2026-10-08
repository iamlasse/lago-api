<?php

declare(strict_types=1);

namespace App\Services\BillingSegments;

use App\Models\Fee;
use LogicException;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Customer;
use App\Enums\InvoiceType;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\BillingSegment;
use Illuminate\Support\Facades\DB;
use App\Services\Customers\LockService;
use App\Services\Failures\LockAcquisitionFailure;
use App\Services\Invoices\ComputeAmountsFromFees;
use App\Services\Invoices\CreateGeneratingService;
use App\Services\BillingSegments\Fees\ComputeService;
use App\Services\Invoices\TransitionToFinalStatusService;

/**
 * Port of Rails' BillingSegments::ProcessService (app/services/
 * billing_segments/process_service.rb) — the consumer: turns a customer's
 * due billing segments into invoices, grouped by the invoice key, and moves
 * the spent segments on to `done`.
 */
class ProcessService extends BaseService
{
    public function __construct(
        private readonly Customer $customer,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoices');
        $result->invoices = [];

        // Rails: with_advisory_lock(..., timeout_seconds: 0) — try once, no
        // wait; a miss raises FailedToAcquireLock for the job to retry.
        try {
            LockService::call(
                customer: $this->customer,
                scope: 'billing_schedule',
                body: function () use ($result): void {
                    $segments = $this->pendingSegments();

                    $grouped = [];
                    foreach ($segments as $segment) {
                        $grouped[implode('|', $this->invoiceKey($segment))][] = $segment;
                    }

                    $invoices = [];
                    foreach ($grouped as $invoiceSegments) {
                        $invoices[] = $this->buildInvoice($invoiceSegments);
                    }

                    // Magic __set can't take `[]=` appends on the overloaded
                    // property — collect locally, assign once.
                    $result->invoices = $invoices;

                    $this->finalizeGeneratingInvoices();
                },
                timeoutSeconds: '0s',
            );
        } catch (LockAcquisitionFailure) {
            // Rails: raise BaseLockService::FailedToAcquireLock,
            //   "Failed to acquire billing segment lock for customer #{id}".
            throw new LockAcquisitionFailure(
                $result,
                'lock_acquisition_failed',
                'Failed to acquire billing segment lock for customer '.$this->customer->id,
            );
        }

        return $result;
    }

    /**
     * Rails: `#pending_segments` — `BillingSegment.awaiting_invoicing` for
     * this customer, eagerly loaded.
     *
     * @return list<BillingSegment>
     */
    private function pendingSegments(): array
    {
        return BillingSegment::query()
            ->awaitingInvoicing()
            ->where('billing_segments.customer_id', $this->customer->id)
            ->with([
                'pricingUnit',
                'rateOverride',
                'contract',
                'contractRateCard.rateCard.product',
                'rateCardRate.rateCard',
            ])
            ->get()
            ->all();
    }

    /**
     * Rails: `#invoice_key` — the fields one invoice is built from.
     *
     * @return list<string>
     */
    private function invoiceKey(BillingSegment $segment): array
    {
        $contract = $segment->contract;
        $billingAt = \Carbon\CarbonImmutable::parse($segment->billing_at)
            ->setTimezone($this->customer->applicableTimezone());

        return [
            $billingAt->toDateString(),
            $contract->consolidate_invoice ? 'shared' : $segment->id,
            (string) $segment->currency,
            (string) ($contract->billing_entity_id ?? $this->customer->billing_entity_id),
            implode('|', $this->paymentMethodKey($contract)),
            (string) $contract->purchase_order_number,
        ];
    }

    /**
     * Rails: `#payment_method_key`.
     *
     * @return list<?string>
     */
    private function paymentMethodKey($contract): array
    {
        if ($contract->payment_method_id !== null && $contract->payment_method_id !== '') {
            return [(string) $contract->payment_method_id,
                $contract->payment_method_type instanceof \App\Enums\ContractPaymentMethodType
                    ? $contract->payment_method_type->value
                    : (string) $contract->payment_method_type];
        }

        if ($contract->payment_method_type === 'manual') {
            return [null, 'manual'];
        }

        $defaultPaymentMethod = $this->customer->paymentMethods()
            ->where('is_default', true)
            ->first();

        if ($defaultPaymentMethod !== null) {
            return [$defaultPaymentMethod->id, 'provider'];
        }

        return [null, $contract->payment_method_type instanceof \App\Enums\ContractPaymentMethodType
                ? $contract->payment_method_type->value
                : (string) $contract->payment_method_type];
    }

    /**
     * Rails: `#build_invoice` — one transaction per invoice group: create the
     * generating invoice, attach the segment fees, roll the totals up, and
     * mark every served segment done. A failure on any segment rolls back the
     * whole group.
     *
     * @param  list<BillingSegment>  $segments
     */
    private function buildInvoice(array $segments): Invoice
    {
        $first = $segments[0];
        $contract = $first->contract;

        $meteredSegments = [];
        $fixedSegments = [];
        foreach ($segments as $segment) {
            $productType = $segment->contractRateCard->rateCard->product->product_type;

            if ($productType === Product::PRODUCT_TYPES['metered']) {
                $meteredSegments[] = $segment;
            } else {
                $fixedSegments[] = $segment;
            }
        }

        return DB::transaction(function () use ($segments, $contract, $fixedSegments, $meteredSegments): Invoice {
            $invoice = CreateGeneratingService::callBang(
                customer: $this->customer,
                billingEntity: $contract->billingEntity ?? $this->customer->billingEntity,
                invoiceType: InvoiceType::Subscription,
                invoicingReason: 'subscription_periodic',
                currency: $segments[0]->currency,
                datetime: $segments[0]->billing_at,
                purchaseOrderNumber: $contract->purchase_order_number,
            )->invoice;

            $this->attachFixedFees($fixedSegments, $invoice);

            // TODO(port): the metered half of the consumer —
            // Events::BillingPeriodFilterService.for_billing_segments! and
            // Fees::ChargeService with
            // MeteredItem.from_billing_segment (usage/records slice). A
            // metered-arrears segment reaching this point raises loudly and
            // rolls its invoice group back, matching Rails'
            // failure-rolls-the-group-back contract; today no other ported
            // code path writes such a segment.
            if ($meteredSegments !== []) {
                throw new LogicException(
                    'TODO(port): BillingSegments::ProcessService metered segments '
                    .'(Events::BillingPeriodFilterService + Fees::ChargeService billing-segment source).',
                );
            }

            $invoice->load('fees');

            ComputeAmountsFromFees::callBang(invoice: $invoice);
            $invoice->save();

            foreach ($segments as $segment) {
                $segment->status = 'done';
                $segment->invoice_id = $invoice->id;
                $segment->save();
            }

            return $invoice;
        });
    }

    /**
     * Rails: `#attach_fixed_fees`.
     *
     * @param  list<BillingSegment>  $segments
     */
    private function attachFixedFees(array $segments, Invoice $invoice): void
    {
        foreach ($segments as $segment) {
            [$fee, $trueUpFee] = $this->computeFixedFees($segment);

            // Rails: the parent fee is associated with the true-up as an
            // OBJECT, so the FK resolves at save time — the parent is saved
            // first, then the true-up points at its id.
            $fee->invoice_id = $invoice->id;
            $fee->billing_entity_id = $invoice->billing_entity_id;
            $fee->save();

            if ($trueUpFee !== null) {
                $trueUpFee->invoice_id = $invoice->id;
                $trueUpFee->billing_entity_id = $invoice->billing_entity_id;
                $trueUpFee->true_up_parent_fee_id = $fee->id;
                $trueUpFee->save();
            }
        }
    }

    /**
     * Rails: `#compute_fixed_fees` — the service-price fee plus its optional
     * true-up.
     *
     * @return array{0: Fee, 1: ?Fee}
     */
    private function computeFixedFees(BillingSegment $segment): array
    {
        $feeResult = ComputeService::callBang(billingSegment: $segment);

        return [$feeResult->fee, $feeResult->true_up_fee];
    }

    /**
     * Rails: `#finalize_generating_invoices` — re-queries done segments so a
     * retry finalizes existing invoices without rebuilding fees.
     */
    private function finalizeGeneratingInvoices(): void
    {
        $invoiceIds = BillingSegment::query()
            ->select('billing_segments.invoice_id')
            ->where('billing_segments.status', 'done')
            ->where('billing_segments.customer_id', $this->customer->id)
            ->join('invoices', 'invoices.id', '=', 'billing_segments.invoice_id')
            ->where('invoices.status', \App\Enums\InvoiceStatus::Generating->value)
            ->pluck('invoice_id');

        foreach (Invoice::query()->whereIn('id', $invoiceIds)->get() as $invoice) {
            TransitionToFinalStatusService::callBang(invoice: $invoice);

            if ($invoice->isDirty()) {
                $invoice->save();
            }
        }
    }
}
