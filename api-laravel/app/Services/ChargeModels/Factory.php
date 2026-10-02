<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

use App\Enums\ChargeModel;
use App\Support\MoneyMath;
use NotImplementedException;

/**
 * Port of Rails' ChargeModels::Factory
 * (app/services/charge_models/factory.rb).
 *
 * TODO(port): ChargeModels::GroupedService (per pricing_group_keys with
 * grouped `aggregations`) — the grouped aggregation itself arrives with the
 * M2 event store; when AggregationResult::$aggregations is set, the factory
 * applies the underlying model per group entry.
 */
final class Factory
{
    public static function newInstance(
        PricingStructure $pricingStructure,
        AggregationResult $aggregationResult,
        string|float|null $periodRatio = 1.0,
        bool $calculateProjectedUsage = false,
    ): AbstractChargeModel|GroupedChargeModel {
        $hasAggregations = $aggregationResult->aggregations !== null;

        if ($hasAggregations) {
            return new GroupedChargeModel(
                chargeModelClass: self::chargeModelClass($pricingStructure),
                pricingStructure: $pricingStructure,
                aggregationResult: $aggregationResult,
                periodRatio: $periodRatio,
                calculateProjectedUsage: $calculateProjectedUsage,
            );
        }

        return new (self::chargeModelClass($pricingStructure))(
            pricingStructure: $pricingStructure,
            aggregationResult: $aggregationResult,
            periodRatio: $periodRatio,
            calculateProjectedUsage: $calculateProjectedUsage,
        );
    }

    /**
     * The `has_aggregator` fallback: Rails routes prorated graduated charges
     * to ProratedGraduatedService only when per-event aggregation data
     * exists; without it (M1: no event store) they fall back to the
     * non-prorated GraduatedService.
     *
     * @return class-string<AbstractChargeModel>
     */
    public static function chargeModelClass(PricingStructure $pricingStructure): string
    {
        return match ($pricingStructure->chargeModel) {
            ChargeModel::Standard => StandardService::class,
            ChargeModel::Graduated => GraduatedService::class, // TODO(port): ProratedGraduatedService with per-event aggregation (M2).
            ChargeModel::GraduatedPercentage => GraduatedPercentageService::class,
            ChargeModel::Package => PackageService::class,
            ChargeModel::Percentage => PercentageService::class,
            ChargeModel::Volume => VolumeService::class,
            ChargeModel::Custom => CustomService::class,
            ChargeModel::Dynamic => DynamicService::class,
        };
    }

    /** Port of `in_advance_charge_model_class` (no prorated graduated). */
    public static function inAdvanceChargeModelClass(PricingStructure $pricingStructure): string
    {
        return match ($pricingStructure->chargeModel) {
            ChargeModel::Standard => StandardService::class,
            ChargeModel::Graduated => GraduatedService::class,
            ChargeModel::GraduatedPercentage => GraduatedPercentageService::class,
            ChargeModel::Package => PackageService::class,
            ChargeModel::Percentage => PercentageService::class,
            ChargeModel::Custom => CustomService::class,
            ChargeModel::Dynamic => DynamicService::class,
            default => throw new NotImplementedException(
                "Charge model {$pricingStructure->chargeModel->label()} is not implemented",
            ),
        };
    }
}

/**
 * Port of Rails' ChargeModels::GroupedService — applies the underlying
 * charge model to each per-group aggregation entry and aggregates the
 * amounts / units.
 */
final class GroupedChargeModel extends AbstractChargeModel
{
    public function __construct(
        private readonly string $chargeModelClass,
        PricingStructure $pricingStructure,
        AggregationResult $aggregationResult,
        string|float|null $periodRatio,
        bool $calculateProjectedUsage,
    ) {
        parent::__construct($pricingStructure, $aggregationResult, $periodRatio, $calculateProjectedUsage);
    }

    public function apply(): ChargeModelResult
    {
        $groupedResults = [];

        foreach ($this->aggregationResult->aggregations ?? [] as $aggregation) {
            $groupResult = new ($this->chargeModelClass)(
                pricingStructure: $this->pricingStructure,
                aggregationResult: $aggregation,
                periodRatio: $this->periodRatio,
                calculateProjectedUsage: $this->calculateProjectedUsage,
            )->apply();

            $groupResult->groupedBy = $aggregation->groupedBy;
            $groupedResults[] = $groupResult;
        }

        $this->result->groupedResults = $groupedResults;

        $amount = '0';
        $units = '0';

        foreach ($groupedResults as $groupResult) {
            $amount = MoneyMath::add($amount, (string) $groupResult->amount);
            $units = MoneyMath::add($units, (string) $groupResult->units);
        }

        $this->result->amount = $amount;
        $this->result->units = $units;

        if ($this->calculateProjectedUsage) {
            $projectedAmount = '0';
            $projectedUnits = '0';

            foreach ($groupedResults as $groupResult) {
                $projectedAmount = MoneyMath::add($projectedAmount, (string) $groupResult->projectedAmount);
                $projectedUnits = MoneyMath::add($projectedUnits, (string) $groupResult->projectedUnits);
            }

            $this->result->projectedAmount = $projectedAmount;
            $this->result->projectedUnits = $projectedUnits;
        }

        return $this->result;
    }

    protected function computeAmount(): string
    {
        return '0';
    }

    protected function computeProjectedAmount(): string
    {
        return '0';
    }

    protected function unitAmount(): string
    {
        return '0';
    }
}
