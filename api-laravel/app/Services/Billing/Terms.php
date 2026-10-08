<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Carbon\CarbonInterface;
use InvalidArgumentException;
use App\Enums\RateCardBillingTiming;

/**
 * Port of Rails' Billing::Terms (app/services/billing/terms.rb) — the
 * billing timing a rate card bills on, and which end of a window falls due.
 */
final class Terms
{
    public function __construct(
        public readonly RateCardBillingTiming $timing,
        public readonly bool $prorated,
    ) {}

    /**
     * Rails: `Terms.new(timing:, prorated:)` accepts symbols/strings; the
     * Laravel port takes the string-backed enum directly.
     */
    public static function from(string|RateCardBillingTiming $timing, bool $prorated): self
    {
        $timing = $timing instanceof RateCardBillingTiming
            ? $timing
            : (RateCardBillingTiming::tryFrom((string) $timing)
                ?? throw new InvalidArgumentException(
                    'unknown billing timing '.var_export($timing, true),
                ));

        return new self(timing: $timing, prorated: $prorated);
    }

    /**
     * Rails: `#billing_at_for(window)` — which end of a window falls due: the
     * start in advance, the end in arrears.
     */
    public function billingAtFor(CarbonInterface $startedAt, CarbonInterface $endedAt): CarbonInterface
    {
        return $this->timing === RateCardBillingTiming::Advance ? $startedAt : $endedAt;
    }
}
