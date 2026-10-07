<?php

declare(strict_types=1);

namespace App\Services\BillableMetrics\ProratedAggregations;

use LogicException;
use App\Support\MoneyMath;
use App\Services\Events\Stores\PostgresStore;
use App\Services\ChargeModels\AggregationResult;
use App\Models\Billing\Context as BillingContext;
use App\Services\BillableMetrics\Aggregations\SumService as BaseSumService;

/**
 * Port of Rails' BillableMetrics::ProratedAggregations::SumService
 * (app/services/billable_metrics/prorated_aggregations/sum_service.rb) —
 * the non-grouped aggregation path for prorated sum charges.
 *
 * The persisted-before-period events bill at the FULL period proration
 * ratio; events inside the period prorate by their own day-ratio.
 *
 * TODO(port): the pay-in-advance branches (handle_current_usage /
 * compute_pay_in_advance_aggregation / per-event prorated aggregation) and
 * the grouped_by branches (M2).
 */
final class SumService extends BaseSumService
{
    private const PERSISTED_TOP_BOUNDARY_DELAY_MICROSECONDS = 1;

    private ?\App\Services\Events\Stores\ProratedAggregationResult $currentProratedResult = null;

    private ?\App\Services\Events\Stores\ProratedAggregationResult $persistedProratedResult = null;

    public function __construct(
        \App\Services\Events\Stores\BaseStore $eventStore,
        \App\Services\Fees\ChargeService\MeteredItem $meteredItem,
        BillingContext $billingContext,
        array $boundaries,
        array $filters = [],
        bool $bypassAggregation = false,
        array $aggregationOptions = [],
    ) {
        parent::__construct($eventStore, $meteredItem, $billingContext, $boundaries, $filters, $bypassAggregation, $aggregationOptions);

        // NOTE: the base (non-prorated) aggregator shares the SAME store
        // instance here — its `use_from_boundary` and numeric/property state
        // are the ones this service needs (Rails builds a separate window
        // store; both windows coincide on the arrears path).
    }

    public function computeAggregation(): AggregationResult
    {
        if ($this->shouldBypassAggregation()) {
            return $this->nullResult();
        }

        // Rails: `bill_full_amount?` — pay in advance charges billed on the
        // billing date (no event, not current usage) always bill the full
        // amount: delegate to the non-prorated aggregator.
        $options = $this->options();
        if ($options['is_pay_in_advance'] && ! $options['is_current_usage']) {
            return parent::computeAggregation();
        }

        if ($options['is_current_usage']) {
            // Rails: handle_current_usage — the cached max_aggregation_with_proration
            // adjustment of the in-period usage display (M2 pay-in-advance slice).
            throw new LogicException('Prorated sum current usage is not ported yet — TODO(port)');
        }

        $aggregationWithoutProration = parent::computeAggregation();

        $aggregation = $this->ceilTo5($this->computeEventAggregation());

        $fullUnitsNumber = $aggregationWithoutProration->aggregation;

        return new AggregationResult(
            aggregation: $aggregation,
            fullUnitsNumber: $fullUnitsNumber,
            count: $aggregationWithoutProration->count,
            options: $options,
        );
    }

    /**
     * Port of `compute_event_aggregation` — persisted value bills on the
     * full period, current value prorates by its own day ratio.
     */
    protected function computeEventAggregation(): string
    {
        return MoneyMath::add(
            (string) ($this->persistedProratedResult()->proratedValue ?? '0'),
            (string) ($this->currentProratedResult()->proratedValue ?? '0'),
        );
    }

    /** Port of `current_prorated_result` — prorated sum of the in-period events. */
    protected function currentProratedResult(): \App\Services\Events\Stores\ProratedAggregationResult
    {
        return $this->currentProratedResult ??= $this->eventStore->proratedSum($this->periodDuration());
    }

    /** Port of `persisted_prorated_result` — prorated sum of the earlier events. */
    protected function persistedProratedResult(): \App\Services\Events\Stores\ProratedAggregationResult
    {
        if ($this->persistedProratedResult !== null) {
            return $this->persistedProratedResult;
        }

        $store = $this->persistedEventStoreInstance();

        return $this->persistedProratedResult = $store->proratedSum(
            $this->periodDuration(),
            $this->billingContext->dateDiffWithTimezone($this->fromDatetime(), $this->toDatetime()),
        );
    }

    /**
     * Port of `persisted_event_store_instance` — the window BEFORE the
     * current period (avoiding double counting an event exactly on
     * from_datetime), without a lower boundary.
     */
    protected function persistedEventStoreInstance(): PostgresStore
    {
        $from = \Illuminate\Support\Facades\Date::parse($this->fromDatetime());
        $topBoundary = $from->copy()->subMicroseconds(self::PERSISTED_TOP_BOUNDARY_DELAY_MICROSECONDS);

        $store = $this->eventStore->forWindow(
            boundaries: ['to_datetime' => $topBoundary],
        );

        $store->setUseFromBoundary(false);

        /** @var PostgresStore */
        return $store;
    }

    /** Rails: `period_duration` — boundaries[:charges_duration]. */
    protected function periodDuration(): int|string
    {
        return $this->boundaries['charges_duration'] ?? 1;
    }

    /** Rails: `aggregation.ceil(5)`. */
    private function ceilTo5(string $value): string
    {
        $scaled = bcmul($value, '100000');
        $truncated = bcadd($scaled, '0', 0);

        $ceiled = MoneyMath::compare($scaled, $truncated) > 0
            ? bcadd($truncated, '1', 0)
            : $truncated;

        return bcdiv($ceiled, '100000', 5);
    }
}
