<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\RateCard;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V2::RateCardSerializer
 * (app/serializers/v2/rate_card_serializer.rb).
 */
final class RateCardSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var RateCard $rateCard */
        $rateCard = $this->model;

        $payload = [
            'lago_id' => $rateCard->id,
            'product_code' => $rateCard->product->code,
            'product_filter_code' => $rateCard->productFilter?->code,
            'name' => $rateCard->name,
            'code' => $rateCard->code,
            'description' => $rateCard->description,
            'currency' => $rateCard->currency,
            'billing_timing' => $rateCard->getRawOriginal('billing_timing'),
            'proration' => (bool) $rateCard->proration,
            'display_on_invoice' => (bool) $rateCard->display_on_invoice,
            'regroup_paid_fees' => $rateCard->getRawOriginal('regroup_paid_fees'),
            'applied_pricing_unit_code' => $rateCard->applied_pricing_unit_code,
            // Preloaded by the index so the count reads the loaded relation.
            'rates_count' => $rateCard->rates->count(),
            'created_at' => $this->serializeDatetime($rateCard->created_at),
            'updated_at' => $this->serializeDatetime($rateCard->updated_at),
        ];

        if ($this->include('active_rate')) {
            $activeRate = $this->activeRate();
            $payload['active_rate'] = $activeRate;
        }

        if ($this->include('taxes')) {
            $payload += $this->taxes();
        }

        // Full timeline for activity-log payloads; API payloads stay lean.
        if ($this->include('rates')) {
            $payload += $this->rates();
        }

        return $payload;
    }

    /** Rails: `active_rate` — @return array<string, mixed>|null */
    private function activeRate(): ?array
    {
        /** @var RateCard $rateCard */
        $rateCard = $this->model;

        $rate = $rateCard->activeRate();

        return $rate === null ? null : (new RateCardRateSerializer($rate))->serialize();
    }

    /** Rails: `rates` — @return array<string, list<array<string, mixed>>> */
    private function rates(): array
    {
        /** @var RateCard $rateCard */
        $rateCard = $this->model;

        return (new CollectionSerializer(
            $rateCard->rates,
            RateCardRateSerializer::class,
            ['collection_name' => 'rates'],
        ))->serialize();
    }

    /** Rails: `taxes` — @return array<string, list<array<string, mixed>>> */
    private function taxes(): array
    {
        /** @var RateCard $rateCard */
        $rateCard = $this->model;

        return (new CollectionSerializer(
            $rateCard->taxes,
            TaxSerializer::class,
            ['collection_name' => 'taxes'],
        ))->serialize();
    }
}
