<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Fee;
use App\Models\Invoice;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Enums\SubscriptionInvoicingReason;
use App\Services\Invoices\ApplyInvoiceCustomSectionsService;
use App\Services\Failures\UnknownTaxFailure;
use App\Services\LifetimeUsages\FlagRefreshFromInvoiceService;

/**
 * Port of Rails' Invoices::RefreshDraftService
 * (app/services/invoices/refresh_draft_service.rb) — regenerates a
 * subscription draft invoice's fees, invoice_subscriptions and totals.
 *
 * TODO(port) emission points left at their exact Rails positions: the
 * credit-note refresh (CreditNotes::RefreshDraftService — credit notes are
 * unported), the lifetime-usage / wallet refresh flags, Hubspot update and
 * error_details.discard_all. The applied custom sections are WIRED
 * (invoice-custom-sections slice).
 */
class RefreshDraftService extends BaseService
{
    private Collection $invoiceSubscriptions;

    /** @var list<string> */
    private array $subscriptionIds;

    private bool $recurring;

    private string $invoicingReason;

    public function __construct(
        private readonly Invoice $invoice,
        private readonly string $context = 'refresh',
    ) {
        parent::__construct();

        $this->subscriptionIds = $invoice->subscriptions()->pluck('subscriptions.id')->all();
        $this->invoiceSubscriptions = $invoice->invoiceSubscriptions()->get();

        // NOTE: Recurring status (meaning billed automatically from the
        // recurring billing process) should be kept to prevent double billing
        // on billing day.
        $this->recurring = (bool) ($this->invoiceSubscriptions->first()?->recurring ?? false);

        // NOTE: upgrading is used as a not persisted reason as it means
        // one subscription starting and a second one terminating (it is not
        // a SubscriptionInvoicingReason enum value — Rails passes :upgrading
        // straight through).
        $this->invoicingReason = $this->recurring
            ? SubscriptionInvoicingReason::SubscriptionPeriodic->value
            : ($this->invoiceSubscriptions->count() === 1
                ? ($this->invoiceSubscriptions->first()?->invoicingReasonName() ?? 'upgrading')
                : 'upgrading');
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice');

        if (! $this->invoice->isSubscription()) {
            return $result->forbiddenFailure();
        }

        $result->invoice = $this->invoice;

        if (! $this->invoice->isDraft()) {
            return $result;
        }

        return $this->rescueFailures(function () use ($result): BaseResult {
            DB::transaction(function (): void {
                if ($this->invoice->ready_to_be_refreshed) {
                    $this->invoice->ready_to_be_refreshed = false;
                    $this->invoice->save();
                }

                $oldTotalAmountCents = (int) $this->invoice->total_amount_cents;

                // TODO(port): old credit-note item fee values + the
                // credit-note/subscription mapping (credit notes unported).

                $timestamp = $this->fetchTimestamp();

                $this->resetInvoiceValues();

                CreateInvoiceSubscriptionService::call(
                    invoice: $this->invoice,
                    subscriptions: \App\Models\Subscription::query()->whereIn('id', $this->subscriptionIds)->get(),
                    timestamp: $timestamp,
                    invoicingReason: $this->invoicingReason,
                    refresh: true,
                )->raiseIfError();

                $calculateResult = CalculateFeesService::call(
                    invoice: $this->invoice->refresh(),
                    recurring: $this->recurring,
                    context: $this->context,
                );

                ApplyInvoiceCustomSectionsService::call(invoice: $this->invoice);

                // TODO(port): refresh each credit note
                // (CreditNotes::RefreshDraftService).

                $error = $calculateResult->getError();

                if ($error === null || ! $error instanceof UnknownTaxFailure) {
                    $calculateResult->raiseIfError();
                }

                if ($oldTotalAmountCents !== (int) $this->invoice->total_amount_cents) {
                    // FlagRefreshFromInvoiceService — WIRED (usage-monitoring
                    // slice). TODO(port): customer.flag_wallets_for_refresh.
                    FlagRefreshFromInvoiceService::callBang(invoice: $this->invoice);
                }

                // NOTE: In case of a refresh the same day of the termination.
                Fee::query()
                    ->where('invoice_id', $this->invoice->id)
                    ->update(['created_at' => $this->invoice->created_at]);

                // Rails runs CalculateFeesService on invoice.reload — the SAME
                // model object — so the response serializes fees freshly, with
                // the stamped created_at. The port refreshed a COPY for the
                // fee engine; reload here so the cached fees relation (and the
                // serialized response) sees the update.
                $this->invoice->refresh();

                $error = $calculateResult->getError();

                if ($error !== null && $error instanceof UnknownTaxFailure) {
                    return;
                }

                $calculateResult->raiseIfError();

                // TODO(port): Integrations::Aggregator::Invoices::Hubspot::UpdateJob
                // when invoice.should_update_hubspot_invoice?.
            });

            return $result;
        }, $result);
    }

    /**
     * Rails: fetch_timestamp — the invoice_subscription timestamp, else the
     * invoice created_at (+1s for to_i rounding), else the first fee's
     * properties timestamp.
     */
    private function fetchTimestamp(): int
    {
        $timestamp = $this->invoiceSubscriptions->first()?->timestamp;

        if ($timestamp !== null) {
            return \Illuminate\Support\Facades\Date::parse($timestamp)->getTimestamp();
        }

        $fee = $this->invoice->fees()->first();
        $feeTimestamp = $fee?->properties['timestamp'] ?? null;

        if ($feeTimestamp !== null) {
            return \Illuminate\Support\Facades\Date::parse($feeTimestamp)->getTimestamp();
        }

        return (int) \Illuminate\Support\Facades\Date::parse($this->invoice->created_at)->addSecond()->getTimestamp();
    }

    private function resetInvoiceValues(): void
    {
        // TODO(port): invoice.credit_notes.each { |cn| cn.items.update_all(fee_id: nil) }
        // (credit notes unported).

        Fee::query()->where('invoice_id', $this->invoice->id)->delete();
        $this->invoice->invoiceSubscriptions()->delete();
        $this->invoice->appliedTaxes()->delete();

        // TODO(port): invoice.error_details.discard_all (error details
        // unported).
        $this->invoice->appliedInvoiceCustomSections()->delete();
        // TODO(port): invoice.credits.progressive_billing_invoice_kind
        // .destroy_all (progressive billing credits unported).

        $this->invoice->taxes_amount_cents = 0;
        $this->invoice->total_amount_cents = 0;
        $this->invoice->taxes_rate = 0;
        $this->invoice->fees_amount_cents = 0;
        $this->invoice->sub_total_excluding_taxes_amount_cents = 0;
        $this->invoice->sub_total_including_taxes_amount_cents = 0;
        $this->invoice->progressive_billing_credit_amount_cents = 0;

        $this->invoice->save();
    }
}
