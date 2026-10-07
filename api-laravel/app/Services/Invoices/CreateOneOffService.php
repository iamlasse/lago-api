<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use Illuminate\Support\Facades\DB;
use App\Enums\InvoicePaymentStatus;
use App\Jobs\Invoices\GenerateDocumentsJob;
use App\Services\Customers\UpdateCurrencyService;

/**
 * Port of Rails' Invoices::CreateOneOffService
 * (app/services/invoices/create_one_off_service.rb) — builds and finalizes
 * a one-off invoice from payload add-on fees.
 *
 * TODO(port) emission points left at their exact Rails positions:
 * SegmentTrack.invoice_created,
 * Integrations::Aggregator::Invoices::* jobs, Invoices::Payments::CreateService
 * (payments are a later milestone), activity_loggable
 * (invoice.one_off_created), invoice custom sections
 * (Invoices::ApplyInvoiceCustomSectionsService) and the provider-tax
 * deferral branch (totals never fail with UnknownTaxFailure while provider
 * taxation is unported). GenerateDocumentsJob (documents + email) is wired.
 */
class CreateOneOffService extends \App\Services\BaseService
{
    private ?string $currency = null;

    /** @var list<string>|null */
    private ?array $memoInvoiceCustomSectionIds = null;

    private Invoice $invoice;

    private ?object $billingEntity = null;

    private ?object $paymentMethod = null;

    public function __construct(
        private readonly ?Customer $customer,
        ?string $currency,
        private readonly array $fees,
        private readonly int $timestamp,
        private readonly bool $skipPsp = false,
        private readonly ?string $voidedInvoiceId = null,
        private readonly ?array $paymentMethodParams = null,
        private readonly array $invoiceCustomSection = [],
        private readonly ?string $billingEntityId = null,
        private readonly ?string $billingEntityCode = null,
        private readonly ?string $purchaseOrderNumber = null,
        private readonly bool $withDiscardedAddOns = false,
    ) {
        parent::__construct();

        $this->currency = $currency ?? $customer?->currency;
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice', 'payment_method');

        if ($this->customer === null) {
            return $result->notFoundFailure('customer');
        }

        if ($this->fees === []) {
            return $result->notFoundFailure('fees');
        }

        // Rails: CreateGeneratingService#save! runs the invoice presence
        // validation (currency value_is_mandatory) — validated before the
        // service call since the ported CreateGeneratingService types the
        // currency as a non-null string.
        if ($this->currency === null || $this->currency === '') {
            return $result->singleValidationFailure('value_is_mandatory', 'currency');
        }

        if (count($this->addOns()) !== count($this->addOnIdentifiers())) {
            return $result->notFoundFailure('add_on');
        }

        if (! $this->validPaymentMethod($result)) {
            return $result;
        }

        $this->resolveBillingEntity($result);

        if ($result->failure()) {
            return $result;
        }

        $taxDeferred = false;

        return $this->rescueFailures(function () use ($result, &$taxDeferred): BaseResult {
            DB::transaction(function () use ($result, &$taxDeferred): void {
                UpdateCurrencyService::call(
                    customer: $this->customer,
                    currency: $this->currency,
                )->raiseIfError();

                $this->createGeneratingInvoice();

                $result->invoice = $this->invoice;

                $this->createOneOffFees();

                $this->invoice->fees_amount_cents = (int) $this->invoice->fees()->sum('amount_cents');
                $this->invoice->sub_total_excluding_taxes_amount_cents = $this->invoice->fees_amount_cents;

                $this->invoice->payment_method_id = $this->paymentMethod?->id ?? null;
                $this->invoice->skip_automatic_payment = $this->skipPsp;

                // NOTE: Custom sections are applied before computing taxes so
                // they are persisted even when tax computation is deferred to a
                // tax provider.
                if (! $this->skipCustomSections()) {
                    ApplyInvoiceCustomSectionsService::call(
                        invoice: $this->invoice,
                        customSectionIds: $this->invoiceCustomSectionIds(),
                    );
                }

                $totalsResult = ComputeTaxesAndTotalsService::call(invoice: $this->invoice);

                // TODO(port): provider taxation — Rails defers finalization when
                // the totals fail with BaseService::UnknownTaxFailure; the local
                // taxes pipeline never raises that failure.
                if ($totalsResult->failure()
                    && $totalsResult->getError() instanceof \App\Services\Failures\UnknownTaxFailure) {
                    $taxDeferred = true;

                    return;
                }

                $totalsResult->raiseIfError();

                $this->invoice->payment_status = $this->invoice->total_amount_cents > 0
                    ? InvoicePaymentStatus::Pending
                    : InvoicePaymentStatus::Succeeded;

                TransitionToFinalStatusService::call(invoice: $this->invoice);

                if ($this->voidedInvoiceId !== null) {
                    $this->invoice->voided_invoice_id = $this->voidedInvoiceId;
                }

                $this->invoice->save();
            });

            if ($taxDeferred) {
                return $result;
            }

            if (! $this->invoice->isClosed()) {
                // TODO(port): Utils::SegmentTrack.invoice_created after commit.
                SendWebhookJob::performLater('invoice.one_off_created', $this->invoice);
                // Rails: GenerateDocumentsJob.perform_after_commit(invoice:,
                // notify: should_deliver_email?) — this code runs after the
                // transaction block closes (the Laravel equivalent of the
                // after-commit position).
                dispatch(new GenerateDocumentsJob($this->invoice, $this->shouldDeliverEmail()));
                // Rails: Integrations::Aggregator::Invoices::CreateJob.
                // perform_after_commit(invoice:) if invoice.should_sync_invoice?
                // (the Hubspot leg is the Hubspot slice's).
                \App\Jobs\Integrations\Aggregator\Invoices\CreateJob::dispatchIfShouldSync($this->invoice);
                if (! $this->invoice->skip_automatic_payment) {
                    // Rails: Invoices::Payments::CreateService.call_async.
                    (new Payments\CreateService(invoice: $this->invoice))->callAsync();
                }
            }

            $result->invoice = $this->invoice;

            return $result;
        }, $result);
    }

    private function createGeneratingInvoice(): void
    {
        $invoiceResult = CreateGeneratingService::callBang(
            customer: $this->customer,
            invoiceType: \App\Enums\InvoiceType::OneOff,
            currency: $this->currency,
            datetime: \Illuminate\Support\Facades\Date::createFromTimestamp($this->timestamp, 'UTC'),
            billingEntity: $this->billingEntity,
            purchaseOrderNumber: $this->purchaseOrderNumber !== null
                ? mb_trim($this->purchaseOrderNumber)
                : null,
        );

        $this->invoice = $invoiceResult->invoice;

        // Rails: invoice.save! runs the presence validations (issuing_date,
        // currency) and raises RecordInvalid -> record_validation_failure.
        $errors = $this->invoice->validateAttributes();

        if ($errors !== []) {
            BaseResult::of('invoice')->recordValidationFailure($errors)->raiseIfError();
        }
    }

    private function createOneOffFees(): void
    {
        \App\Services\Fees\OneOffService::callBang(
            invoice: $this->invoice,
            fees: $this->fees,
            withDiscardedAddOns: $this->withDiscardedAddOns,
        );
    }

    private function resolveBillingEntity(BaseResult $result): void
    {
        $organization = $this->customer->organization;

        if ($this->billingEntityId !== null) {
            $this->billingEntity = $organization->billingEntities()
                ->where('id', $this->billingEntityId)
                ->first();

            if ($this->billingEntity === null) {
                $result->notFoundFailure('billing_entity');
            }
        } elseif ($this->billingEntityCode !== null) {
            $this->billingEntity = $organization->billingEntities()
                ->where('code', $this->billingEntityCode)
                ->first();

            if ($this->billingEntity === null) {
                $result->notFoundFailure('billing_entity');
            }
        } else {
            $this->billingEntity = $this->customer->billingEntity;
        }
    }

    /** Rails: add_ons — the scope match count guard against the payload. */
    private function addOns(): \Illuminate\Database\Eloquent\Collection
    {
        $identifier = $this->apiContext() ? 'code' : 'id';

        $scope = $this->withDiscardedAddOns
            ? $this->customer->organization->addOns()->withTrashed()
            : $this->customer->organization->addOns();

        return $scope->whereIn($identifier, $this->addOnIdentifiers())->get();
    }

    /** @return list<string> */
    private function addOnIdentifiers(): array
    {
        $identifier = $this->apiContext() ? 'add_on_code' : 'add_on_id';

        $identifiers = [];
        foreach ($this->fees as $fee) {
            if (isset($fee[$identifier]) && $fee[$identifier] !== null) {
                $identifiers[] = (string) $fee[$identifier];
            }
        }

        return array_values(array_unique($identifiers));
    }

    /**
     * Port of PaymentMethods::ValidateService#valid? (with the PaymentMethod
     * lookups stubbed — the payment methods milestone is not ported, so the
     * looked-up `payment_method` is always nil, like a customer without one).
     *
     * TODO(port): PaymentMethods::ValidateService full behavior once
     * payment methods land.
     */
    private function validPaymentMethod(BaseResult $result): bool
    {
        $result->payment_method = $this->paymentMethod();

        $params = $this->paymentMethodParams;

        if ($params === null || $params === []) {
            return true;
        }

        $type = $params['payment_method_type'] ?? null;
        $id = $params['payment_method_id'] ?? null;

        if (($type === null || $type === '') && ($id === null || $id === '')) {
            return true;
        }

        // TODO(port): payment_method lookup on the customer (always nil).
        $paymentMethod = null;

        if ($id === null && (string) $type === 'provider') {
            return true;
        }

        if ($paymentMethod !== null && (string) $type === 'provider') {
            return true;
        }

        if ($paymentMethod === null && (string) $type === 'manual') {
            return true;
        }

        $result->singleValidationFailure('invalid_payment_method', 'payment_method');

        return false;
    }

    /** Rails: payment_method — the customer's payment method from the payload id. */
    private function paymentMethod(): ?object
    {
        if ($this->paymentMethod !== null) {
            return $this->paymentMethod;
        }

        $params = $this->paymentMethodParams;

        if ($params === null || ($params['payment_method_id'] ?? null) === null) {
            return null;
        }

        // TODO(port): customer.payment_methods.find_by(id:) — payment
        // methods are a later milestone.
        return $this->paymentMethod = null;
    }

    /**
     * Rails: should_deliver_email? — License.premium? && the billing
     * entity's email settings include "invoice.finalized".
     */
    private function shouldDeliverEmail(): bool
    {
        return \App\Support\License::premium()
            && in_array(
                'invoice.finalized',
                (array) ($this->billingEntity?->email_settings ?? []),
                true,
            );
    }

    /**
     * Rails: invoice_custom_section_ids — the explicit section selection from
     * the `invoice_custom_section` param block, resolved against the
     * organization (codes in API context, ids in GraphQL context).
     *
     * @return list<string>
     */
    private function invoiceCustomSectionIds(): array
    {
        if (isset($this->memoInvoiceCustomSectionIds)) {
            return $this->memoInvoiceCustomSectionIds;
        }

        $identifiers = $this->sectionIdentifiers();

        if ($identifiers === null || $identifiers === []) {
            return $this->memoInvoiceCustomSectionIds = [];
        }

        $identifier = $this->apiContext() ? 'code' : 'id';

        return $this->memoInvoiceCustomSectionIds = $this->customer->organization
            ->invoiceCustomSections()
            ->whereIn($identifier, $identifiers)
            ->pluck('id')
            ->all();
    }

    /**
     * Rails: section_identifiers.
     *
     * @return list<string>|null
     */
    private function sectionIdentifiers(): ?array
    {
        if ($this->invoiceCustomSection === []) {
            return null;
        }

        $key = $this->apiContext()
            ? 'invoice_custom_section_codes'
            : 'invoice_custom_section_ids';

        $value = $this->invoiceCustomSection[$key] ?? null;

        if ($value === null) {
            return null;
        }

        return array_values(array_unique(array_map(strval(...), array_filter((array) $value))));
    }

    /**
     * Rails: skip_custom_sections?.
     */
    private function skipCustomSections(): bool
    {
        if ($this->invoiceCustomSection === []) {
            return false;
        }

        if (! array_key_exists('skip_invoice_custom_sections', $this->invoiceCustomSection)) {
            return false;
        }

        return (bool) $this->invoiceCustomSection['skip_invoice_custom_sections'];
    }
}
