<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::ChargeFilterSerializer
 * (app/serializers/v1/charge_filter_serializer.rb).
 */
class ChargeFilterSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'lago_id' => $this->model->id,
            'charge_code' => $this->model->charge?->code,
            'invoice_display_name' => $this->model->invoice_display_name,
            'properties' => $this->properties(),
            'values' => $this->model->toH(),
        ];
    }

    /**
     * TODO(pricing_group_keys): remove after deprecation of grouped_by —
     * both keys are emitted, mirroring whichever was provided.
     *
     * @return array<string, mixed>
     */
    protected function properties(): mixed
    {
        $attributes = is_array($this->model->properties) ? $this->model->properties : [];

        if (($attributes['grouped_by'] ?? null) !== null && ($attributes['grouped_by'] ?? null) !== []
            && (($attributes['pricing_group_keys'] ?? null) === null || ($attributes['pricing_group_keys'] ?? null) === [])) {
            $attributes['pricing_group_keys'] = $attributes['grouped_by'];
        }

        if (($attributes['pricing_group_keys'] ?? null) !== null && ($attributes['pricing_group_keys'] ?? null) !== []
            && (($attributes['grouped_by'] ?? null) === null || ($attributes['grouped_by'] ?? null) === [])) {
            $attributes['grouped_by'] = $attributes['pricing_group_keys'];
        }

        return $attributes;
    }
}
