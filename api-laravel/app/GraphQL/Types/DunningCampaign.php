<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Customer;
use App\Models\DunningCampaign as DunningCampaignModel;

/**
 * Field resolvers for the frozen SDL's `DunningCampaign` type (port of
 * Rails' Types::DunningCampaigns::Object computed fields — appliedToOrganization
 * reads the default billing entity's applied campaign, customersCount is the
 * explicit + billing-entity-fallback customer count).
 *
 * Plain columns resolve through the snake_case attribute fallback.
 */
class DunningCampaign
{
    /**
     * Rails: applied_to_organization — the default billing entity carries
     * the campaign (the legacy organization-wide application).
     */
    public function appliedToOrganization(DunningCampaignModel $root): bool
    {
        return $root->organization?->defaultBillingEntity?->applied_dunning_campaign_id === $root->id;
    }

    /**
     * Rails: customers_count — customers excluded from dunning never count;
     * the rest either applied the campaign explicitly or fall back to it
     * through one of its billing entities.
     */
    public function customersCount(DunningCampaignModel $root): int
    {
        return Customer::query()
            ->where('exclude_from_dunning_campaign', false)
            ->where(function ($query) use ($root): void {
                $query->where('applied_dunning_campaign_id', $root->id)
                    ->orWhere(function ($fallback) use ($root): void {
                        $fallback->whereNull('applied_dunning_campaign_id')
                            ->whereIn('billing_entity_id', $root->billingEntities()->select('id'));
                    });
            })
            ->count();
    }

    /** Rails: thresholds — ordered by the join (no explicit order in Rails). */
    public function thresholds(DunningCampaignModel $root): \Illuminate\Support\Collection
    {
        return $root->thresholds;
    }
}
