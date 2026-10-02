<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use Closure;
use DateTimeInterface;
use App\Models\Invoice;
use App\Models\Customer;
use App\Enums\InvoiceType;
use App\Enums\InvoiceStatus;
use App\Services\BaseResult;
use InvalidArgumentException;
use App\Support\Utils\Datetime;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Invoices::CreateGeneratingService
 * (app/services/invoices/create_generating_service.rb) — creates the
 * `generating` invoice row; issuing / payment dates derive from the
 * customer's timezone and net payment term.
 *
 * TODO(port): partner-account revenue-share gating needs the Organization
 * revenue_share_enabled flag once ported (Rails: forbidden_failure when
 * customer.partner_account? && !organization.revenue_share_enabled?).
 */
class CreateGeneratingService extends \App\Services\BaseService
{
    private ?Closure $invoiceBlock = null;

    public function __construct(
        private readonly Customer $customer,
        private readonly InvoiceType|int|string $invoiceType,
        private readonly DateTimeInterface|string $datetime,
        private readonly string $currency,
        private readonly bool $chargeInAdvance = false,
        private readonly bool $skipCharges = false,
        private readonly ?string $invoiceId = null,
        private readonly ?string $invoicingReason = null,
        private readonly bool $subscriptionGated = false,
        private readonly ?\App\Models\BillingEntity $billingEntity = null,
        private readonly ?string $purchaseOrderNumber = null,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice');

        $invoice = DB::transaction(function () use ($result): Invoice {
            $invoice = Invoice::query()->create([
                'id' => $this->invoiceId,
                'organization_id' => $this->customer->organization_id,
                'billing_entity_id' => ($this->billingEntity ?? $this->customer->billingEntity)->id,
                'customer_id' => $this->customer->id,
                'invoice_type' => $this->resolveInvoiceType(),
                'currency' => $this->currency,
                'timezone' => $this->customer->applicableTimezone(),
                'status' => InvoiceStatus::Generating,
                'issuing_date' => $this->issuingDate(),
                'expected_finalization_date' => $this->expectedFinalizationDate(),
                'payment_due_date' => $this->paymentDueDate(),
                'net_payment_term' => $this->customer->applicableNetPaymentTerm(),
                'skip_charges' => $this->skipCharges,
                'self_billed' => $this->customer->partnerAccount(),
                'purchase_order_number' => $this->purchaseOrderNumber,
            ]);

            $result->invoice = $invoice;

            $invoice->refreshSearchTerms();

            if ($this->invoiceBlock !== null) {
                ($this->invoiceBlock)($invoice);
            }

            return $invoice;
        });

        $result->invoice = $invoice;

        return $result;
    }

    /** Optional block handed to the service (Rails yields the invoice inside the transaction). */
    public function withInvoice(Closure $block): self
    {
        $this->invoiceBlock = $block;

        return $this;
    }

    /**
     * NOTE: accounting date must be in the customer timezone.
     */
    private function issuingDate(): string
    {
        $date = Datetime::parseIso8601($this->datetime)
            ->setTimezone($this->customer->applicableTimezone())
            ->startOfDay();

        if (! $this->gracePeriod() || $this->chargeInAdvance) {
            return $date->toDateString();
        }

        return $date->addDays($this->issuingDateAdjustment())->toDateString();
    }

    private function expectedFinalizationDate(): string
    {
        $date = Datetime::parseIso8601($this->datetime)
            ->setTimezone($this->customer->applicableTimezone())
            ->startOfDay();

        if (! $this->gracePeriod() || $this->chargeInAdvance) {
            return $date->toDateString();
        }

        return $date->addDays($this->customer->applicableInvoiceGracePeriod())->toDateString();
    }

    private function gracePeriod(): bool
    {
        if ($this->subscriptionGated) {
            return false;
        }

        return $this->resolveInvoiceType() === InvoiceType::Subscription;
    }

    private function paymentDueDate(): string
    {
        return Datetime::parseIso8601($this->issuingDate())
            ->addDays((int) $this->customer->applicableNetPaymentTerm())
            ->toDateString();
    }

    private function issuingDateAdjustment(): int
    {
        return (new IssuingDateService(
            customerSettings: $this->customer,
            recurring: $this->invoicingReason === 'subscription_periodic',
        ))->issuingDateAdjustment();
    }

    private function resolveInvoiceType(): InvoiceType
    {
        if ($this->invoiceType instanceof InvoiceType) {
            return $this->invoiceType;
        }

        if (is_int($this->invoiceType)) {
            return InvoiceType::from($this->invoiceType);
        }

        return InvoiceType::fromOption($this->invoiceType)
            ?? throw new InvalidArgumentException("Unknown invoice type {$this->invoiceType}");
    }
}
