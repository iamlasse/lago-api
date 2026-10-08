<?php

declare(strict_types=1);

namespace App\Services\DailyUsages;

use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\InvoiceSubscription;
use App\Serializers\V1\Customers\UsageSerializer;

/**
 * Port of Rails' DailyUsages::FillFromInvoiceService
 * (app/services/daily_usages/fill_from_invoice_service.rb) — builds the
 * daily usage rows covered by a periodic/terminating invoice, from the
 * invoice's own fees (the billing day's usage comes from the invoice, not
 * the cache).
 */
class FillFromInvoiceService extends BaseService
{
    /** Rails: Usage = Struct.new(...) — the invoice-derived usage snapshot. */
    public function __construct(
        private readonly Invoice $invoice,
        /** @var list<Subscription> */
        private readonly array $subscriptions,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('daily_usages');
        $result->daily_usages = [];

        foreach ($this->invoice->invoiceSubscriptions as $invoiceSubscription) {
            $subscription = null;
            foreach ($this->subscriptions as $candidate) {
                if ($candidate->id === $invoiceSubscription->subscription_id) {
                    $subscription = $candidate;

                    break;
                }
            }

            if ($subscription === null) {
                continue;
            }

            if (! $this->chargeBoundariesValid($invoiceSubscription)) {
                continue;
            }

            if ($this->existingDailyUsage($invoiceSubscription) !== null) {
                continue;
            }

            $usage = $this->invoiceUsage($subscription, $invoiceSubscription);

            if ($usage['fees'] !== []) {
                $dailyUsage = new DailyUsage([
                    'organization_id' => $this->invoice->organization_id,
                    'customer_id' => $this->invoice->customer_id,
                    'subscription_id' => $subscription->id,
                    'external_subscription_id' => $subscription->external_id,
                    'usage' => $this->serializeUsage($usage),
                    'from_datetime' => $invoiceSubscription->charges_from_datetime->copy()->startOfSecond(),
                    'to_datetime' => $invoiceSubscription->charges_to_datetime->copy()->startOfSecond(),
                    'refreshed_at' => $invoiceSubscription->timestamp,
                    'usage_date' => $this->usageDate($invoiceSubscription),
                ]);

                $dailyUsage->usage_diff = ComputeDiffService::callBang(dailyUsage: $dailyUsage)->usage_diff;

                $dailyUsage->save();

                $result->daily_usages[] = $dailyUsage;
            }
        }

        return $result;
    }

    /**
     * Rails: `invoice_usage` — the invoice's charge fees for the
     * subscription, plus the pay-in-advance fees billed in advance over the
     * same boundaries.
     *
     * @return array{from_datetime: string, to_datetime: string, issuing_date: string, currency: string, amount_cents: int, total_amount_cents: int, taxes_amount_cents: int, fees: list<Fee>}
     */
    private function invoiceUsage(Subscription $subscription, InvoiceSubscription $invoiceSubscription): array
    {
        $inAdvanceFees = $this->inAdvanceFees($subscription, $invoiceSubscription);

        $chargeFees = $this->invoice->fees()
            ->charge()
            ->get()
            ->filter(fn (Fee $fee): bool => $fee->subscription_id === $subscription->id)
            ->values()
            ->all();

        $fees = [...$inAdvanceFees, ...$chargeFees];

        $inAdvanceAmountCents = array_sum(array_map(fn (Fee $f): int => (int) $f->amount_cents, $inAdvanceFees));
        $chargeAmountCents = array_sum(array_map(fn (Fee $f): int => (int) $f->amount_cents, $chargeFees));
        $amountCents = $inAdvanceAmountCents + $chargeAmountCents;

        $inAdvanceTaxesCents = array_sum(array_map(fn (Fee $f): int => (int) $f->taxes_amount_cents, $inAdvanceFees));
        $chargeTaxesCents = array_sum(array_map(fn (Fee $f): int => (int) $f->taxes_amount_cents, $chargeFees));
        $taxesAmountCents = $inAdvanceTaxesCents + $chargeTaxesCents;

        return [
            'from_datetime' => $invoiceSubscription->charges_from_datetime->copy()->startOfSecond()->toDateTimeString(),
            'to_datetime' => $invoiceSubscription->charges_to_datetime->copy()->startOfSecond()->toDateTimeString(),
            'issuing_date' => $this->invoice->issuing_date->toDateString(),
            'currency' => $this->invoice->currency,
            'amount_cents' => $amountCents,
            'total_amount_cents' => $amountCents + $taxesAmountCents,
            'taxes_amount_cents' => $taxesAmountCents,
            'fees' => $fees,
        ];
    }

    /**
     * Rails: `in_advance_fees` — the subscription's pay-in-advance charge
     * fees bound to exactly the invoice subscription's boundaries.
     *
     * @return list<Fee>
     */
    private function inAdvanceFees(Subscription $subscription, InvoiceSubscription $invoiceSubscription): array
    {
        $from = $invoiceSubscription->charges_from_datetime?->toDateTimeString();
        $to = $invoiceSubscription->charges_to_datetime?->toDateTimeString();

        return Fee::query()
            ->charge()
            ->where('fees.subscription_id', $subscription->id)
            ->whereNotNull('fees.pay_in_advance_event_transaction_id')
            ->where('fees.pay_in_advance', true)
            ->whereRaw("(fees.properties->>'charges_from_datetime')::timestamptz = ?", [$from])
            ->whereRaw("(fees.properties->>'charges_to_datetime')::timestamptz = ?", [$to])
            ->get()
            ->all();
    }

    private function serializeUsage(array $usage): array
    {
        $snapshot = new \App\Support\SubscriptionUsage(
            fromDatetime: $usage['from_datetime'],
            toDatetime: $usage['to_datetime'],
            issuingDate: $usage['issuing_date'],
            currency: $usage['currency'],
            amountCents: $usage['amount_cents'],
            totalAmountCents: $usage['total_amount_cents'],
            taxesAmountCents: $usage['taxes_amount_cents'],
            fees: $usage['fees'],
        );

        return (new UsageSerializer($snapshot, ['includes' => ['charges_usage']]))->serialize();
    }

    private function existingDailyUsage(InvoiceSubscription $invoiceSubscription): ?DailyUsage
    {
        return DailyUsage::query()
            ->where('from_datetime', $invoiceSubscription->charges_from_datetime->copy()->startOfSecond())
            ->where('to_datetime', $invoiceSubscription->charges_to_datetime->copy()->startOfSecond())
            ->whereDate('usage_date', $this->usageDate($invoiceSubscription)->toDateString())
            ->where('subscription_id', $invoiceSubscription->subscription_id)
            ->first();
    }

    private function chargeBoundariesValid(InvoiceSubscription $invoiceSubscription): bool
    {
        if ($invoiceSubscription->charges_from_datetime === null) {
            return false;
        }

        if ($invoiceSubscription->charges_to_datetime === null) {
            return false;
        }

        return $invoiceSubscription->charges_from_datetime->lte($invoiceSubscription->charges_to_datetime);
    }

    private function usageDate(InvoiceSubscription $invoiceSubscription)
    {
        return $invoiceSubscription->charges_to_datetime
            ->copy()
            ->setTimezone($this->invoice->customer->applicableTimezone())
            ->startOfDay();
    }
}
