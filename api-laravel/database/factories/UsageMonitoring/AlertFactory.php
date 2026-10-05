<?php

declare(strict_types=1);

namespace Database\Factories\UsageMonitoring;

use App\Models\UsageMonitoring\Alert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :usage_monitoring_alert factory (spec/factories, minimal
 * shape — subscription alert, increasing, current_usage_amount).
 *
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => \Database\Factories\OrganizationFactory::new(),
            'alert_type' => 'current_usage_amount',
            'code' => $this->faker->regexify('[a-z0-9]{8}'),
            'name' => $this->faker->words(2, true),
            'direction' => 'increasing',
            'previous_value' => '0.0',
        ];
    }

    /** The subscription_external_id is mandatory (subscription xor wallet). */
    public function forSubscription(string $externalId): static
    {
        return $this->state(fn (array $attributes) => [
            'subscription_external_id' => $externalId,
        ]);
    }

    public function forWalletId(string $walletId): static
    {
        return $this->state(fn (array $attributes) => [
            'wallet_id' => $walletId,
            'subscription_external_id' => null,
            'direction' => 'decreasing',
        ]);
    }
}
