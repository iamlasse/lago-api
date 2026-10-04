<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\Contract;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V2::ContractSerializer
 * (app/serializers/v2/contract_serializer.rb) — the agreement a customer
 * signed: an optional plan (a plan-less contract prices through directly
 * attached rate cards), a validity window and the billing anchor.
 */
final class ContractSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var Contract $contract */
        $contract = $this->model;

        $payload = [
            'lago_id' => $contract->id,
            'external_id' => $contract->external_id,
            'lago_customer_id' => $contract->customer_id,
            'external_customer_id' => $contract->customer->external_id,
            'name' => $contract->name,
            'plan_code' => $contract->catalogPlan?->code,
            'status' => $contract->getRawOriginal('status'),
            'billing_time' => $contract->getRawOriginal('billing_time'),
            'billing_anchor_date' => $this->serializeDate($contract->billing_anchor_date),
            'started_at' => $this->serializeDatetime($contract->started_at),
            'ended_at' => $this->serializeDatetime($contract->ended_at),
            'terminated_at' => $this->serializeDatetime($contract->terminated_at),
            'canceled_at' => $this->serializeDatetime($contract->canceled_at),
            'created_at' => $this->serializeDatetime($contract->created_at),
            'updated_at' => $this->serializeDatetime($contract->updated_at),
            'applied_rate_cards_count' => $this->appliedRateCardsCount(),
        ];

        if ($this->include('applied_rate_cards')) {
            $payload['applied_rate_cards'] = $this->appliedRateCards();
        }

        return $payload;
    }

    /**
     * Rails: the index passes one grouped count for the whole page; show
     * falls back to a count on the single record.
     */
    private function appliedRateCardsCount(): int
    {
        /** @var Contract $contract */
        $contract = $this->model;

        $counts = $this->options['applied_rate_cards_counts'] ?? null;

        if (is_array($counts)) {
            return (int) ($counts[$contract->id] ?? 0);
        }

        return $contract->appliedRateCards()->count();
    }

    /** Rails: `applied_rate_cards` — @return list<array<string, mixed>> */
    private function appliedRateCards(): array
    {
        /** @var Contract $contract */
        $contract = $this->model;

        $serialized = (new CollectionSerializer(
            $contract->appliedRateCards,
            ContractAppliedRateCardSerializer::class,
            ['collection_name' => 'applied_rate_cards'],
        ))->serialize();

        return $serialized['applied_rate_cards'];
    }
}
