<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Fee;
use App\Models\Plan;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\Utils\Datetime;
use App\Support\UsageProjections;
use App\Support\SubscriptionUsage;
use Illuminate\Support\Collection;
use App\Models\BillingPeriodBoundaries;
use App\Services\Fees\ProjectionService;
use App\Services\Fees\ChargeService\Options;
use App\Services\Subscriptions\DatesService;
use App\Services\Fees\ChargeService\MeteredItem;
use App\Services\Fees\ApplyTaxesService as FeeApplyTaxesService;

/**
 * Port of Rails' Invoices::CustomerUsageService
 * (app/services/invoices/customer_usage_service.rb) — the per-subscription
 * current-usage computation behind the customers REST usage endpoints and
 * the wallet ongoing-balance refresh chain.
 *
 * The usage is computed on a NON-persisted invoice: charge fees are built
 * in memory through Fees\ChargeService (context: current_usage), fee and
 * invoice taxes are applied on the in-memory objects, and the totals are
 * rolled up without ever touching the database.
 *
 * M-later seams (see the TODO(port) markers):
 * - UsageFilters (filter by charge / metric code / group, full usage) —
 *   arrives with the M2 filters pipeline;
 * - Subscriptions::ChargeCacheMiddleware (fragmented usage cache) — the
 *   aggregation always runs live; with_cache is accepted and ignored;
 * - provider taxation (Anrok/Avalara) — integrations milestone;
 * - usage buckets + streaming destinations — ClickHouse slice.
 */
class CustomerUsageService extends BaseService
{
    private ?Invoice $invoice = null;

    /** @var list<Fee>|null */
    private ?array $fees = null;

    /**
     * Rails: `boundaries` — the current billing period, as seen by the
     * subscription's dates service in current-usage mode. `max_timestamp`
     * forces the charges_to_datetime boundary (wallet refresh on terminated
     * subscriptions).
     */
    private ?BillingPeriodBoundaries $boundariesMemo = null;

    public function __construct(
        private readonly ?Customer $customer,
        private readonly ?Subscription $subscription,
        private readonly mixed $timestamp = null,
        private readonly bool $applyTaxes = true,
        private readonly bool $withCache = true,
        private readonly mixed $maxTimestamp = null,
        private readonly bool $withProjection = false,
    ) {
        parent::__construct();
    }

    /**
     * Port of `self.with_external_ids` — the REST entrypoint (customers are
     * keyed by external_id, subscriptions by their external_id too).
     */
    public static function withExternalIds(
        string $customerExternalId,
        string $externalSubscriptionId,
        string $organizationId,
        bool $applyTaxes = true,
        bool $withProjection = false,
    ): BaseResult {
        $customer = Customer::query()
            ->where('organization_id', $organizationId)
            ->where('external_id', $customerExternalId)
            ->first();

        if ($customer === null) {
            return BaseResult::of('usage', 'invoice')->notFoundFailure('customer');
        }

        $subscription = $customer->subscriptions()
            ->active()
            ->where('external_id', $externalSubscriptionId)
            ->first();

        return (new self(
            customer: $customer,
            subscription: $subscription,
            applyTaxes: $applyTaxes,
            withProjection: $withProjection,
        ))->execute();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('invoice', 'usage');

        if ($this->customer === null) {
            return $result->notFoundFailure('customer');
        }

        if ($this->subscription === null) {
            return $result->notAllowedFailure('no_active_subscription');
        }

        $result->usage = $this->computeUsage();
        $result->invoice = $this->invoice;

        return $result;
    }

    private function computeUsage(): SubscriptionUsage
    {
        $this->invoice = new Invoice([
            'organization_id' => $this->subscription->organization_id,
            'billing_entity_id' => $this->customer->billing_entity_id,
            'customer_id' => $this->customer->id,
            'issuing_date' => Datetime::serialize($this->boundaries()->issuingDate),
            'currency' => $this->plan()->amount_currency,
        ]);

        $this->fees = $this->computeChargeFees();

        $this->invoice->fees_amount_cents = collect($this->fees)->sum(fn (Fee $fee) => (int) $fee->amount_cents);

        if ($this->applyTaxes) {
            $this->computeAmounts();
        } else {
            $this->computeAmountsWithoutTax();
        }

        return $this->formatUsage();
    }

    private function plan(): Plan
    {
        return $this->subscription->plan;
    }

    /**
     * Rails: `charges` — every charge of the plan, joined to its billable
     * metric. UsageFilters narrowing is TODO(port) (filters pipeline, M2).
     *
     * @return Collection<int, \App\Models\Charge>
     */
    private function charges(): Collection
    {
        return $this->subscription->plan->charges()
            ->join('billable_metrics', 'billable_metrics.id', '=', 'charges.billable_metric_id')
            ->select('charges.*')
            ->with('billableMetric')
            ->get();
    }

    /** @return list<Fee> */
    private function computeChargeFees(): array
    {
        $fees = [];

        foreach ($this->charges() as $charge) {
            $feeResult = \App\Services\Fees\ChargeService::call(
                invoice: $this->invoice,
                meteredItem: MeteredItem::fromCharge($charge, $this->boundaries()),
                subscription: $this->subscription,
                options: new Options(
                    // NOTE: current usage is computed on a non-persisted
                    // invoice, so adjusted fees never apply.
                    context: 'current_usage',
                    applyTaxes: false,
                    skipAdjustedFees: true,
                ),
            );

            try {
                $feeResult->raiseIfError();
            } catch (\App\Services\Failures\FailedResult $e) {
                throw $e;
            }

            foreach ($feeResult->fees ?? [] as $fee) {
                $fees[] = $fee;
            }
        }

        // Rails sorts the fees by billable metric name (downcased) unless the
        // request filters by charge (UsageFilters — TODO(port)).
        usort($fees, fn (Fee $a, Fee $b) => strcmp(
            mb_strtolower((string) ($a->charge->billableMetric->name ?? '')),
            mb_strtolower((string) ($b->charge->billableMetric->name ?? '')),
        ));

        return $fees;
    }

    private function boundaries(): BillingPeriodBoundaries
    {
        if ($this->boundariesMemo !== null) {
            return $this->boundariesMemo;
        }

        $dateService = DatesService::newInstance(
            $this->subscription,
            $this->timestamp ?? now(),
            currentUsage: true,
        );

        $this->boundariesMemo = new BillingPeriodBoundaries(
            fromDatetime: $dateService->fromDatetime(),
            toDatetime: $dateService->toDatetime(),
            chargesFromDatetime: $dateService->chargesFromDatetime(),
            chargesToDatetime: $dateService->chargesToDatetime(),
            chargesDuration: $dateService->chargesDurationInDays(),
            timestamp: $this->timestamp ?? now(),
            issuingDate: $dateService->nextEndOfPeriod(),
        );

        if ($this->maxTimestamp !== null) {
            $this->boundariesMemo->maxTimestamp = $this->maxTimestamp;
        }

        return $this->boundariesMemo;
    }

    private function computeAmounts(): void
    {
        $this->invoice->setRelation('fees', collect($this->fees));

        foreach ($this->fees as $fee) {
            // In-memory fees carry no coupons column default — normalize the
            // null precise coupon bucket before the tax math reads it.
            $fee->precise_coupons_amount_cents ??= '0';

            FeeApplyTaxesService::call(
                fee: $fee,
                plan: $this->plan(),
                customer: $this->customer,
            )->raiseIfError();
        }

        ApplyTaxesService::call(invoice: $this->invoice)->raiseIfError();

        $this->invoice->total_amount_cents = (int) $this->invoice->fees_amount_cents + (int) $this->invoice->taxes_amount_cents;
    }

    private function computeAmountsWithoutTax(): void
    {
        $this->invoice->taxes_amount_cents = 0;
        $this->invoice->taxes_rate = 0;
        $this->invoice->total_amount_cents = (int) $this->invoice->fees_amount_cents;
    }

    private function formatUsage(): SubscriptionUsage
    {
        $boundaries = $this->boundaries();

        return new SubscriptionUsage(
            fromDatetime: $this->iso8601($boundaries->chargesFromDatetime),
            toDatetime: $this->iso8601($boundaries->chargesToDatetime),
            issuingDate: $this->iso8601($boundaries->issuingDate),
            currency: (string) $this->invoice->currency,
            amountCents: (int) $this->invoice->fees_amount_cents,
            totalAmountCents: (int) $this->invoice->total_amount_cents,
            taxesAmountCents: (int) $this->invoice->taxes_amount_cents,
            fees: $this->fees ?? [],
            projections: $this->projections(),
        );
    }

    private function projections(): ?UsageProjections
    {
        if (! $this->withProjection) {
            return null;
        }

        $timezone = $this->customer->applicableTimezone();
        $projections = new UsageProjections;

        foreach ($this->fees ?? [] as $fee) {
            $projection = ProjectionService::call(
                fee: $fee,
                meteredItem: $this->meteredItemFor($fee),
                timezone: (string) $timezone,
            )->projection;

            $projections->set($fee, $projection);
        }

        return $projections;
    }

    private function meteredItemFor(Fee $fee): MeteredItem
    {
        return MeteredItem::fromCharge($fee->charge, $this->boundaries());
    }

    /** Rails: `iso8601` — second precision, UTC. */
    private function iso8601(mixed $datetime): string
    {
        return Datetime::parseIso8601($datetime)->utc()->toIso8601String();
    }
}
