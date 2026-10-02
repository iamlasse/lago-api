<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Serializers\Base\ModelSerializer;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::FixedChargeSerializer
 * (app/serializers/v1/fixed_charge_serializer.rb).
 */
class FixedChargeSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /**
     * Rails: BigDecimal#to_s on a numeric(30,10) value — trailing zeros are
     * trimmed but at least one decimal place is kept ("10.0000000000" →
     * "10.0").
     */
    public static function serializeUnits(mixed $units): string
    {
        $value = (string) $units;

        if (! str_contains($value, '.')) {
            return $value.'.0';
        }

        $value = mb_rtrim($value, '0');

        return str_ends_with($value, '.') ? $value.'0' : $value;
    }

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        $payload = [
            'lago_id' => $this->model->id,
            'lago_add_on_id' => $this->model->add_on_id,
            'code' => $this->model->code,
            'invoice_display_name' => $this->model->invoice_display_name,
            'add_on_code' => $this->model->addOn?->code,
            'created_at' => $this->serializeDatetime($this->model->created_at),
            'charge_model' => $this->model->getRawOriginal('charge_model'),
            'pay_in_advance' => $this->model->pay_in_advance,
            'prorated' => $this->model->prorated,
            'properties' => $this->model->properties,
            'units' => $this->effectiveUnits(),
            'lago_parent_id' => $this->model->parent_id,
        ];

        if ($this->include('taxes')) {
            $payload = [...$payload, ...$this->taxes()];
        }

        return $payload;
    }

    /**
     * Subscription-scoped callers pre-resolve override units into the
     * `effective_units_by_id` option (one query per request, regardless of
     * collection size). Plan-scoped callers (plan endpoints, plan webhooks)
     * don't pass the option and naturally fall back to the plan-level units
     * on the FixedCharge record.
     */
    protected function effectiveUnits(): string
    {
        $units = $this->options['effective_units_by_id'][$this->model->id]
            ?? $this->model->units;

        return self::serializeUnits($units);
    }

    /** @return array<string, mixed> */
    protected function taxes(): array
    {
        return (new CollectionSerializer(
            $this->model->taxes,
            TaxSerializer::class,
            ['collection_name' => 'taxes'],
        ))->serialize();
    }
}
