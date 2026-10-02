<?php

declare(strict_types=1);

use App\Models\Charge;
use App\Models\BillableMetric;
use Database\Factories\ChargeFactory;
use Database\Factories\BillableMetricFactory;

if (! function_exists('chargeForValidation')) {
    /**
     * An unsaved charge of the given factory state carrying the given
     * properties — the Rails specs use `build(:standard_charge, properties:)`.
     */
    function chargeForValidation(array $properties, string $state = 'standard'): Charge
    {
        $charge = ChargeFactory::new()->{$state}()->make();
        $charge->properties = $properties;

        return $charge;
    }
}

if (! function_exists('chargeWithMetricForValidation')) {
    function chargeWithMetricForValidation(array $properties, BillableMetric $metric): Charge
    {
        $charge = chargeForValidation($properties);
        $charge->setRelation('billableMetric', $metric);

        return $charge;
    }
}

if (! function_exists('metricForValidation')) {
    function metricForValidation(int $aggregationType = BillableMetricFactory::SUM_AGG): BillableMetric
    {
        return BillableMetricFactory::new()->make(['aggregation_type' => $aggregationType]);
    }
}

if (! function_exists('expectPropertyError')) {
    function expectPropertyError(object $validator, string $field, string $errorCode): void
    {
        expect($validator->valid())->toBeFalse()
            ->and($validator->messages()[$field] ?? null)->toBeArray()
            ->and(in_array($errorCode, $validator->messages()[$field] ?? [], true))->toBeTrue();
    }
}
