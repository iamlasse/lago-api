<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Support\FeatureFlag;
use App\GraphQL\Support\LagoContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Field resolvers for the frozen SDL's `CurrentOrganization` type (port of
 * Rails' Types::Organizations::CurrentOrganizationType). Computed fields are
 * method resolvers here; plain columns resolve through Lighthouse's default
 * snake_case attribute lookup.
 */
class CurrentOrganization extends Organization
{
    /** Rails: object.api_keys.first.value. */
    public function apiKey(mixed $root): ?string
    {
        return $root->apiKeys()->first()?->value;
    }

    /** Rails: webhook_endpoints.map(&:webhook_url).first. */
    public function webhookUrl(mixed $root): ?string
    {
        return $root->webhookEndpoints()->first()?->webhook_url;
    }

    /** Rails: License.premium? gate — see Types\Organization. */
    public function hmacKey(mixed $root): ?string
    {
        return $root->hmac_key;
    }

    /**
     * Rails: object.feature_flags.select { FeatureFlag.valid?(it) }.
     *
     * @return list<string>
     */
    public function featureFlags(mixed $root): array
    {
        return array_values(array_filter(
            (array) ($root->feature_flags ?? []),
            fn (string $flag): bool => FeatureFlag::valid($flag),
        ));
    }

    /**
     * Rails: email_settings column values ("invoice.finalized") serialized
     * through the enum's underscored wire values ("invoice_finalized").
     *
     * @return list<string>
     */
    public function emailSettings(mixed $root): array
    {
        return array_map(
            static fn (string $setting): string => str_replace('.', '_', $setting),
            array_values((array) ($root->email_settings ?? [])),
        );
    }

    /** Rails: Organization#events_store. */
    public function eventsStore(mixed $root): string
    {
        return $root->eventsStore();
    }

    /** Rails: premium_integrations column (validated against the enum). */
    public function premiumIntegrations(mixed $root): array
    {
        return array_values((array) ($root->premium_integrations ?? []));
    }

    /** Rails: authentication_methods column. */
    public function authenticationMethods(mixed $root): array
    {
        return array_values((array) ($root->authentication_methods ?? []));
    }

    /** Rails: context[:login_method]. */
    public function authenticatedMethod(mixed $root, array $args, GraphQLContext $context): ?string
    {
        return LagoContext::loginMethod($context);
    }
}
