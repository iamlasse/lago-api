<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Utils\Datetime;

/**
 * Port of Rails' BillingPeriodBoundaries (app/models/billing_period_boundaries.rb).
 *
 * Immutable value object carrying a billing period's boundaries; `to_array()`
 * is the port of `#to_h` (fee `properties` keys).
 */
class BillingPeriodBoundaries
{
    public function __construct(
        public readonly mixed $fromDatetime,
        public readonly mixed $toDatetime,
        public readonly mixed $chargesFromDatetime,
        public readonly mixed $chargesToDatetime,
        public readonly mixed $chargesDuration,
        public readonly mixed $timestamp,
        public readonly mixed $fixedChargesFromDatetime = null,
        public readonly mixed $fixedChargesToDatetime = null,
        public readonly mixed $fixedChargesDuration = null,
        public readonly mixed $issuingDate = null,
        public mixed $maxTimestamp = null,
        public mixed $chargesToDatetimeOverride = null,
    ) {}

    /** Rails: `BillingPeriodBoundaries.from_fee(fee)` — reads fee.properties. */
    public static function fromFee(?Fee $fee): self
    {
        $props = $fee->properties ?? [];

        return new self(
            fromDatetime: $props['from_datetime'] ?? null,
            toDatetime: $props['to_datetime'] ?? null,
            chargesFromDatetime: $props['charges_from_datetime'] ?? null,
            chargesToDatetime: $props['charges_to_datetime'] ?? null,
            chargesDuration: $props['charges_duration'] ?? null,
            timestamp: $props['timestamp'] ?? null,
            fixedChargesFromDatetime: $props['fixed_charges_from_datetime'] ?? null,
            fixedChargesToDatetime: $props['fixed_charges_to_datetime'] ?? null,
            fixedChargesDuration: $props['fixed_charges_duration'] ?? null,
            issuingDate: $props['issuing_date'] ?? null,
        );
    }

    /** Rails: accessor `charges_to_datetime` is writable — kept via override slot. */
    public function chargesToDatetimeValue(): mixed
    {
        return $this->chargesToDatetimeOverride ?? $this->chargesToDatetime;
    }

    public function setChargesToDatetime(mixed $value): void
    {
        $this->chargesToDatetimeOverride = $value;
    }

    /** Rails: `#to_h` — string-keyed fee properties payload. */
    public function toArray(): array
    {
        $h = [
            'from_datetime' => Datetime::serialize($this->fromDatetime),
            'to_datetime' => Datetime::serialize($this->toDatetime),
            'charges_from_datetime' => Datetime::serialize($this->chargesFromDatetime),
            'charges_to_datetime' => Datetime::serialize($this->chargesToDatetimeValue()),
            'charges_duration' => $this->chargesDuration,
            'timestamp' => Datetime::serialize($this->timestamp),
            'fixed_charges_from_datetime' => Datetime::serialize($this->fixedChargesFromDatetime),
            'fixed_charges_to_datetime' => Datetime::serialize($this->fixedChargesToDatetime),
            'fixed_charges_duration' => $this->fixedChargesDuration,
        ];

        if ($this->issuingDate !== null) {
            $h['issuing_date'] = Datetime::serialize($this->issuingDate);
        }

        if ($this->maxTimestamp !== null) {
            $h['max_timestamp'] = Datetime::serialize($this->maxTimestamp);
        }

        return $h;
    }
}
