<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\Customer;
use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::DunningCampaignFinishedSerializer
 * (app/serializers/v1/dunning_campaign_finished_serializer.rb) — the
 * "dunning_campaign.finished" webhook payload (sent when every dunned
 * currency of a customer reached max_attempts).
 */
class DunningCampaignFinishedSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var Customer $customer */
        $customer = $this->model;
        $overdueBalances = $customer->overdueBalances();

        return [
            'external_customer_id' => $customer->external_id,
            'dunning_campaign_code' => $this->options['dunning_campaign_code'] ?? null,
            'overdue_balance_cents' => $customer->overdueBalanceCents($customer->currency),
            'overdue_balance_currency' => $customer->currency,
            'overdue_balances' => array_map(
                fn (string $currency, int $amountCents): array => [
                    'currency' => $currency,
                    'amount_cents' => $amountCents,
                ],
                array_keys($overdueBalances),
                array_values($overdueBalances),
            ),

            // DEPRECATED (kept verbatim, like Rails).
            'customer_external_id' => $customer->external_id,
        ];
    }
}
