<?php

declare(strict_types=1);

namespace App\Services\Fees;

use App\Models\Fee;
use App\Enums\FeeType;
use App\Models\Invoice;
use App\Support\Currency;
use App\Support\MoneyMath;
use App\Models\AdjustedFee;
use Carbon\CarbonImmutable;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Enums\FeePaymentStatus;
use App\Support\Utils\Datetime;
use Illuminate\Support\Facades\DB;
use App\Models\BillingPeriodBoundaries;
use App\Services\Subscriptions\DatesService;

/**
 * Port of Rails' Fees::SubscriptionService
 * (app/services/fees/subscription_service.rb) — the prorated subscription
 * fee: amount = billed_days * (plan_amount / period_days), rounded with
 * Ruby's half-away-from-zero semantics.
 */
class SubscriptionService extends \App\Services\BaseService
{
    public function __construct(
        private readonly Invoice $invoice,
        private readonly Subscription $subscription,
        private readonly BillingPeriodBoundaries $boundaries,
        private readonly ?string $context = null,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('fee');

        if ($this->alreadyBilled($result)) {
            return $result;
        }

        $newPreciseAmountCents = $this->computeAmount();
        $newAmountCents = MoneyMath::round($newPreciseAmountCents);
        $newFee = $this->initializeFee($result, $newAmountCents, $newPreciseAmountCents);

        $result->fee = $newFee;

        if ($this->context === 'preview') {
            return $result;
        }

        DB::transaction(function () use ($newFee): void {
            $newFee->save();

            if ($this->invoice->isDraft() && ($adjustedFee = $this->adjustedFee()) !== null) {
                $adjustedFee->fee_id = $newFee->id;
                $adjustedFee->save();
            }
        });

        return $result;
    }

    private function initializeFee(BaseResult $result, int $newAmountCents, string $newPreciseAmountCents): Fee
    {
        $plan = $this->subscription->plan;

        $baseFee = new Fee([
            'invoice_id' => $this->invoice->id,
            'organization_id' => $this->invoice->organization_id,
            'billing_entity_id' => $this->invoice->billing_entity_id,
            'subscription_id' => $this->subscription->id,
            'amount_cents' => $newAmountCents,
            'precise_amount_cents' => $newPreciseAmountCents,
            'amount_currency' => $plan->amount_currency,
            'fee_type' => FeeType::Subscription,
            'invoiceable_type' => 'Subscription',
            'invoiceable_id' => $this->subscription->id,
            'units' => '1',
            'properties' => $this->boundaries->toArray(),
            'payment_status' => FeePaymentStatus::Pending,
            'taxes_amount_cents' => 0,
            'unit_amount_cents' => $newAmountCents,
            'amount_details' => ['plan_amount_cents' => (int) $plan->amount_cents],
        ]);

        // Rails: base_fee.precise_unit_amount = base_fee.unit_amount.to_f
        // (amount in currency units as a float).
        $baseFee->precise_unit_amount = (string) ($newAmountCents / Currency::subunitToUnit((string) $plan->amount_currency));

        $adjustedFee = ($this->invoice->isDraft()) ? $this->adjustedFee() : null;

        if ($adjustedFee === null) {
            return $baseFee;
        }

        if (($adjustedFee->invoice_display_name !== null) && $this->adjustedDisplayName($adjustedFee)) {
            $baseFee->invoice_display_name = $adjustedFee->invoice_display_name;

            return $baseFee;
        }

        $units = (string) $adjustedFee->units;
        $unitPreciseAmountCents = (string) $adjustedFee->unit_precise_amount_cents;

        if ($this->adjustedUnits($adjustedFee)) {
            $amountCents = MoneyMath::mul($units, (string) $newAmountCents);
            $preciseAmountCents = MoneyMath::mul($units, $newPreciseAmountCents);
            $preciseUnitAmountCents = (string) $newAmountCents;
        } else {
            $amountCents = MoneyMath::mul($units, $unitPreciseAmountCents);
            $preciseAmountCents = MoneyMath::mul($units, $unitPreciseAmountCents);
            $preciseUnitAmountCents = $unitPreciseAmountCents;
        }

        $baseFee->amount_cents = MoneyMath::round($amountCents);
        $baseFee->precise_amount_cents = $preciseAmountCents;
        $baseFee->units = $units;
        $baseFee->unit_amount_cents = MoneyMath::round($preciseUnitAmountCents);
        $baseFee->precise_unit_amount = MoneyMath::fdiv(
            $preciseUnitAmountCents,
            (string) Currency::subunitToUnit((string) $this->invoice->currency),
        );
        $baseFee->invoice_display_name = $adjustedFee->invoice_display_name;

        return $baseFee;
    }

    /** Rails: `adjusted_fee.adjusted_display_name?` — neither units nor amount adjusted. */
    private function adjustedDisplayName(AdjustedFee $adjustedFee): bool
    {
        return ! $this->adjustedUnits($adjustedFee) && ! $this->adjustedAmount($adjustedFee);
    }

    private function adjustedUnits(AdjustedFee $adjustedFee): bool
    {
        return (bool) $adjustedFee->adjusted_units;
    }

    private function adjustedAmount(AdjustedFee $adjustedFee): bool
    {
        return (bool) $adjustedFee->adjusted_amount;
    }

    private function adjustedFee(): ?AdjustedFee
    {
        return AdjustedFee::query()
            ->where('invoice_id', $this->invoice->id)
            ->where('subscription_id', $this->subscription->id)
            ->where('fee_type', FeeType::Subscription->value)
            ->whereRaw("properties->>'from_datetime' = ?", [
                $this->serializeIso($this->boundaries->fromDatetime),
            ])
            ->whereRaw("properties->>'to_datetime' = ?", [
                $this->serializeIso($this->boundaries->toDatetime),
            ])
            ->first();
    }

    /** Rails `iso8601(3)` — millisecond precision, UTC. */
    private function serializeIso(mixed $datetime): ?string
    {
        if ($datetime === null) {
            return null;
        }

        return Datetime::parseIso8601($datetime)?->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    private function alreadyBilled(BaseResult $result): bool
    {
        $existingFee = $this->invoice->fees()
            ->subscription()
            ->where('subscription_id', $this->subscription->id)
            ->first();

        if ($existingFee === null) {
            return false;
        }

        $result->fee = $existingFee;

        return true;
    }

    /** @return string|int decimal string (or 0 / plan amount int) */
    private function computeAmount(): string
    {
        // NOTE: bill for the last time a subscription that was upgraded
        if ($this->shouldComputeTerminatedAmount()) {
            return (string) $this->terminatedAmount();
        }

        // NOTE: bill for the first time a subscription created after an upgrade
        if ($this->shouldComputeUpgradedAmount()) {
            return (string) $this->upgradedAmount();
        }

        // NOTE: bill a subscription on a full period
        if ($this->shouldUseFullAmount()) {
            return (string) $this->fullPeriodAmount();
        }

        // NOTE: bill a subscription for the first time (or after downgrade)
        return (string) $this->firstSubscriptionAmount();
    }

    private function shouldComputeTerminatedAmount(): bool
    {
        if (! $this->subscription->terminated()) {
            return false;
        }

        if ($this->subscription->plan->pay_in_advance) {
            return false;
        }

        return $this->subscription->upgraded() || $this->subscription->nextSubscription() === null;
    }

    private function shouldComputeUpgradedAmount(): bool
    {
        if ($this->subscription->previous_subscription_id === null) {
            return false;
        }

        // Rails: subscription.invoices.where.not(status: :deleted) — through
        // invoice_subscriptions (no direct relation ported on Subscription).
        $nonDeletedInvoices = Invoice::query()
            ->whereIn('id', $this->subscription->invoiceSubscriptions()->select('invoice_id'))
            ->where('status', '!=', \App\Enums\InvoiceStatus::Deleted->value)
            ->count();

        if ($nonDeletedInvoices > 1) {
            return false;
        }

        return $this->subscription->previousSubscription?->upgraded() ?? false;
    }

    /**
     * NOTE: Subscription has already been billed once and is not terminated,
     * or it is paid in advance on an anniversary base.
     */
    private function shouldUseFullAmount(): bool
    {
        $plan = $this->subscription->plan;

        if ($plan->pay_in_advance && $this->subscription->anniversary() && $this->subscription->previous_subscription_id === null) {
            return true;
        }

        $billedBefore = Fee::query()
            ->subscription()
            ->where('subscription_id', $this->subscription->id)
            ->where('created_at', '<', $this->invoice->created_at)
            ->exists();

        if ($billedBefore) {
            return true;
        }

        if ($this->subscription->startedInPast() && $plan->pay_in_advance) {
            return true;
        }

        if ($this->subscription->startedInPast()
            && $this->subscription->started_at->lt($this->dateService($this->subscription)->previousBeginningOfPeriod())) {
            return true;
        }

        return false;
    }

    private function firstSubscriptionAmount(): string
    {
        $fromDatetime = $this->boundaries->fromDatetime;
        $toDatetime = $this->boundaries->toDatetime;
        $plan = $this->subscription->plan;

        if ($plan->hasTrial()) {
            // NOTE: amount is 0 if trial covers the full period
            if ($this->subscription->trialEndDate()->gte($toDatetime)) {
                return 0;
            }

            // NOTE: from_date is the trial end date if it happens during the period
            if ($this->subscription->trialEndDate()->gt($fromDatetime) && $this->subscription->trialEndDate()->lt($toDatetime)) {
                $fromDatetime = $this->subscription->initialStartedAt()->addDays((int) $plan->trial_period);
            }
        }

        // NOTE: Number of days of the first period since subscription creation
        $daysToBill = Datetime::dateDiffWithTimezone(
            $fromDatetime,
            $toDatetime,
            $this->customer()->applicableTimezone(),
        );

        return (string) ($daysToBill * $this->singleDayPrice($this->subscription));
    }

    /**
     * NOTE: When terminating a pay-in-arrear subscription, we bill the number
     * of used days of the terminated subscription.
     */
    private function terminatedAmount(): string
    {
        $fromDatetime = $this->boundaries->fromDatetime;
        $toDatetime = $this->boundaries->toDatetime;
        $plan = $this->subscription->plan;

        if ($plan->hasTrial()) {
            if ($this->subscription->trialEndDatetime()->gte($toDatetime)) {
                return 0;
            }

            if ($this->subscription->trialEndDatetime()->gt($fromDatetime) && $this->subscription->trialEndDatetime()->lt($toDatetime)) {
                $fromDatetime = $this->subscription->trialEndDatetime();
            }
        }

        // NOTE: number of days between beginning of the period and the termination date
        $numberOfDaysToBill = Datetime::dateDiffWithTimezone(
            $fromDatetime,
            $toDatetime,
            $this->customer()->applicableTimezone(),
        );

        $optionalFromDate = Datetime::parseIso8601($fromDatetime)
            ?->setTimezone($this->customer()->applicableTimezone())
            ->startOfDay();

        return (string) ($numberOfDaysToBill * $this->singleDayPrice($this->subscription, $optionalFromDate));
    }

    private function upgradedAmount(): string
    {
        $fromDatetime = $this->boundaries->fromDatetime;
        $toDatetime = $this->boundaries->toDatetime;
        $plan = $this->subscription->plan;

        if ($plan->hasTrial()) {
            if ($this->subscription->trialEndDatetime()->gte($toDatetime)) {
                return 0;
            }

            if ($this->subscription->trialEndDatetime()->gt($fromDatetime) && $this->subscription->trialEndDatetime()->lt($toDatetime)) {
                $fromDatetime = $this->subscription->trialEndDatetime();
            }
        }

        // NOTE: number of days between the upgrade and the end of the period
        $numberOfDaysToBill = Datetime::dateDiffWithTimezone(
            $fromDatetime,
            $toDatetime,
            $this->customer()->applicableTimezone(),
        );

        // NOTE: We only bill the days between the upgrade date and the end of
        // the period. A credit note will apply the amount of days from the
        // previous plan that were not consumed.
        return (string) ($numberOfDaysToBill * $this->singleDayPrice($this->subscription));
    }

    private function fullPeriodAmount(): string
    {
        $fromDatetime = $this->boundaries->fromDatetime;
        $toDatetime = $this->boundaries->toDatetime;
        $plan = $this->subscription->plan;

        if ($plan->hasTrial()) {
            if ($this->subscription->trialEndDatetime()->gte($toDatetime)) {
                return 0;
            }

            // NOTE: from_date is the trial end date if it happens during the
            // period; for this case we apply the prorata between the trial end
            // date and the invoice to_date, not the full period amount.
            if ($this->subscription->trialEndDatetime()->gt($fromDatetime) && $this->subscription->trialEndDatetime()->lt($toDatetime)) {
                $numberOfDaysToBill = Datetime::dateDiffWithTimezone(
                    $this->subscription->trialEndDatetime(),
                    $toDatetime,
                    $this->customer()->applicableTimezone(),
                );

                $optionalFromDate = Datetime::parseIso8601($fromDatetime)
                    ?->setTimezone($this->customer()->applicableTimezone())
                    ->startOfDay();

                return (string) ($numberOfDaysToBill * $this->singleDayPrice($this->subscription, $optionalFromDate));
            }
        }

        // BUGFIX(port): declared `: string` but returned an int — TypeError on
        // every non-trial full-period subscription fee (Rails: plan.amount_cents).
        return (string) $plan->amount_cents;
    }

    private function dateService(Subscription $subscription): DatesService
    {
        // TODO(integration): verify signature against ported DatesService
        return DatesService::newInstance(
            $subscription,
            CarbonImmutable::createFromTimestampUTC((int) Datetime::parseIso8601($this->boundaries->timestamp)->getTimestamp()),
        );
    }

    /** NOTE: cost of a single day in a period. */
    private function singleDayPrice(Subscription $targetSubscription, ?CarbonImmutable $optionalFromDate = null): float
    {
        // TODO(integration): verify signature against ported DatesService
        return $this->dateService($targetSubscription)->singleDayPrice($optionalFromDate);
    }

    private function customer()
    {
        return $this->invoice->customer;
    }
}
