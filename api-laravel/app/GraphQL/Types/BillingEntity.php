<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\GraphQL\Support\TimezoneWire;

/**
 * Field resolvers for the frozen SDL's `BillingEntity` type — currently only
 * the timezone wire conversion; plain columns resolve through Lighthouse's
 * default snake_case attribute lookup.
 */
class BillingEntity
{
    /** The timezone column holds the IANA identifier; the wire carries TZ_*. */
    public function timezone(mixed $root): ?string
    {
        return TimezoneWire::toWire($root->timezone);
    }

    /**
     * Rails: `field :email_settings, [Types::BillingEntities::EmailSettingsEnum]`
     * — the column stores the notification names in their dot form
     * ("invoice.finalized"); the frozen SDL's enum values are the underscore
     * form ("invoice_finalized") since GraphQL enum names cannot carry dots.
     *
     * @return list<string>
     */
    public function emailSettings(\App\Models\BillingEntity $root): array
    {
        return array_values(array_map(
            static fn (string $setting): string => str_replace('.', '_', $setting),
            (array) ($root->email_settings ?? []),
        ));
    }

    /** Rails: Types::BillingEntities::Object#applied_dunning_campaign — the relation. */
    public function appliedDunningCampaign(\App\Models\BillingEntity $root): ?\App\Models\DunningCampaign
    {
        return $root->appliedDunningCampaign;
    }

    /**
     * Rails: Types::BillingEntities::Object#is_default — the organization's
     * default (first active) billing entity. No column: computed.
     */
    public function isDefault(\App\Models\BillingEntity $root): bool
    {
        return $root->organization->defaultBillingEntity?->id === $root->id;
    }
}
