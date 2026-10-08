<?php

declare(strict_types=1);

namespace App\Models\Billing;

use Carbon\CarbonInterface;

/**
 * Port of Rails' Billing::ElapsedPeriodRatio (app/models/billing/
 * elapsed_period_ratio.rb) — inclusive-day progress for usage projection,
 * not service-price proration. Callers choose the calendar dates and may
 * supply a full-period denominator for a shortened service window. The last
 * service day always completes it.
 */
final class ElapsedPeriodRatio
{
    public static function calculate(
        CarbonInterface $fromDate,
        CarbonInterface $toDate,
        CarbonInterface $currentDate,
        ?int $durationInDays = null,
    ): float {
        if ($currentDate->gte($toDate)) {
            return 1.0;
        }

        if ($currentDate->lt($fromDate)) {
            return 0.0;
        }

        $duration = $durationInDays ?? ((int) $fromDate->diffInDays($toDate) + 1);
        $daysPassed = (int) $fromDate->diffInDays($currentDate) + 1;

        $ratio = $duration === 0 ? 1.0 : $daysPassed / $duration;

        return max(0.0, min(1.0, $ratio));
    }
}
