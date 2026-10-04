<?php

declare(strict_types=1);

namespace App\Services\Fees;

use App\Models\Fee;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\Utils\Datetime;
use App\Support\UsageProjection;
use App\Services\ChargeModels\AggregationResult;
use App\Services\Fees\ChargeService\MeteredItem;
use App\Services\ChargeModels\Factory as ChargeModelFactory;

/**
 * Port of Rails' Fees::ProjectionService
 * (app/services/fees/projection_service.rb) — the projected end-of-period
 * units and amount of a single current-usage fee.
 *
 * Charge models that price from the charge properties (standard, graduated,
 * package, volume) are re-priced on the projected units; the others depend on
 * per-event data the fee does not keep, so their current amount is scaled.
 *
 * TODO(port): pricing units (AppliedPricingUnit stays none) and presentation
 * breakdowns (the M2 filters slice) are not ported.
 */
class ProjectionService extends BaseService
{
    private const REPRICED_CHARGE_MODELS = ['standard', 'graduated', 'package', 'volume'];

    public function __construct(
        private readonly Fee $fee,
        private readonly MeteredItem $meteredItem,
        private readonly string $timezone,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('projection');

        if ($this->meteredItem->billableMetric()->recurring) {
            $result->projection = $this->currentProjection();
        } elseif ($this->periodRatio() > 0.0) {
            $result->projection = $this->projection();
        } else {
            $result->projection = UsageProjection::zero();
        }

        return $result;
    }

    private function currentProjection(): UsageProjection
    {
        return new UsageProjection(
            units: (string) $this->fee->units,
            amountCents: (int) $this->fee->amount_cents,
        );
    }

    private function projection(): UsageProjection
    {
        $amountCents = $this->repriced() ? $this->repricedAmountCents() : $this->scaledAmountCents();

        return new UsageProjection(
            units: $this->projectedUnits(),
            amountCents: $amountCents,
        );
    }

    private function repriced(): bool
    {
        return in_array($this->meteredItem->charge->charge_model, self::REPRICED_CHARGE_MODELS, true);
    }

    /**
     * Re-price the projected units with the charge properties.
     */
    private function repricedAmountCents(): int
    {
        $units = (string) $this->fee->units;

        $aggregationResult = new AggregationResult(
            aggregation: $units,
            currentUsageUnits: $units,
            fullUnitsNumber: $units,
            totalAggregatedUnits: $units,
            count: (int) $this->fee->events_count,
            options: ['running_total' => []],
        );

        $chargeModelResult = ChargeModelFactory::newInstance(
            pricingStructure: $this->meteredItem->pricingStructure(),
            aggregationResult: $aggregationResult,
            periodRatio: $this->periodRatio(),
            calculateProjectedUsage: true,
        )->apply();

        $amountResult = AmountsService::build(
            currency: $this->meteredItem->currency(),
            chargeModelResult: $chargeModelResult,
            appliedPricingUnit: AppliedPricingUnit::none(),
        );

        return (int) $amountResult->amount->amount_cents;
    }

    /**
     * Scale the fee's precise amount over the full period.
     */
    private function scaledAmountCents(): int
    {
        return max(
            MoneyMath::round(MoneyMath::fdiv((string) $this->fee->precise_amount_cents, (string) $this->periodRatio())),
            0,
        );
    }

    private function projectedUnits(): string
    {
        $units = (string) $this->fee->units;

        if (MoneyMath::compare($units, '0') <= 0) {
            return '0';
        }

        return MoneyMath::roundTo(MoneyMath::fdiv($units, (string) $this->periodRatio()), 2);
    }

    /**
     * Rails: `period_ratio` — the units cover the charges period, which is
     * shorter than the billing period for yearly plans billing charges
     * monthly; the ratio is timezone-aware (day-count based).
     */
    private function periodRatio(): float
    {
        $fromDatetime = Datetime::parseIso8601($this->fee->properties['charges_from_datetime'] ?? null);
        $toDatetime = Datetime::parseIso8601($this->fee->properties['charges_to_datetime'] ?? null);
        $currentTime = now();

        if ($fromDatetime === null || $toDatetime === null) {
            return 1.0;
        }

        if ($currentTime->gte($toDatetime)) {
            return 1.0;
        }

        if ($currentTime->lt($fromDatetime)) {
            return 0.0;
        }

        $totalDays = Datetime::dateDiffWithTimezone($fromDatetime, $toDatetime, $this->timezone);
        $daysPassed = Datetime::dateDiffWithTimezone($fromDatetime, $currentTime, $this->timezone);

        if ($totalDays === 0) {
            return 1.0;
        }

        return max(0.0, min(1.0, $daysPassed / $totalDays));
    }
}
