<?php

declare(strict_types=1);

namespace App\Services\BillableMetrics\ProratedAggregations;

use LogicException;
use App\Support\MoneyMath;
use App\Services\ChargeModels\AggregationResult;
use App\Services\BillableMetrics\Aggregations\UniqueCountService as BaseUniqueCountService;

/**
 * Port of Rails' BillableMetrics::ProratedAggregations::UniqueCountService
 * (app/services/billable_metrics/prorated_aggregations/unique_count_service.rb)
 * — the non-grouped aggregation path for prorated unique count charges.
 *
 * TODO(port): the pay-in-advance branches (handle_current_usage /
 * compute_pay_in_advance_aggregation) and the grouped_by branches (M2).
 */
final class UniqueCountService extends BaseUniqueCountService
{
    public function computeAggregation(): AggregationResult
    {
        $aggregationWithoutProration = parent::computeAggregation();

        $options = $this->options();

        // For charges that are pay in advance on billing date we always bill full amount.
        if ($options['is_pay_in_advance'] && ! $options['is_current_usage']) {
            return $aggregationWithoutProration;
        }

        $aggregation = $this->ceilTo5((string) $this->eventStore->proratedUniqueCount()->value);

        if ($options['is_current_usage']) {
            // Rails: handle_current_usage — pay-in-advance current usage
            // proration state (M2).
            throw new LogicException('Prorated unique count current usage is not ported yet — TODO(port)');
        }

        return new AggregationResult(
            aggregation: $aggregation,
            fullUnitsNumber: $aggregationWithoutProration->aggregation,
            count: (int) $aggregation,
            options: $options,
        );
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
