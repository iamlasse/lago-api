<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Enums\InvoiceType;
use Carbon\CarbonImmutable;
use App\Enums\InvoiceStatus;
use App\Models\Subscription;
use App\Services\BaseResult;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\UnknownTaxFailure;

/**
 * Port of Rails' Invoices::SubscriptionService
 * (app/services/invoices/subscription_service.rb) — orchestrates the whole
 * subscription invoicing pipeline: generating invoice + boundary rows →
 * fees → coupons/credits → totals → final status.
 *
 * TODO(port) hooks (not in M1 scope):
 * - Invoices::ApplyInvoiceCustomSectionsService
 * - subscription activation rules (payment gating) — Subscriptions slice
 * - LifetimeUsages::FlagRefreshFromInvoiceService, wallet refresh flags
 * - webhooks (invoice.created / invoice.drafted / fee.created) and
 *   GenerateDocumentsJob, integrations syncs, payments, Segment tracking —
 *   they plug in at the marked emission points.
 * - DailyUsages::FillFromInvoiceJob (revenue analytics).
 */
class SubscriptionService extends \App\Services\BaseService
{
    public const ACTIVATION_BILLING_REASONS = ['subscription_starting', 'upgrading'];

    public function __construct(
        private array $subscriptions,
        private readonly int $timestamp,
        private readonly string $invoicingReason,
        private readonly ?Invoice $invoice = null,
        private readonly bool $skipCharges = false,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice', 'non_invoiceable_fees');
        $recurring = $this->invoicingReason === 'subscription_periodic';

        if ($this->activationBilling()) {
            // Activation billing runs inside the subscription locks' transaction.
            return $this->rescueFailures(function () use ($result, $recurring) {
                return DB::transaction(function () use ($result, $recurring) {
                    return $this->lockSubscriptions($result, $recurring);
                });
            }, $result);
        }

        return $this->rescueFailures(fn () => $this->performCall($result, $recurring), $result);
    }

    private function performCall(BaseResult $result, bool $recurring): BaseResult
    {
        if ($this->activeSubscriptions() === [] && $recurring) {
            return $result;
        }

        if ($this->mixedBillingEntities()) {
            return $result->validationFailure(['billing_entity' => ['mixed_billing_entities']]);
        }

        if ($this->mixedPurchaseOrderNumbers()) {
            return $result->validationFailure(['purchase_order_number' => ['mixed_purchase_order_numbers']]);
        }

        $invoice = $this->invoice ?? $this->createGeneratingInvoice();

        if ($this->subscriptionGated()) {
            $invoice->status = InvoiceStatus::Open;
        }

        $result->invoice = $invoice;

        // Activation billing runs inside the lock's transaction, so this
        // needs its own savepoint to keep rolling partial fees back on failure.
        $feeResult = DB::transaction(function () use ($invoice) {
            $context = $this->gracePeriod($invoice) ? 'draft' : 'finalize';

            $feeResult = CalculateFeesService::call(
                invoice: $invoice,
                recurring: $this->invoicingReason === 'subscription_periodic',
                context: $context,
            );

            // TODO(port): Invoices::ApplyInvoiceCustomSectionsService.

            $this->setInvoiceGeneratedStatus($invoice, $feeResult);

            $invoice->save();

            // NOTE: We don't want to raise an error and corrupt the DB commit
            // on a tax error — in that case the fees stay attached to the
            // invoice; a retry action lets users finalize the invoice.
            if (! $this->taxError($feeResult)) {
                $feeResult->raiseIfError();
            }

            $invoice->refresh();

            // TODO(port): LifetimeUsages::FlagRefreshFromInvoiceService;
            // customer.flag_wallets_for_refresh when in grace period.

            return $feeResult;
        });

        $result->non_invoiceable_fees = $feeResult->non_invoiceable_fees;

        // TODO(port): SendWebhookJob "fee.created" for each non-invoiceable fee.
        // TODO(port): DailyUsages::FillFromInvoiceJob (revenue analytics).

        if ($this->taxError($feeResult)) {
            if ($this->gracePeriod($invoice)) {
                // TODO(port): SendWebhookJob "invoice.drafted" + activity log
                // + notify_ready_to_finalize when not tax pending.
            }

            return $result;
        }

        if ($this->subscriptionGated()) {
            // Rails: Invoices::Payments::CreateService.call_async — payment
            // attempts stay gated until the subscription activates.
            (new Payments\CreateService(invoice: $invoice))->callAsync();
        } elseif ($this->gracePeriod($invoice)) {
            // TODO(port): SendWebhookJob "invoice.drafted" + activity log
            // + notify_ready_to_finalize when not tax pending.
        } elseif (! $invoice->isClosed()) {
            // We don't need to send the webhooks if the invoice was closed
            // (skip 0 invoice setting).
            // TODO(port): SendWebhookJob "invoice.created" + activity log +
            // GenerateDocumentsJob + integrations syncs + Segment.
            (new Payments\CreateService(invoice: $invoice))->callAsync();
        }

        return $result;
    }

    // TODO(integration): verify signature against ported DatesService / services
    private function lockSubscriptions(BaseResult $result, bool $recurring): BaseResult
    {
        if ($this->subscriptions === []) {
            return $this->performCall($result, $recurring);
        }

        $ids = array_map(fn (Subscription $s) => $s->id, $this->subscriptions);

        $locked = Subscription::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $this->subscriptions = array_map(
            fn (Subscription $s) => $locked[$s->id] ?? $s,
            $this->subscriptions,
        );

        return $this->performCall($result, $recurring);
    }

    private function activationBilling(): bool
    {
        return $this->skipCharges && in_array($this->invoicingReason, self::ACTIVATION_BILLING_REASONS, true);
    }

    /** @return list<Subscription> */
    private function activeSubscriptions(): array
    {
        return array_values(array_filter(
            $this->subscriptions,
            fn (Subscription $subscription) => $subscription->active(),
        ));
    }

    private function subscriptionGated(): bool
    {
        return collect($this->subscriptions)->contains(fn (Subscription $s) => $s->gated());
    }

    private function mixedBillingEntities(): bool
    {
        $entities = array_map(
            fn (Subscription $s) => $s->billing_entity_id ?? $s->customer?->billing_entity_id,
            $this->subscriptions,
        );

        return count(array_unique($entities)) > 1;
    }

    private function mixedPurchaseOrderNumbers(): bool
    {
        $numbers = array_map(fn (Subscription $s) => $s->purchase_order_number, $this->subscriptions);

        return count(array_unique($numbers)) > 1;
    }

    private function createGeneratingInvoice(): Invoice
    {
        $first = $this->subscriptions[0] ?? null;
        $customer = $first?->customer;

        // BUGFIX(port): the invoice block belongs to the SERVICE (Rails yields
        // it inside the creating transaction) — the old code chained
        // ->withInvoice() onto the BaseResult, which has no such method and
        // crashed every BillSubscriptionJob run. Build the instance so the
        // block runs inside CreateGeneratingService's transaction, like
        // Rails' `CreateGeneratingService.call(...) { |invoice| ... }`.
        $invoiceResult = (new CreateGeneratingService(
            customer: $customer,
            billingEntity: \App\Models\BillingEntity::query()->find(
                $first->billing_entity_id ?? $customer?->billing_entity_id,
            ),
            invoiceType: InvoiceType::Subscription,
            invoicingReason: $this->invoicingReason,
            currency: $first?->plan?->amount_currency,
            datetime: CarbonImmutable::createFromTimestampUTC($this->timestamp),
            skipCharges: $this->skipCharges,
            purchaseOrderNumber: $first?->purchase_order_number,
        ))->withInvoice(function (Invoice $invoice): void {
            CreateInvoiceSubscriptionService::call(
                invoice: $invoice,
                subscriptions: $this->subscriptions,
                timestamp: $this->timestamp,
                invoicingReason: $this->invoicingReason,
            )->raiseIfError();
        })->execute();

        $invoiceResult->raiseIfError();

        return $invoiceResult->invoice;
    }

    private function gracePeriod(Invoice $invoice): bool
    {
        if ($this->subscriptionGated()) {
            return false;
        }

        return $invoice->customer->applicableInvoiceGracePeriod() > 0;
    }

    private function setInvoiceGeneratedStatus(Invoice $invoice, BaseResult $feeResult): void
    {
        if ($this->gracePeriod($invoice)) {
            $invoice->status = InvoiceStatus::Draft;

            return;
        }

        TransitionToFinalStatusService::call(invoice: $invoice);
    }

    private function taxError(BaseResult $feeResult): bool
    {
        if ($feeResult->success()) {
            return false;
        }

        return $feeResult->getError() instanceof UnknownTaxFailure;
    }
}
