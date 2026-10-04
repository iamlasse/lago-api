<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Fee;

/**
 * Port of Rails' SubscriptionUsage Struct
 * (app/models/subscription_usage.rb) — the current-usage payload handed to
 * the usage serializers and to the wallet refresh chain.
 *
 * @param  list<Fee>  $fees
 */
final class SubscriptionUsage
{
    public function __construct(
        public readonly string $fromDatetime,
        public readonly string $toDatetime,
        public readonly string $issuingDate,
        public readonly string $currency,
        public readonly int $amountCents,
        public readonly int $totalAmountCents,
        public readonly int $taxesAmountCents,
        public readonly array $fees,
        public readonly ?UsageProjections $projections = null,
    ) {}
}
