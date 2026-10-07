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
final readonly class SubscriptionUsage
{
    public function __construct(
        public string $fromDatetime,
        public string $toDatetime,
        public string $issuingDate,
        public string $currency,
        public int $amountCents,
        public int $totalAmountCents,
        public int $taxesAmountCents,
        public array $fees,
        public ?UsageProjections $projections = null,
    ) {}
}
