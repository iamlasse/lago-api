<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\DunningCampaign;
use App\Models\DunningCampaignThreshold;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :dunning_campaign_threshold factory.
 *
 * @extends Factory<DunningCampaignThreshold>
 */
class DunningCampaignThresholdFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dunning_campaign_id' => DunningCampaignFactory::new(),
            'organization_id' => function (array $attributes): string {
                $campaign = DunningCampaign::query()->find($attributes['dunning_campaign_id'] ?? null);

                return $campaign?->organization_id
                    ?? Organization::factory()->create()->id;
            },
            'currency' => 'EUR',
            'amount_cents' => 100,
        ];
    }

    public function forCampaign(DunningCampaign $campaign): static
    {
        return $this->state(fn () => [
            'dunning_campaign_id' => $campaign->id,
            'organization_id' => $campaign->organization_id,
        ]);
    }

    /** A specific currency + amount. */
    public function forCurrency(string $currency, int $amountCents): static
    {
        return $this->state(fn () => [
            'currency' => $currency,
            'amount_cents' => $amountCents,
        ]);
    }
}
