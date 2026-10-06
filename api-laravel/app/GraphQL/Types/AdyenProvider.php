<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\PaymentProvider as PaymentProviderModel;
use App\GraphQL\Types\PaymentProviders\AbstractProvider;

/**
 * Field resolvers for the frozen SDL's `AdyenProvider` type (port of Rails'
 * Types::PaymentProviders::Adyen — the settings/secrets accessors).
 */
class AdyenProvider extends AbstractProvider
{
    /** Rails: api_key, ObfuscatedString (secrets accessor). */
    public function apiKey(PaymentProviderModel $root): ?string
    {
        $value = $root->apiKey();

        return $value === null ? null : (string) $value;
    }

    /** Rails: hmac_key, ObfuscatedString (secrets accessor). */
    public function hmacKey(PaymentProviderModel $root): ?string
    {
        $value = $root->hmacKey();

        return $value === null ? null : (string) $value;
    }

    /** Rails: live_prefix (settings accessor). */
    public function livePrefix(PaymentProviderModel $root): ?string
    {
        $value = $root->livePrefix();

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** Rails: merchant_account (settings accessor). */
    public function merchantAccount(PaymentProviderModel $root): string
    {
        return (string) $root->merchantAccount();
    }
}
