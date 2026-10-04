<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Port of Rails' UsageProjection Data object
 * (app/models/usage_projection.rb) — the projected end-of-period units and
 * amount of a single fee.
 *
 * TODO(port): pricing_unit_amount_cents stays null (PricingUnitUsage is not
 * ported) and presentation_breakdowns stay empty (the breakdowns pipeline
 * arrives with the M2 filters slice).
 */
final class UsageProjection
{
    /**
     * @param  list<mixed>  $presentationBreakdowns
     */
    public function __construct(
        public readonly string $units,
        public readonly int $amountCents,
        public readonly ?int $pricingUnitAmountCents = null,
        public readonly array $presentationBreakdowns = [],
    ) {}

    public static function zero(): self
    {
        return new self(units: '0', amountCents: 0);
    }

    public function plus(self $other): self
    {
        return new self(
            units: MoneyMath::add($this->units, $other->units),
            amountCents: $this->amountCents + $other->amountCents,
            pricingUnitAmountCents: ($this->pricingUnitAmountCents ?? $other->pricingUnitAmountCents) === null
                ? null
                : ($this->pricingUnitAmountCents ?? 0) + ($other->pricingUnitAmountCents ?? 0),
            presentationBreakdowns: array_merge($this->presentationBreakdowns, $other->presentationBreakdowns),
        );
    }
}
