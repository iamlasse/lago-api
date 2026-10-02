<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

/**
 * Port of Rails' ChargeModels::BuildDefaultPropertiesService
 * (app/services/charge_models/build_default_properties_service.rb).
 */
class BuildDefaultPropertiesService extends \App\Services\BaseService
{
    public function __construct(
        private readonly string|int|null $chargeModel,
    ) {
        parent::__construct();
    }

    public function execute(): \App\Services\BaseResult
    {
        // Rails returns the hash directly; we surface it as result->properties.
        $result = static::makeResult('properties');
        $result->properties = $this->defaultProperties();

        return $result;
    }

    /** @return array<string, mixed> */
    public function defaultProperties(): array
    {
        $chargeModel = is_string($this->chargeModel)
            ? $this->chargeModel
            : ($this->chargeModel !== null ? \App\Enums\ChargeModel::from($this->chargeModel)->label() : null);

        return match ($chargeModel) {
            'standard' => ['amount' => '0'],
            'graduated' => [
                'graduated_ranges' => [
                    [
                        'from_value' => 0,
                        'to_value' => null,
                        'per_unit_amount' => '0',
                        'flat_amount' => '0',
                    ],
                ],
            ],
            'package' => [
                'package_size' => 1,
                'amount' => '0',
                'free_units' => 0,
            ],
            'percentage' => ['rate' => '0'],
            'volume' => [
                'volume_ranges' => [
                    [
                        'from_value' => 0,
                        'to_value' => null,
                        'per_unit_amount' => '0',
                        'flat_amount' => '0',
                    ],
                ],
            ],
            'graduated_percentage' => [
                'graduated_percentage_ranges' => [
                    [
                        'from_value' => 0,
                        'to_value' => null,
                        'rate' => '0',
                        'fixed_amount' => '0',
                        'flat_amount' => '0',
                    ],
                ],
            ],
            'dynamic' => [],
            default => [],
        };
    }
}
