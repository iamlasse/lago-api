<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Carbon\CarbonInterface;

/**
 * Port of Rails' Billing::Cycle (app/services/billing/cycle.rb) — one
 * billing period of one rate card.
 *
 * `$endedAt` is EXCLUSIVE, unlike the billing_segments column of the same
 * name. The calendar varies per cycle, because a cadence change re-anchors
 * mid-walk.
 */
final class Cycle
{
    public function __construct(
        public readonly int $index,
        public readonly CarbonInterface $startedAt,
        public readonly CarbonInterface $endedAt,
        public readonly Phase $phase,
        public readonly Calendar $calendar,
    ) {}
}
