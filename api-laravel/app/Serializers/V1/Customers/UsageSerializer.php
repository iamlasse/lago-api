<?php

declare(strict_types=1);

namespace App\Serializers\V1\Customers;

use App\Support\SubscriptionUsage;
use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::Customers::UsageSerializer
 * (app/serializers/v1/customers/usage_serializer.rb) — the customers
 * current_usage payload.
 */
class UsageSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var SubscriptionUsage $usage */
        $usage = $this->model;

        $payload = [
            'from_datetime' => $usage->fromDatetime,
            'to_datetime' => $usage->toDatetime,
            'issuing_date' => $usage->issuingDate,
            'currency' => $usage->currency,
            'amount_cents' => $usage->amountCents,
            'total_amount_cents' => $usage->totalAmountCents,
            'taxes_amount_cents' => $usage->taxesAmountCents,
            'lago_invoice_id' => null,
        ];

        if ($this->include('charges_usage')) {
            $payload['charges_usage'] = [
                'charges_usage' => (new ChargeUsageSerializer($usage->fees))->serialize(),
            ];
        }

        return $payload;
    }
}
