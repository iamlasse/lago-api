<?php

declare(strict_types=1);

namespace App\Support;

use WeakMap;

/**
 * Port of Rails' UsageProjections (app/models/usage_projections.rb) — the
 * per-fee projection map produced by the current-usage computation, with
 * `for(fees)` summing the projections of the given fees.
 */
final class UsageProjections
{
    /** @var WeakMap<object, UsageProjection> */
    private WeakMap $projections;

    public function __construct()
    {
        $this->projections = new WeakMap;
    }

    public function set(object $fee, UsageProjection $projection): void
    {
        $this->projections[$fee] = $projection;
    }

    public function forIterable(iterable $fees): UsageProjection
    {
        $total = UsageProjection::zero();

        foreach ($fees as $fee) {
            $total = $total->plus($this->projections[$fee] ?? UsageProjection::zero());
        }

        return $total;
    }
}
