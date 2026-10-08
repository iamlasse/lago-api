<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\RateOverride;
use InvalidArgumentException;

/**
 * Port of Rails' Billing::Phase (app/services/billing/phase.rb) — a stretch
 * of consecutive cycles priced under one override. Carries no dates: a phase
 * starts where the previous one ended, so its place in the queue is its date.
 */
final class Phase
{
    public function __construct(
        public readonly ?string $code,
        /** null runs to the end of the card. */
        public readonly ?int $billingIntervalCycleCount,
        public readonly ?RateOverride $rateOverride,
    ) {
        if ($billingIntervalCycleCount !== null && $billingIntervalCycleCount < 1) {
            throw new InvalidArgumentException(
                'a phase lasts a positive whole number of cycles, or nil to run to the end, got '
                .var_export($billingIntervalCycleCount, true),
            );
        }
    }

    /**
     * Rails: `Phase.default` — the card's own rate and cadence, with no
     * cycle limit. No code, because nothing persisted it — being last is
     * what defines it.
     */
    public static function default(): self
    {
        return new self(code: null, billingIntervalCycleCount: null, rateOverride: null);
    }

    /** Rails: `#unbounded?` — whether this phase runs to the end of the card. */
    public function unbounded(): bool
    {
        return $this->billingIntervalCycleCount === null;
    }
}
