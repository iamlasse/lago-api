<?php

declare(strict_types=1);

namespace App\Services\ChargeModels\FilterProperties;

use App\Models\Charge;
use App\Models\FixedCharge;
use App\Models\ChargeFilter;

/**
 * Port of Rails' ChargeModels::FilterProperties::BaseService
 * (app/services/charge_models/filter_properties/base_service.rb) — slices the
 * incoming properties hash down to the attributes the charge model accepts.
 */
abstract class BaseService extends \App\Services\BaseService
{
    public function __construct(
        protected Charge|FixedCharge|ChargeFilter $chargeable,
        protected mixed $properties = null,
    ) {
        parent::__construct();

        $this->properties = is_array($this->properties) ? $this->properties : [];
    }

    public function execute(): \App\Services\BaseResult
    {
        $result = static::makeResult('properties');
        $result->properties = $this->sliceProperties() ?? [];

        $customProperties = $result->properties['custom_properties'] ?? null;

        if ($customProperties !== null && $customProperties !== '' && is_string($customProperties)) {
            $decoded = json_decode($customProperties, true);
            $result->properties['custom_properties'] = is_array($decoded) ? $decoded : [];
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    protected function sliceProperties(): ?array
    {
        $attributes = array_merge($this->baseAttributes(), $this->chargeModelAttributes());
        $slicedAttributes = array_intersect_key($this->properties, array_flip($attributes));

        // TODO(pricing_group_keys): deprecate grouped_by attribute.
        $groupedBy = $slicedAttributes['grouped_by'] ?? null;
        $pricingGroupKeys = $slicedAttributes['pricing_group_keys'] ?? null;

        if (($groupedBy !== null && $groupedBy !== []) && ($pricingGroupKeys === null || $pricingGroupKeys === [])) {
            $pricingGroupKeys = $groupedBy;
        }

        if ($pricingGroupKeys !== null && $pricingGroupKeys !== [] && is_array($pricingGroupKeys)) {
            $slicedAttributes['pricing_group_keys'] = array_values(array_filter(
                $pricingGroupKeys,
                fn ($key) => $key !== null && $key !== '',
            ));
        }

        unset($slicedAttributes['grouped_by']);

        return $slicedAttributes;
    }

    /**
     * @return list<string>
     */
    protected function baseAttributes(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    protected function chargeModelAttributes(): array
    {
        $chargeModel = $this->chargeModel();

        $attributes = match ($chargeModel) {
            'standard' => ['amount'],
            'graduated' => ['graduated_ranges'],
            'volume' => ['volume_ranges'],
            default => [],
        };

        if ($chargeModel !== null) {
            if (($this->properties['grouped_by'] ?? null) !== null
                && ($this->properties['grouped_by'] ?? []) !== []
                && ($this->properties['pricing_group_keys'] ?? null) === null) {
                $attributes[] = 'grouped_by';
            }

            if (($this->properties['pricing_group_keys'] ?? null) !== null) {
                $attributes[] = 'pricing_group_keys';
            }

            if (($this->properties['presentation_group_keys'] ?? null) !== null) {
                $attributes[] = 'presentation_group_keys';
            }
        }

        return $attributes;
    }

    protected function chargeModel(): ?string
    {
        $raw = $this->chargeable->charge_model;

        if ($raw === null) {
            return null;
        }

        // charges stores the integer enum position; fixed_charges stores the
        // native enum string directly.
        return is_int($raw) || ctype_digit((string) $raw)
            ? \App\Enums\ChargeModel::from((int) $raw)->label()
            : $raw;
    }
}
