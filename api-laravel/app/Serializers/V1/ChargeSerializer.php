<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Enums\ChargeModel;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::ChargeSerializer
 * (app/serializers/v1/charge_serializer.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): applied_pricing_unit (AppliedPricingUnits models) — always
 *   null today.
 */
class ChargeSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        $payload = [
            'lago_id' => $this->model->id,
            'lago_billable_metric_id' => $this->model->billable_metric_id,
            'code' => $this->model->code,
            'invoice_display_name' => $this->model->invoice_display_name,
            'billable_metric_code' => $this->model->billableMetric?->code,
            'created_at' => $this->serializeDatetime($this->model->created_at),
            'charge_model' => $this->serializeChargeModel(),
            'invoiceable' => $this->model->invoiceable,
            'regroup_paid_fees' => $this->serializeRegroupPaidFees(),
            'pay_in_advance' => $this->model->pay_in_advance,
            'prorated' => $this->model->prorated,
            'min_amount_cents' => $this->model->min_amount_cents,
            'accepts_target_wallet' => $this->model->accepts_target_wallet,
            'properties' => $this->properties(),
            'applied_pricing_unit' => null,
            'lago_parent_id' => $this->model->parent_id,
        ];

        $payload = [...$payload, ...$this->chargeFilters()];

        if ($this->include('taxes')) {
            $payload = [...$payload, ...$this->taxes()];
        }

        return $payload;
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

    /** @return array<string, mixed> */
    protected function chargeFilters(): array
    {
        $filters = $this->model->filters()->with(['charge', 'values.billableMetricFilter'])->get();

        return (new CollectionSerializer(
            $filters,
            ChargeFilterSerializer::class,
            ['collection_name' => 'filters'],
        ))->serialize();
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

    private function serializeChargeModel(): string
    {
        return ChargeModel::from((int) $this->model->charge_model)->label();
    }

    /** Rails: the regroup_paid_fees enum name (nil when unset). */
    private function serializeRegroupPaidFees(): ?string
    {
        $raw = $this->model->regroup_paid_fees;

        if ($raw === null) {
            return null;
        }

        // Rails: REGROUPING_PAID_FEES_OPTIONS = %i[invoice] (invoice = 0).
        return $raw === 0 ? 'invoice' : (string) $raw;
    }
}
