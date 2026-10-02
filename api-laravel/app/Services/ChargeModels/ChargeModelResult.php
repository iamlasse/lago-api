<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

/**
 * Port of Rails' ChargeModels::BaseService::Result fields — the outcome of
 * applying a charge model to an aggregation result. Amounts are decimal
 * strings (bcmath discipline); `amount` is later rounded to currency
 * exponent by Fees\ChargeService.
 */
final class ChargeModelResult
{
    public ?string $units = null;

    public ?string $currentUsageUnits = null;

    public ?string $fullUnitsNumber = null;

    public ?int $count = null;

    public string|int $amount = '0';

    public string|int $unitAmount = '0';

    /** @var array<string, mixed> */
    public array $amountDetails = [];

    public ?string $totalAggregatedUnits = null;

    /** @var array<string, mixed> */
    public array $groupedBy = [];

    /** @var list<self> */
    public array $groupedResults = [];

    public ?string $projectedAmount = null;

    public ?string $projectedUnits = null;

    /** Writable amount/unit_amount slots (Rails assigns amount_result.amount in ChargeService). */
    public function setAmount(string|int $amount): void
    {
        $this->amount = $amount;
    }

    public function setUnitAmount(string|int $unitAmount): void
    {
        $this->unitAmount = $unitAmount;
    }

    public function setUnits(string $units): void
    {
        $this->units = $units;
    }

    public function setFullUnitsNumber(?string $fullUnitsNumber): void
    {
        $this->fullUnitsNumber = $fullUnitsNumber;
    }
}
