<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\PaymentProvider as PaymentProviderModel;
use App\GraphQL\Types\PaymentProviders\AbstractProvider;

/**
 * Field resolvers for the frozen SDL's `GocardlessProvider` type (port of
 * Rails' Types::PaymentProviders::Gocardless — the access token is a
 * sensitive information and is never sent back; only its presence is).
 */
class GocardlessProvider extends AbstractProvider
{
    /** Rails: has_access_token — presence of the secrets-stored token. */
    public function hasAccessToken(PaymentProviderModel $root): bool
    {
        return ($root->accessToken() ?? '') !== '';
    }

    /** Rails: webhook_secret. */
    public function webhookSecret(PaymentProviderModel $root): ?string
    {
        $value = $root->webhookSecret();

        return $value === null || $value === '' ? null : (string) $value;
    }
}
