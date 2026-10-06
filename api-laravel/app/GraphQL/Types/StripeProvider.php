<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\PaymentProvider as PaymentProviderModel;
use App\GraphQL\Types\PaymentProviders\AbstractProvider;

/**
 * Field resolvers for the frozen SDL's `StripeProvider` type (port of Rails'
 * Types::PaymentProviders::Stripe — the settings/secrets accessors).
 */
class StripeProvider extends AbstractProvider
{
    /** Rails: require_terms_of_service_consent. */
    public function requireTermsOfServiceConsent(PaymentProviderModel $root): bool
    {
        return $root->requireTermsOfServiceConsent();
    }

    /** Rails: secret_key, ObfuscatedString (secrets accessor). */
    public function secretKey(PaymentProviderModel $root): ?string
    {
        $value = $root->secretKey();

        return $value === null ? null : (string) $value;
    }

    /** Rails: supports_3ds. */
    public function supports3ds(PaymentProviderModel $root): ?bool
    {
        $value = $root->supports3ds();

        return $value === null ? null : (bool) $value;
    }
}
