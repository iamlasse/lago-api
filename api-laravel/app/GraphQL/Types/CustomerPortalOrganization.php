<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

/**
 * Field resolvers for the frozen SDL's `CustomerPortalOrganization` type
 * (port of Rails' Types::CustomerPortal::Organizations::Object, which
 * subclasses Types::Organizations::BaseOrganizationType): the trimmed
 * organization exposed through the customer portal. Logo / timezone come
 * from the organization type; default_currency and name resolve as plain
 * columns.
 */
class CustomerPortalOrganization extends Organization
{
    /**
     * Rails: Types::Organizations::BaseOrganizationType#billing_configuration —
     * the derived `{id}-c0nf` view (the synthetic identifier lets the Apollo
     * cache tell the nested objects apart).
     *
     * @return array{id: string, document_locale: ?string, invoice_footer: ?string, invoice_grace_period: int}
     */
    public function billingConfiguration(mixed $root): array
    {
        return [
            'id' => $root->id.'-c0nf',
            'document_locale' => $root->document_locale,
            'invoice_footer' => $root->invoice_footer,
            'invoice_grace_period' => (int) ($root->invoice_grace_period ?? 0),
        ];
    }

    /** Rails: premium_integrations column (validated against the enum). */
    public function premiumIntegrations(mixed $root): array
    {
        return array_values((array) ($root->premium_integrations ?? []));
    }
}
