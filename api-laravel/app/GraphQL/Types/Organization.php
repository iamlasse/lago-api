<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Support\License;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Support\TimezoneWire;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Field resolvers for the frozen SDL's `Organization` type (the trimmed
 * organization exposed through `User.organizations`). Fields that map 1:1 to
 * columns resolve through Lighthouse's default snake_case attribute lookup.
 */
class Organization
{
    /**
     * Rails: object.authentication_methods.include?(context[:login_method]).
     */
    public function accessibleByCurrentSession(mixed $root, array $args, GraphQLContext $context): bool
    {
        return in_array(LagoContext::loginMethod($context), (array) $root->authentication_methods, true);
    }

    /**
     * Rails: `can_create_billing_entity?` — remaining_billing_entities > 0,
     * with the MULTI_ENTITIES_MAX tiers: 1 (default), 2 (multi_entities_pro
     * premium integration), unbounded (multi_entities_enterprise).
     */
    public function canCreateBillingEntity(mixed $root): bool
    {
        $premiumIntegrations = (array) ($root->premium_integrations ?? []);
        $premium = self::premiumLicense();

        if ($premium && in_array('multi_entities_enterprise', $premiumIntegrations, true)) {
            return true; // MULTI_ENTITIES_MAX[:enterprise] = infinity
        }

        $max = ($premium && in_array('multi_entities_pro', $premiumIntegrations, true)) ? 2 : 1;

        return $root->billingEntities()->count() < $max;
    }

    /**
     * Rails: Types::Organizations::BillingConfiguration — a derived view of
     * the organization's invoice-related columns
     * (`{ id:, document_locale:, invoice_footer:, invoice_grace_period: }`).
     *
     * @return array{id: string, document_locale: ?string, invoice_footer: ?string, invoice_grace_period: int}
     */
    public function billingConfiguration(mixed $root): array
    {
        return [
            'id' => $root->id,
            'document_locale' => $root->document_locale,
            'invoice_footer' => $root->invoice_footer,
            'invoice_grace_period' => (int) ($root->invoice_grace_period ?? 0),
        ];
    }

    /**
     * Rails: Organization#logo_url — an ActiveStorage blob URL. Logo
     * attachments are not ported yet.
     *
     * TODO(port): handle_base64_logo / rails_blob_url.
     */
    public function logoUrl(mixed $root): ?string
    {
        return null;
    }

    /**
     * The timezone column holds the IANA identifier; the wire carries the
     * TimezoneEnum's TZ_* symbol (Rails: Types::TimezoneEnum value mapping).
     */
    public function timezone(mixed $root): ?string
    {
        return TimezoneWire::toWire($root->timezone);
    }

    /** Rails: `License.premium?` (see BaseService#premium). */
    protected static function premiumLicense(): bool
    {
        return License::premium();
    }
}
