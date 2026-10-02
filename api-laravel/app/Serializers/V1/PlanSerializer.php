<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Enums\PlanInterval;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::PlanSerializer
 * (app/serializers/v1/plan_serializer.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): entitlements / usage_thresholds /
 *   applicable_usage_thresholds include the real serializers when the
 *   Entitlement and UsageThreshold models exist (empty collections today).
 * - TODO(port): minimum_commitment include (V1::CommitmentSerializer).
 * - TODO(port): metadata include (Metadata::ItemMetadata).
 */
class PlanSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        $payload = [
            'lago_id' => $this->model->id,
            'name' => $this->model->name,
            'invoice_display_name' => $this->model->invoice_display_name,
            'created_at' => $this->serializeDatetime($this->model->created_at),
            'code' => $this->model->code,
            'interval' => $this->serializeInterval(),
            'description' => $this->model->description,
            'amount_cents' => $this->model->amount_cents,
            'amount_currency' => $this->model->amount_currency,
            'trial_period' => $this->model->trial_period,
            'pay_in_advance' => $this->model->pay_in_advance,
            'bill_charges_monthly' => $this->model->bill_charges_monthly,
            'bill_fixed_charges_monthly' => $this->model->bill_fixed_charges_monthly,
            'customers_count' => 0,
            'active_subscriptions_count' => 0,
            'draft_invoices_count' => 0,
            'parent_id' => $this->model->parent_id,
            'pending_deletion' => $this->model->pending_deletion,
        ];

        if ($this->include('charges')) {
            $payload = [...$payload, ...$this->charges()];
        }

        if ($this->include('fixed_charges')) {
            $payload = [...$payload, ...$this->fixedCharges()];
        }

        if ($this->include('entitlements')) {
            // TODO(port): V1::Entitlement::PlanEntitlementSerializer.
            $payload['entitlements'] = [];
        }

        if ($this->include('usage_thresholds')) {
            // TODO(port): V1::UsageThresholdSerializer.
            $payload['usage_thresholds'] = [];
        }

        if ($this->include('applicable_usage_thresholds')) {
            // TODO(port): V1::ApplicableUsageThresholdSerializer.
            $payload['applicable_usage_thresholds'] = [];
        }

        if ($this->include('taxes')) {
            $payload = [...$payload, ...$this->taxes()];
        }

        if ($this->include('minimum_commitment') && $this->model->minimumCommitment()->first() !== null) {
            // TODO(port): V1::CommitmentSerializer (minus :commitment_type).
        }

        if ($this->model->metadata !== null) {
            // TODO(port): V1::MetadataSerializer (Metadata::ItemMetadata).
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    protected function charges(): array
    {
        return (new CollectionSerializer(
            $this->model->charges()->with(['billableMetric', 'taxes', 'filters.charge', 'filters.values.billableMetricFilter'])->get(),
            ChargeSerializer::class,
            [
                'collection_name' => 'charges',
                'includes' => $this->include('taxes') ? ['taxes'] : [],
            ],
        ))->serialize();
    }

    /** @return array<string, mixed> */
    protected function fixedCharges(): array
    {
        return (new CollectionSerializer(
            $this->model->fixedCharges()->get(),
            FixedChargeSerializer::class,
            [
                'collection_name' => 'fixed_charges',
                'includes' => $this->include('taxes') ? ['taxes'] : [],
            ],
        ))->serialize();
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

    private function serializeInterval(): ?string
    {
        $interval = $this->model->interval;

        if ($interval === null) {
            return null;
        }

        return PlanInterval::from((int) $interval)->label();
    }
}
