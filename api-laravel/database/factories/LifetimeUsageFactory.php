<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LifetimeUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :lifetime_usage factory.
 *
 * @extends Factory<LifetimeUsage>
 */
class LifetimeUsageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'subscription_id' => SubscriptionFactory::new(),
            'current_usage_amount_cents' => 0,
            'invoiced_usage_amount_cents' => 0,
            'historical_usage_amount_cents' => 0,
            'recalculate_current_usage' => false,
            'recalculate_invoiced_usage' => false,
        ];
    }
}
