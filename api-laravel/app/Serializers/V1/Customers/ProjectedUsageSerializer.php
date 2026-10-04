<?php

declare(strict_types=1);

namespace App\Serializers\V1\Customers;

use App\Support\SubscriptionUsage;
use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::Customers::ProjectedUsageSerializer
 * (app/serializers/v1/customers/projected_usage_serializer.rb) — the
 * customers projected_usage payload.
 */
class ProjectedUsageSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var SubscriptionUsage $usage */
        $usage = $this->model;

        return [
            'from_datetime' => $usage->fromDatetime,
            'to_datetime' => $usage->toDatetime,
            'issuing_date' => $usage->issuingDate,
            'currency' => $usage->currency,
            'amount_cents' => $usage->amountCents,
            'projected_amount_cents' => $usage->projections?->forIterable($usage->fees)->amountCents ?? 0,
            'total_amount_cents' => $usage->totalAmountCents,
            'taxes_amount_cents' => $usage->taxesAmountCents,
            'lago_invoice_id' => null,
            'charges_usage' => [
                'charges_usage' => (new ProjectedChargeUsageSerializer($usage->fees, usage: $usage))->serialize(),
            ],
        ];
    }
}
