<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Taxes;

/**
 * Port of Rails' TaxResult::TaxBreakdownItem (a Data.define with a nullable
 * allocated_amount_cents): one jurisdiction's share of a fee's tax.
 */
class TaxBreakdownItem
{
    public function __construct(
        public readonly ?string $name,
        public readonly int|float|string|null $rate,
        public readonly int|float|string|null $taxAmount,
        public readonly ?string $type,
        public readonly ?int $allocatedAmountCents = null,
    ) {}

    /** Rails: Data#define's `with` — a copy carrying the given overrides. */
    public function with(int|float|string|null $taxAmount = null, ?int $allocatedAmountCents = null): self
    {
        return new self(
            $this->name,
            $this->rate,
            $taxAmount ?? $this->taxAmount,
            $this->type,
            $allocatedAmountCents ?? $this->allocatedAmountCents,
        );
    }
}
