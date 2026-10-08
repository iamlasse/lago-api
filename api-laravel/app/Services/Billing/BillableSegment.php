<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Carbon\CarbonInterface;
use App\Models\RateOverride;
use InvalidArgumentException;

/**
 * Port of Rails' Billing::BillableSegment (app/services/billing/
 * billable_segment.rb) — one slice of one cycle; everything a writer needs
 * for one `billing_segments` row.
 *
 * The calendar only builds a segment for a window it can price, so a segment
 * without either rate is a caller's mistake rather than a state to carry.
 */
final class BillableSegment
{
    public function __construct(
        public readonly int $cycleIndex,
        public readonly CarbonInterface $cycleStartedAt,
        public readonly CarbonInterface $startedAt,
        public readonly CarbonInterface $endedAt,
        public readonly CarbonInterface $billingAt,
        public readonly ?object $rate,
        public readonly ?RateOverride $rateOverride,
        public readonly float $prorationRatio,
        public readonly ?string $ratePhaseCode,
    ) {
        if ($rate === null && $rateOverride === null) {
            throw new InvalidArgumentException(
                'a billable segment needs a rate or an override to price it',
            );
        }
    }

    /**
     * Rails: `#properties` — a phase override prices the segment; the rate
     * card's own rate does otherwise.
     *
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        $source = $this->rateOverride ?? $this->rate;
        assert($source !== null);

        return $source->properties();
    }
}
