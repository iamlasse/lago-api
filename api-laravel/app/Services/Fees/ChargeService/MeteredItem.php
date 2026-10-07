<?php

declare(strict_types=1);

namespace App\Services\Fees\ChargeService;

use App\Models\Charge;
use App\Support\Currency;
use InvalidArgumentException;
use App\Models\BillableMetric;
use App\Models\BillingPeriodBoundaries;
use App\Services\ChargeModels\PricingStructure;

/**
 * Port of Rails' Fees::ChargeService::MeteredItem +
 * Fees::ChargeService::Sources::Charge
 * (app/services/fees/charge_service/metered_item.rb, sources/charge.rb).
 *
 * TODO(port): charge_filter / properties_override branches (charge filters
 * are billed with the filters pipeline in M2); pricing_group_keys and
 * presentation_group_keys_values (grouped aggregation, M2).
 */
final class MeteredItem
{
    public function __construct(
        public readonly Charge $charge,
        public readonly BillingPeriodBoundaries $boundaries,
    ) {}

    public static function fromCharge(Charge $charge, BillingPeriodBoundaries $boundaries): self
    {
        if ($boundaries->chargesFromDatetime === null || $boundaries->chargesToDatetime === null) {
            throw new InvalidArgumentException('charge boundaries are mandatory');
        }

        return new self($charge, $boundaries);
    }

    public function chargeId(): string
    {
        return $this->charge->id;
    }

    public function billableMetric(): BillableMetric
    {
        return $this->charge->billableMetric;
    }

    public function organizationId(): string
    {
        return $this->charge->organization_id;
    }

    public function currency(): string
    {
        return (string) $this->charge->plan->amount_currency;
    }

    public function currencyExponent(): int
    {
        return Currency::exponent($this->currency());
    }

    public function subunitToUnit(): int
    {
        return Currency::subunitToUnit($this->currency());
    }

    public function payInAdvance(): bool
    {
        return $this->charge->payInAdvance();
    }

    public function prorated(): bool
    {
        return $this->charge->proratedCharge();
    }

    public function invoiceable(): bool
    {
        return $this->charge->invoiceableCharge();
    }

    public function dynamic(): bool
    {
        return $this->charge->dynamic();
    }

    public function properties(): array
    {
        return $this->charge->properties ?? [];
    }

    public function pricingStructure(): PricingStructure
    {
        return PricingStructure::fromCharge($this->charge);
    }

    /**
     * Port of Sources::Charge#period_ratio — how much of the charge period
     * has already elapsed, used for usage projections.
     */
    public function periodRatio(): float
    {
        $fromDate = \Carbon\CarbonImmutable::parse($this->boundaries->chargesFromDatetime)->startOfDay();
        $toDate = \Carbon\CarbonImmutable::parse($this->boundaries->chargesToDatetime)->startOfDay();
        $currentDate = today();

        $totalDays = (int) $fromDate->diffInDays($toDate) + 1;
        $chargesDuration = $this->boundaries->chargesDuration ?? $totalDays;

        if ($currentDate->gte($toDate)) {
            return 1.0;
        }

        if ($currentDate->lt($fromDate)) {
            return 0.0;
        }

        $daysPassed = (int) $fromDate->diffInDays($currentDate) + 1;

        return max(0.0, min(1.0, $daysPassed / $chargesDuration));
    }

    /**
     * Port of MeteredItem#filtered_for_charge_boundaries — the fee
     * `properties` payload: period boundaries without the fixed-charge keys.
     */
    public function filteredForChargeBoundaries(): array
    {
        $properties = $this->boundaries->toArray();
        $properties['fixed_charges_from_datetime'] = null;
        $properties['fixed_charges_to_datetime'] = null;
        $properties['fixed_charges_duration'] = null;

        return $properties;
    }
}
