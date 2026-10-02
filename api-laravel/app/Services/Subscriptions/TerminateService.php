<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use Carbon\CarbonImmutable;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Subscriptions::TerminateService
 * (app/services/subscriptions/terminate_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Subscriptions::ActivationRules::CancelService (cancel of an
 *   incomplete subscription) — the incomplete subscription is canceled
 *   outright meanwhile.
 * - TODO(port): CreditNotes::CreateFromTermination — credit notes are part of
 *   the invoice pipeline (M1 task 9); the unconsumed-subscription credit note
 *   branch is marked at its hook below.
 * - TODO(port): BillSubscriptionJob / BillNonInvoiceableFeesJob — the billing
 *   side (M1 task 9); hooks are marked inline.
 * - TODO(port): SendWebhookJob emissions + ActivityLog + Hubspot sync.
 */
class TerminateService extends BaseService
{
    protected ?Subscription $subscription;

    protected bool $async;

    protected bool $upgrade;

    protected string $onTerminationCreditNote;

    protected string $onTerminationInvoice;

    public function __construct(
        ?Subscription $subscription = null,
        bool $async = true,
        bool $upgrade = false,
        ?string $onTerminationCreditNote = null,
        ?string $onTerminationInvoice = null,
    ) {
        parent::__construct();

        $this->subscription = $subscription;
        $this->async = $async;
        $this->upgrade = $upgrade;
        $this->onTerminationCreditNote = blank($onTerminationCreditNote)
            ? ($subscription?->on_termination_credit_note ?? 'credit')
            : $onTerminationCreditNote;
        $this->onTerminationInvoice = blank($onTerminationInvoice)
            ? ($subscription?->on_termination_invoice ?? 'generate')
            : $onTerminationInvoice;
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('subscription');

        if ($this->subscription === null) {
            return $result->notFoundFailure('subscription');
        }

        $cancelIncompleteSubscription = false;
        $subscriptionChanged = false;

        DB::transaction(function () use ($result, &$cancelIncompleteSubscription, &$subscriptionChanged): void {
            // Rails: subscription.with_lock — SELECT ... FOR UPDATE on the row.
            $subscription = Subscription::query()
                ->whereKey($this->subscription->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->subscription = $subscription;

            if ($subscription->incomplete()) {
                $this->cancelIncomplete($result);
                $cancelIncompleteSubscription = true;
            } elseif ($subscription->canceled()) {
                $result->singleValidationFailure('subscription_canceled');
            } elseif (! $this->upgrade && $subscription->nextSubscription()?->incomplete()) {
                $result->singleValidationFailure('next_subscription_incomplete');
            } elseif ($subscription->pending()) {
                $previous = $subscription->previousSubscription;
                $subscription->markAsCanceled();
                $subscription->save();

                // TODO(port): SendWebhookJob "subscription.updated" on previous
                // subscription + ActivityLog.

                $subscriptionChanged = true;
            } elseif (! $subscription->terminated()) {
                $subscription->markAsTerminated();
                $subscription->save();

                $this->updateOnTerminationActions();

                // TODO(port): Integrations::Aggregator::Subscriptions::Hubspot::UpdateJob.

                if ($this->generateCreditNoteForUnconsumedSubscription()) {
                    // NOTE: As subscription was paid in advance and terminated
                    // before the end of the period, we have to create a credit
                    // note for the days that were not consumed.
                    //
                    // TODO(port): blocked_by_pending_taxes? →
                    //   not_allowed_failure!("cannot_terminate_with_pending_taxes")
                    // TODO(port): CreditNotes::CreateFromTermination.call!(
                    //   subscription:, reason: "order_cancellation",
                    //   upgrade:, on_termination: on_termination_credit_note)
                    //   — credit notes are part of the invoice pipeline
                    //   (M1 task 9).
                }

                // NOTE: We should bill subscription and generate invoice for all
                // cases except for the upgrade. For upgrade we will create only
                // one invoice for termination charges and for in advance charges.
                // It is handled in subscriptions/create_service.rb.
                if (! $this->upgrade) {
                    // TODO(port): bill_subscription — BillSubscriptionJob (when
                    // on_termination_invoice == :generate, invoicing_reason:
                    // :subscription_terminating, at terminated_at) +
                    // BillNonInvoiceableFeesJob (always, at terminated_at).
                }

                $subscriptionChanged = true;
            }

            if ($subscriptionChanged) {
                $this->cancelNextSubscription();
            }
        });

        if ($result->failure() || $cancelIncompleteSubscription) {
            return $result;
        }

        if ($subscriptionChanged) {
            // TODO(port): SendWebhookJob "subscription.terminated" + ActivityLog.
        }

        $result->subscription = $this->subscription;

        return $result;
    }

    /**
     * NOTE: Called to terminate a downgraded subscription — activates the
     * pending next subscription (chained from PlanDowngradeService).
     */
    public function terminateAndStartNext(int $timestamp): BaseResult
    {
        $result = static::makeResult('subscription');

        $nextSubscription = $this->subscription?->nextSubscription();

        if ($nextSubscription === null || ! $nextSubscription->pending()) {
            return $result;
        }

        $activationResult = ActivateService::call(
            subscription: $nextSubscription,
            timestamp: CarbonImmutable::createFromTimestampUTC($timestamp),
        )->raiseIfError();

        $result->subscription = $activationResult->subscription;

        return $result;
    }

    // -- Branches --------------------------------------------------------------------

    protected function cancelIncomplete(BaseResult $result): void
    {
        // TODO(port): Subscriptions::ActivationRules::CancelService.call!(
        //   subscription:, rule_status: :declined, cancellation_reason: :manual)
        // — activation rules and gating invoices are not ported; the
        // subscription is canceled directly meanwhile.
        $this->subscription->markAsCanceled();
        $this->subscription->cancellation_reason = 'manual';
        $this->subscription->save();

        $result->subscription = $this->subscription;
    }

    protected function cancelNextSubscription(): void
    {
        // NOTE: Upgrade path: next_subscription is the new subscription we just
        // persisted, not a stale scheduled change
        if ($this->upgrade) {
            return;
        }

        $nextSubscription = $this->subscription->nextSubscription();

        if ($nextSubscription === null) {
            return;
        }

        $nextSubscription->markAsCanceled();
        $nextSubscription->save();
    }

    protected function updateOnTerminationActions(): void
    {
        $params = [];

        $payInAdvance = (bool) $this->subscription->plan->pay_in_advance;

        if ($payInAdvance && $this->subscription->on_termination_credit_note !== $this->onTerminationCreditNote) {
            $params['on_termination_credit_note'] = $this->onTerminationCreditNote;
        }

        if ($this->subscription->on_termination_invoice !== $this->onTerminationInvoice) {
            $params['on_termination_invoice'] = $this->onTerminationInvoice;
        }

        if ($params === []) {
            return;
        }

        UpdateService::callBang(subscription: $this->subscription, params: $params);
    }

    /**
     * NOTE: If subscription is terminated automatically by setting ending_at,
     * there is a chance that this service will be called before the invoice
     * for the period has been generated. In that case we do not want to issue
     * a credit note.
     *
     * TODO(port): the gating helpers below depend on the invoice pipeline
     * (InvoiceSubscription matching, fees, invoice tax state) and are deferred
     * with CreditNotes::CreateFromTermination (M1 task 9). Rails logic:
     *   generate_credit_note_for_unconsumed_subscription? =
     *     pay_in_advance? && pay_in_advance_invoice_issued? &&
     *     on_termination_credit_note.in?(%i[credit refund offset])
     *   pay_in_advance_invoice_issued? re-computes the current period
     *   boundaries on a duplicated active subscription and asks
     *   InvoiceSubscription.matching?(subscription, boundaries, recurring: false).
     */
    protected function generateCreditNoteForUnconsumedSubscription(): bool
    {
        return false;
    }
}
