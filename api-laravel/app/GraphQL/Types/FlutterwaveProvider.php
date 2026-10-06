<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\PaymentProvider as PaymentProviderModel;
use App\GraphQL\Types\PaymentProviders\AbstractProvider;

/**
 * Field resolvers for the frozen SDL's `FlutterwaveProvider` type (port of
 * Rails' Types::PaymentProviders::Flutterwave — the secrets accessors).
 */
class FlutterwaveProvider extends AbstractProvider
{
    /** Rails: secret_key, ObfuscatedString (secrets accessor). */
    public function secretKey(PaymentProviderModel $root): ?string
    {
        $value = $root->secretKey();

        return $value === null ? null : (string) $value;
    }

    /** Rails: webhook_secret — lives in secrets for flutterwave. */
    public function webhookSecret(PaymentProviderModel $root): ?string
    {
        $value = $root->flutterwaveWebhookSecret();

        return $value === null || $value === '' ? null : (string) $value;
    }
}
